<?php

namespace App\Filament\Resources\TournamentResource\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\DetachAction;
use Filament\Actions\ImportAction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use App\Imports\QuestionsImporter;
use App\Models\Question;
use Illuminate\Support\Str;

class QuestionsRelationManager extends RelationManager
{
    protected static string $relationship = 'questions';

    protected static ?string $recordTitleAttribute = 'content';

    public function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('content')
                    ->label('Question')
                    ->wrap()
                    ->limit(100),

                TextColumn::make('grade.name')->label('Grade')->sortable()->searchable(),
                TextColumn::make('subject.name')->label('Subject')->sortable()->searchable(),
                TextColumn::make('topic.name')->label('Topic')->sortable()->searchable(),
                TextColumn::make('level.name')->label('Level')->sortable()->searchable(),
                TextColumn::make('pivot.position')->label('Position')->sortable(),
            ])
            ->headerActions([
                ImportAction::make('importQuestions')
                    ->importer(QuestionsImporter::class)
                    ->icon('heroicon-o-upload'),

                Action::make('addFromBank')
                    ->label('Add from Bank')
                    ->icon('heroicon-o-database')
                    ->modalHeading('Add from Question Bank')
                    ->modalWidth('xl')
                    ->form([
                        // Server-side searchable multi-select. Results are filtered to the tournament's grade/subject/level.
                        Select::make('question_ids')
                            ->label('Questions')
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (?string $search) => $this->getQuestionSearchResults($search)),
                    ])
                    ->action('attachFromBank'),
                Action::make('browseBank')
                    ->label('Browse Bank (table)')
                    ->icon('heroicon-o-collection')
                    ->modalHeading('Browse Question Bank')
                    ->modalWidth('7xl')
                    ->modalContent(fn () => view('filament.modals.bank-questions-table', ['tournamentId' => $this->getOwnerRecord()?->id ?? null]))
                    ->color('secondary'),
            ])
            ->actions([
                DetachAction::make(),
            ])
            ->bulkActions([
                DetachAction::make(),
            ]);
    }

    public function attachFromBank(array $data): void
    {
        $tournament = $this->getOwnerRecord();

        try {
            $ids = $data['question_ids'] ?? [];
            if (!is_array($ids)) {
                $ids = [$ids];
            }

            if (count($ids) === 0) {
                $this->notify('warning', 'No questions selected');
                return;
            }

            // Attach without detaching existing associations
            $tournament->questions()->syncWithoutDetaching($ids);

            $this->notify('success', sprintf('Added %d question(s) from bank', count($ids)));
            $this->refresh();
        } catch (\Exception $e) {
            $this->notify('danger', 'Failed to add questions from bank: ' . $e->getMessage());
        }
    }

    protected function getQuestionSearchResults(?string $search): array
    {
        $tournament = $this->getOwnerRecord();

        $query = Question::query()->where('is_banked', true);

        // Apply tournament filters if available
        if ($tournament) {
            if (!empty($tournament->grade_id)) {
                $query->where('grade_id', $tournament->grade_id);
            }
            if (!empty($tournament->subject_id)) {
                $query->where('subject_id', $tournament->subject_id);
            }
            if (!empty($tournament->level_id)) {
                $query->where('level_id', $tournament->level_id);
            }
        }

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('content', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'desc')
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Question $q) => [$q->id => Str::limit(strip_tags($q->content ?: $q->body), 120)])
            ->toArray();
    }
}

