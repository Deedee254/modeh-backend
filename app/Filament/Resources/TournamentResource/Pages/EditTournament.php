<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use App\Models\Question;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make(),
            Action::make('generate_matches')
                ->action(fn () => $this->record->generateMatches())
                ->requiresConfirmation()
                ->visible(fn () => $this->record->status === 'upcoming')
                ->color('success')
                ->icon('heroicon-o-play'),
            Action::make('view_leaderboard')
                ->url(fn () => route('admin.tournaments.leaderboard', $this->record))
                ->openUrlInNewTab()
                ->visible(fn () => in_array($this->record->status, ['active', 'completed']))
                ->color('secondary')
                ->icon('heroicon-o-chart-bar'),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function beforeSave(): void
    {
        // Validate dates if they were changed
        if ($this->record->isDirty(['start_date', 'end_date'])) {
            $startDate = \Carbon\Carbon::parse($this->data['start_date']);
            $endDate = \Carbon\Carbon::parse($this->data['end_date']);

            if ($startDate->isBefore(now()) && $this->record->status === 'upcoming') {
                $this->addError('start_date', 'Start date must be in the future for upcoming tournaments.');
                $this->halt();
            }

            if ($endDate->isBefore($startDate)) {
                $this->addError('end_date', 'End date must be after start date.');
                $this->halt();
            }
        }

        // Validate questions - check if key exists and has at least 5 questions
        $questions = $this->data['questions'] ?? [];
        $questionCount = is_array($questions) ? count($questions) : 0;
        
        // Also count existing questions if none were added in form
        if ($questionCount === 0) {
            $questionCount = $this->record->questions()->count();
        }
        
        if ($questionCount < 5) {
            $this->addError('questions', 'Tournament must have at least 5 questions.');
            $this->halt();
        }

        // Prevent editing of active or completed tournaments
        if ($this->record->status !== 'upcoming') {
            $this->addError('*', 'Cannot edit active or completed tournaments.');
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        // Handle imported questions during edit
        $importedQuestionsData = $this->data['import_questions'] ?? [];
        
        // Decode if it's a JSON string
        if (is_string($importedQuestionsData)) {
            $importedQuestionsData = json_decode($importedQuestionsData, true) ?? [];
        }
        
        if (!empty($importedQuestionsData)) {
            // Get current max position
            $maxPosition = $this->record->questions()->max('position') ?? 0;
            $position = $maxPosition + 1;
            
            foreach ($importedQuestionsData as $qData) {
                // Create question
                $question = Question::create([
                    'type' => $qData['type'] ?? 'mcq',
                    'body' => $qData['body'] ?? '',
                    'options' => $qData['options'] ?? [],
                    'correct' => $qData['correct'] ?? null,
                    'marks' => $qData['marks'] ?? 1,
                    'difficulty' => $qData['difficulty'] ?? 2,
                    'is_banked' => $qData['is_banked'] ?? true,
                    'is_approved' => $qData['is_approved'] ?? true,
                    'level_id' => $qData['level_id'] ?? null,
                    'grade_id' => $qData['grade_id'] ?? null,
                    'subject_id' => $qData['subject_id'] ?? null,
                    'topic_id' => $qData['topic_id'] ?? null,
                ]);
                
                // Attach to tournament
                $this->record->questions()->attach($question->id, ['position' => $position]);
                $position++;
            }
        }
    }
}