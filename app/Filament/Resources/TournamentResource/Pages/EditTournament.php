<?php

namespace App\Filament\Resources\TournamentResource\Pages;

use App\Filament\Resources\TournamentResource;
use App\Models\Question;
use Filament\Actions\DeleteAction;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

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
                    'recommendations' => $this->record->getQuestionRecommendations(),
                    'participantsRecommendation' => $this->record->getMaxParticipantsRecommendation(),
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
                ->before(function () {
                    // Show recommendations before upload
                    $recommendations = $this->record->getQuestionRecommendations();
                    Notification::make()
                        ->info()
                        ->title('Question Recommendations')
                        ->body("{$recommendations['message']}\n\nMinimum: {$recommendations['minimum']} | Optimum: {$recommendations['optimum']} | Current: {$recommendations['current']}")
                        ->persistent()
                        ->send();
                })
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
                                // Parse options as plain strings
                                $opts = [];
                                for ($i = 1; $i <= 4; $i++) {
                                    $optKey = "option{$i}";
                                    if (!empty($question[$optKey] ?? '')) {
                                        $opts[] = trim($question[$optKey]);
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
                                            // $opt is a plain string
                                            if (strtolower(trim((string)$opt)) === strtolower($answerTrimmed)) {
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
                                    // Ensure options are plain strings when saved
                                    'options' => array_values(array_map(fn($o) => is_array($o) && array_key_exists('text', $o) ? trim($o['text']) : (is_object($o) && property_exists($o,'text') ? trim($o->text) : trim((string)$o)), $opts)),
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

            DeleteAction::make()
                ->requiresConfirmation()
                ->modalHeading('Delete Tournament')
                ->modalDescription(fn () => "Are you sure you want to delete '{$this->record->name}'? This action cannot be undone.")
                ->action(function () {
                    $tournamentName = $this->record->name;
                    $tournamentId = $this->record->id;
                    $tournamentStatus = $this->record->status;
                    $userId = auth()->id();

                    Log::warning('Tournament deletion attempted', [
                        'user_id' => $userId,
                        'user_role' => auth()->user()?->role,
                        'tournament_id' => $tournamentId,
                        'tournament_name' => $tournamentName,
                        'tournament_status' => $tournamentStatus,
                    ]);

                    // Check if user is admin (full power)
                    if (auth()->user() && in_array(auth()->user()->role, ['admin', 'super-admin'])) {
                        Log::info('Tournament deleted by admin', [
                            'user_id' => $userId,
                            'user_role' => auth()->user()?->role,
                            'tournament_id' => $tournamentId,
                            'tournament_name' => $tournamentName,
                            'tournament_status' => $tournamentStatus,
                            'timestamp' => now()->toDateTimeString(),
                        ]);

                        $this->record->delete();

                        Notification::make()
                            ->success()
                            ->title('Tournament Deleted')
                            ->body("Tournament '{$tournamentName}' has been permanently deleted.")
                            ->send();

                        return $this->redirect(static::getResource()::getUrl('index'));
                    }

                    // Non-admin users: not allowed to delete
                    $deleteErrorMsg = "You do not have permission to delete tournaments. Only admins can delete tournaments.";

                    Log::warning('Tournament deletion blocked: insufficient permissions', [
                        'user_id' => $userId,
                        'user_role' => auth()->user()?->role,
                        'tournament_id' => $tournamentId,
                        'tournament_name' => $tournamentName,
                    ]);

                    Notification::make()
                        ->danger()
                        ->title('Cannot Delete Tournament')
                        ->body($deleteErrorMsg)
                        ->send();

                    $this->halt();
                }),

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

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Handle sponsor_details JSON structure
        if (isset($data['sponsor_details']) && is_array($data['sponsor_details'])) {
            $data['sponsor_details'] = array_filter($data['sponsor_details'], fn($v) => $v !== null && $v !== '');
            if (empty($data['sponsor_details'])) {
                $data['sponsor_details'] = null;
            }
        }

        // Handle rules - ensure it's JSON encoded if it's an array
        if (isset($data['rules']) && is_array($data['rules'])) {
            $data['rules'] = json_encode($data['rules']);
        }

        return $data;
    }

    protected function beforeSave(): void
    {
        // Check if tournament is editable
        $this->validateTournamentEditable();
        
        // Validate dates if changed
        $this->validateDatesIfChanged();
        
        // Validate question count
        $this->validateQuestionCount();

        Log::debug('Tournament edit - validation started', [
            'user_id' => auth()->id(),
            'tournament_id' => $this->record->id,
            'tournament_name' => $this->record->name,
            'current_status' => $this->record->status,
            'dirty_attributes' => $this->record->getDirty(),
        ]);
    }

    private function validateTournamentEditable(): void
    {
        // Allow admin to edit any tournament at any time (full power)
        if (auth()->user() && in_array(auth()->user()->role, ['admin', 'super-admin'])) {
            Log::info('Admin editing tournament - bypassing status check', [
                'user_id' => auth()->id(),
                'user_role' => auth()->user()?->role,
                'tournament_id' => $this->record->id,
                'tournament_name' => $this->record->name,
                'tournament_status' => $this->record->status,
            ]);
            return;
        }

        // Prevent editing of active or completed tournaments for non-admin users
        if ($this->record->status !== 'upcoming') {
            $statusErrorMsg = "Cannot edit tournaments that are not in 'upcoming' status. This tournament is currently '{$this->record->status}'. Only upcoming tournaments can be edited. Contact an admin if you need to edit an active/completed tournament.";
            
            Log::warning('Tournament edit validation failed: tournament not in editable state', [
                'user_id' => auth()->id(),
                'user_role' => auth()->user()?->role,
                'tournament_id' => $this->record->id,
                'tournament_name' => $this->record->name,
                'status' => $this->record->status,
                'error_message' => $statusErrorMsg,
            ]);

            Notification::make()
                ->danger()
                ->title('Cannot Edit Tournament')
                ->body($statusErrorMsg)
                ->send();

            $this->addError('*', $statusErrorMsg);
            $this->halt();
        }
    }

    private function validateDatesIfChanged(): void
    {
        // Only validate dates if they were changed
        if (!$this->record->isDirty(['start_date', 'end_date'])) {
            return;
        }

        try {
            $startDate = \Carbon\Carbon::parse($this->data['start_date']);
            $endDate = \Carbon\Carbon::parse($this->data['end_date']);
            $now = now();

            Log::debug('Tournament edit - date validation', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'old_start_date' => $this->record->getOriginal('start_date'),
                'new_start_date' => $startDate->toDateTimeString(),
                'old_end_date' => $this->record->getOriginal('end_date'),
                'new_end_date' => $endDate->toDateTimeString(),
            ]);

            if ($startDate->isBefore($now) && $this->record->status === 'upcoming') {
                $errorMsg = "Start date must be in the future. Current date is {$now->format('M d, Y H:i')}. Please choose a date after {$now->clone()->addDay()->format('M d, Y')}";
                
                Log::warning('Tournament edit validation failed: start date in past', [
                    'user_id' => auth()->id(),
                    'tournament_id' => $this->record->id,
                    'tournament_name' => $this->record->name,
                    'start_date' => $startDate->toDateTimeString(),
                    'error_message' => $errorMsg,
                ]);

                Notification::make()
                    ->danger()
                    ->title('Invalid Start Date')
                    ->body($errorMsg)
                    ->send();

                $this->addError('start_date', $errorMsg);
                $this->halt();
            }

            if ($endDate->isBefore($startDate)) {
                $endErrorMsg = "End date must be after start date. Start date: {$startDate->format('M d, Y H:i')}, End date: {$endDate->format('M d, Y H:i')}";
                
                Log::warning('Tournament edit validation failed: end date before start date', [
                    'user_id' => auth()->id(),
                    'tournament_id' => $this->record->id,
                    'tournament_name' => $this->record->name,
                    'start_date' => $startDate->toDateTimeString(),
                    'end_date' => $endDate->toDateTimeString(),
                    'error_message' => $endErrorMsg,
                ]);

                Notification::make()
                    ->danger()
                    ->title('Invalid End Date')
                    ->body($endErrorMsg)
                    ->send();

                $this->addError('end_date', $endErrorMsg);
                $this->halt();
            }
        } catch (\Exception $e) {
            $errorMsg = 'Invalid date format. Please use the date picker to select valid dates.';
            
            Log::error('Tournament edit - date validation error', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'error' => $e->getMessage(),
                'error_message' => $errorMsg,
            ]);

            Notification::make()
                ->danger()
                ->title('Date Format Error')
                ->body($errorMsg)
                ->send();

            $this->addError('start_date', $errorMsg);
            $this->halt();
        }
    }

    private function validateQuestionCount(): void
    {

        // Validate questions - check if key exists and has at least 5 questions
        $questions = $this->data['questions'] ?? [];
        $questionCount = is_array($questions) ? count($questions) : 0;
        
        // Also count existing questions if none were added in form
        if ($questionCount === 0) {
            $questionCount = $this->record->questions()->count();
        }

        Log::debug('Tournament edit - question count validation', [
            'user_id' => auth()->id(),
            'tournament_id' => $this->record->id,
            'question_count' => $questionCount,
        ]);
        
        if ($questionCount < 5) {
            $questionsErrorMsg = "Tournament must have at least 5 questions. Currently has {$questionCount} question(s). Please add " . (5 - $questionCount) . " more question(s).";
            
            Log::warning('Tournament edit validation failed: insufficient questions', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'tournament_name' => $this->record->name,
                'question_count' => $questionCount,
                'required_minimum' => 5,
                'error_message' => $questionsErrorMsg,
            ]);

            Notification::make()
                ->danger()
                ->title('Insufficient Questions')
                ->body($questionsErrorMsg)
                ->send();

            $this->addError('questions', $questionsErrorMsg);
            $this->halt();
        }

        // Prevent editing of active or completed tournaments
        if ($this->record->status !== 'upcoming') {
            $statusErrorMsg = "Cannot edit tournaments that are not in 'upcoming' status. This tournament is currently '{$this->record->status}'. Only upcoming tournaments can be edited.";
            
            Log::warning('Tournament edit validation failed: tournament not in editable state', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'tournament_name' => $this->record->name,
                'status' => $this->record->status,
                'error_message' => $statusErrorMsg,
            ]);

            Notification::make()
                ->danger()
                ->title('Cannot Edit Tournament')
                ->body($statusErrorMsg)
                ->send();

            $this->addError('*', $statusErrorMsg);
            $this->halt();
        }
    }

    protected function afterSave(): void
    {
        // Log tournament update
        $changes = [];
        foreach ($this->record->getChanges() as $key => $value) {
            $changes[$key] = [
                'old' => $this->record->getOriginal($key),
                'new' => $value,
            ];
        }

        Log::info('Tournament successfully updated', [
            'user_id' => auth()->id(),
            'tournament_id' => $this->record->id,
            'tournament_name' => $this->record->name,
            'status' => $this->record->status,
            'changes' => $changes,
            'updated_at' => $this->record->updated_at?->toDateTimeString(),
        ]);

        // Handle imported questions during edit
        $importedQuestionsData = $this->data['import_questions'] ?? [];
        
        // Decode if it's a JSON string
        if (is_string($importedQuestionsData)) {
            $importedQuestionsData = json_decode($importedQuestionsData, true) ?? [];
        }
        
        if (!empty($importedQuestionsData)) {
            Log::debug('Tournament edit - importing questions', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'questions_count' => count($importedQuestionsData),
            ]);

            // Get current max position
            $maxPosition = $this->record->questions()->max('position') ?? 0;
            $position = $maxPosition + 1;
            $importedCount = 0;
            
            foreach ($importedQuestionsData as $qData) {
                try {
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
                    $importedCount++;
                } catch (\Exception $e) {
                    Log::error('Tournament edit - failed to import question', [
                        'user_id' => auth()->id(),
                        'tournament_id' => $this->record->id,
                        'error' => $e->getMessage(),
                        'question_data' => $qData,
                    ]);
                }
            }

            Log::info('Tournament edit - questions imported', [
                'user_id' => auth()->id(),
                'tournament_id' => $this->record->id,
                'imported_count' => $importedCount,
            ]);
        }
    }
}