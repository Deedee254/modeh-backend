<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use App\Models\Question;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\CreateRecord;

class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['status'] = 'upcoming';
        $data['created_by'] = auth()->id();

        return $data;
    }

    protected function beforeCreate(): void
    {
        // Validate dates
        $startDate = \Carbon\Carbon::parse($this->data['start_date']);
        $endDate = \Carbon\Carbon::parse($this->data['end_date']);

        if ($startDate->isBefore(now())) {
            $this->addError('start_date', 'Start date must be in the future.');
            $this->halt();
        }

        if ($endDate->isBefore($startDate)) {
            $this->addError('end_date', 'End date must be after start date.');
            $this->halt();
        }
    }

    protected function afterCreate(): void
    {
        // Get imported questions from form data
        $importedQuestionsData = $this->data['import_questions'] ?? [];
        
        // Decode if it's a JSON string
        if (is_string($importedQuestionsData)) {
            $importedQuestionsData = json_decode($importedQuestionsData, true) ?? [];
        }
        
        if (!empty($importedQuestionsData)) {
            // Create questions and attach to tournament
            $position = 1;
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