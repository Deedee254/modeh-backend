<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use App\Models\Question;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getActions(): array
    {
        return [
            Action::make('browseBank')
                ->label('Add Questions from Bank')
                ->icon('heroicon-o-archive-box')
                ->modalHeading('Browse Question Bank')
                ->modalWidth('7xl')
                ->modalContent(fn () => view('filament.modals.bank-questions-table', [
                    'filters' => [
                        'topic_id' => $this->record->topic_id,
                    ],
                    'tournamentId' => $this->record->id,
                ]))
                ->modalSubmitAction(false)
                ->modalCancelAction(false)
                ->color('secondary'),

            Action::make('importCSV')
                ->label('Import Questions CSV')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('secondary')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('csv_import')
                        ->label('Upload CSV')
                        ->acceptedFileTypes(['text/csv', 'text/plain'])
                        ->maxSize(10240)
                        ->directory('temp/csv-imports')
                        ->visibility('private')
                        ->required(),
                ])
                ->modalWidth('md')
                ->action(function ($data) {
                    if (empty($data['csv_import'])) {
                        Notification::make()
                            ->warning()
                            ->title('No file selected')
                            ->send();
                        return;
                    }
                    
                    $filePath = storage_path('app/private/' . $data['csv_import']);
                    if (!file_exists($filePath)) {
                        Notification::make()
                            ->danger()
                            ->title('File not found')
                            ->send();
                        return;
                    }

                    try {
                        // Parse CSV with encoding handling
                        $rows = [];
                        if (($handle = fopen($filePath, "r")) !== FALSE) {
                            // Detect BOM
                            $bom = fread($handle, 3);
                            if ($bom !== "\xEF\xBB\xBF") {
                                rewind($handle);
                            }
                            
                            while (($data = fgetcsv($handle)) !== FALSE) {
                                $rows[] = array_map(function($value) {
                                    return mb_convert_encoding($value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
                                }, $data);
                            }
                            fclose($handle);
                        }
                        $headers = array_shift($rows);
                        
                        $importedQuestions = [];
                        $successCount = 0;
                        $errorCount = 0;

                        foreach ($rows as $rowIndex => $row) {
                            if (empty(array_filter($row))) continue;
                            
                            $question = array_combine($headers, $row);
                            
                            try {
                                // Parse options
                                $opts = [];
                                for ($i = 1; $i <= 4; $i++) {
                                    $optKey = "option{$i}";
                                    if (!empty($question[$optKey] ?? '')) {
                                        $opts[] = ['text' => trim($question[$optKey])];
                                    }
                                }

                                // Parse answers (support numeric 1-4 or text matching)
                                $answersRaw = $question['answers'] ?? null;
                                $correctIndex = null;

                                if ($answersRaw !== null && $answersRaw !== '') {
                                    $answersRaw = trim((string)$answersRaw);
                                    
                                    // Try numeric first
                                    if (is_numeric($answersRaw)) {
                                        $pos = intval($answersRaw);
                                        if ($pos >= 1 && $pos <= count($opts)) {
                                            $correctIndex = $pos - 1; // Convert to 0-based
                                        } else {
                                            throw new \Exception("Answer {$pos} out of range (1-" . count($opts) . ")");
                                        }
                                    } else {
                                        // Try text matching (case-insensitive)
                                        $answerTrimmed = trim($answersRaw);
                                        foreach ($opts as $idx => $opt) {
                                            if (strtolower(trim($opt['text'])) === strtolower($answerTrimmed)) {
                                                $correctIndex = $idx;
                                                break;
                                            }
                                        }
                                        if ($correctIndex === null) {
                                            throw new \Exception("Answer text '{$answersRaw}' not found in options");
                                        }
                                    }
                                }

                                $importedQuestions[] = [
                                    'type' => $question['type'] ?? 'mcq',
                                    'body' => $question['text'] ?? '',
                                    'options' => $opts,
                                    'answers' => $correctIndex !== null ? [(string)$correctIndex] : [],
                                    'marks' => floatval($question['marks'] ?? 1),
                                    'difficulty' => intval($question['difficulty'] ?? 2),
                                    'is_banked' => true,
                                    'is_approved' => true,
                                    'level_id' => $this->record->level_id,
                                    'grade_id' => $this->record->grade_id,
                                    'subject_id' => $this->record->subject_id,
                                    'topic_id' => $this->record->topic_id,
                                ];
                                $successCount++;
                            } catch (\Exception $e) {
                                \Log::warning("CSV import error at row " . ($rowIndex + 2), [
                                    'tournament_id' => $this->record->id,
                                    'row_data' => $row,
                                    'error' => $e->getMessage(),
                                ]);
                                $errorCount++;
                            }
                        }

                        if (empty($importedQuestions)) {
                            Notification::make()
                                ->danger()
                                ->title('Import Failed')
                                ->body('No valid questions found in CSV.')
                                ->send();
                            return;
                        }

                        // Get current max position and attach questions
                        $maxPosition = $this->record->questions()->max('position') ?? 0;
                        $position = $maxPosition + 1;

                        foreach ($importedQuestions as $qData) {
                            $question = Question::create([
                                'type' => $qData['type'] ?? 'mcq',
                                'body' => $qData['body'] ?? '',
                                'options' => $qData['options'] ?? [],
                                'answers' => $qData['answers'] ?? [],
                                'marks' => $qData['marks'] ?? 1,
                                'difficulty' => $qData['difficulty'] ?? 2,
                                'is_banked' => $qData['is_banked'] ?? true,
                                'is_approved' => $qData['is_approved'] ?? true,
                                'level_id' => $qData['level_id'] ?? null,
                                'grade_id' => $qData['grade_id'] ?? null,
                                'subject_id' => $qData['subject_id'] ?? null,
                                'topic_id' => $qData['topic_id'] ?? null,
                            ]);
                            
                            $this->record->questions()->attach($question->id, ['position' => $position]);
                            $position++;
                        }

                        \Log::info('CSV import completed via edit page', [
                            'tournament_id' => $this->record->id,
                            'imported_count' => $successCount,
                            'error_count' => $errorCount,
                        ]);

                        // Show success notification
                        $message = "Imported {$successCount} questions successfully";
                        if ($errorCount > 0) {
                            $message .= " ({$errorCount} rows skipped due to errors)";
                        }

                        Notification::make()
                            ->success()
                            ->title('Import Complete')
                            ->body($message)
                            ->send();

                        // Refresh the page
                        $this->refreshFormData(['questions']);
                    } catch (\Exception $e) {
                        \Log::error('CSV import error', [
                            'tournament_id' => $this->record->id,
                            'error' => $e->getMessage(),
                        ]);

                        Notification::make()
                            ->danger()
                            ->title('Import Error')
                            ->body($e->getMessage())
                            ->send();
                    } finally {
                        // Clean up temp file
                        @unlink($filePath);
                    }
                }),

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