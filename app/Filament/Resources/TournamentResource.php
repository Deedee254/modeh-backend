<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TournamentResource\Pages;
use App\Models\Tournament;
use App\Models\Question;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Grid;
use Filament\Actions\Action;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Illuminate\Support\Facades\Log;
class TournamentResource extends Resource
{
    protected static ?string $model = Tournament::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';
    protected static \UnitEnum|string|null $navigationGroup = 'Tournaments';
    protected static ?int $navigationSort = 1;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\RichEditor::make('description')
                        ->required()
                        ->maxLength(65535)
                        ->columnSpan('full')
                        ->extraAttributes(['style' => 'min-height: 18rem;']),

                    Forms\Components\Select::make('sponsor_id')
                        ->relationship('sponsor', 'name')
                        ->preload()
                        ->searchable(),

                    Forms\Components\TextInput::make('sponsor_banner_url')
                        ->url()
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => filled($get('sponsor_id'))),

                    Forms\Components\Textarea::make('sponsor_message')
                        ->maxLength(65535)
                        ->visible(fn (Get $get): bool => filled($get('sponsor_id'))),

                    Grid::make(2)
                        ->schema([
                            Forms\Components\DateTimePicker::make('start_date')
                                ->required(),

                            Forms\Components\DateTimePicker::make('end_date')
                                ->required(),

                            Forms\Components\TextInput::make('prize_pool')
                                ->numeric()
                                ->prefix('$')
                                ->required(),

                            Forms\Components\TextInput::make('entry_fee')
                                ->numeric()
                                ->prefix('$')
                                ->default(0),

                            Forms\Components\TextInput::make('max_participants')
                                ->numeric()
                                ->minValue(2)
                                ->required(),
                        ]),
                ])
                ->columns(2),

            Section::make()
                ->schema([
                    Forms\Components\Select::make('level_id')
                        ->relationship('level', 'name', fn (Builder $query) => $query->orderBy('order'))
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('grade_id', null);
                            $set('subject_id', null);
                            $set('topic_id', null);
                            $set('questions', []);
                        }),

                    Forms\Components\Select::make('grade_id')
                        ->relationship('grade', 'display_name', function (Builder $query, Get $get) {
                            $levelId = $get('level_id');
                            if ($levelId) {
                                $query->where('level_id', $levelId);
                            }
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('subject_id', null);
                            $set('topic_id', null);
                            $set('questions', []);
                        }),

                    Forms\Components\Select::make('subject_id')
                        ->relationship('subject', 'name', function (Builder $query, Get $get) {
                            $gradeId = $get('grade_id');
                            if ($gradeId) {
                                $query->where('grade_id', $gradeId);
                            }
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(function (Set $set) {
                            $set('topic_id', null);
                            $set('questions', []);
                        }),

                    Forms\Components\Select::make('topic_id')
                        ->relationship('topic', 'name', function (Builder $query, Get $get) {
                            $subjectId = $get('subject_id');
                            if ($subjectId) {
                                $query->where('subject_id', $subjectId);
                            }
                        })
                        ->searchable()
                        ->preload()
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('questions', [])),

                    Forms\Components\Select::make('questions')
                        ->multiple()
                        ->relationship('questions', 'body', function (Builder $query, Get $get) {
                            if ($get('grade_id')) $query->where('grade_id', $get('grade_id'));
                            if ($get('subject_id')) $query->where('subject_id', $get('subject_id'));
                            if ($get('topic_id')) $query->where('topic_id', $get('topic_id'));
                            if ($get('level_id')) $query->where('level_id', $get('level_id'));
                        })
                        ->searchable()
                        ->preload()
                        ->live(),

                    Grid::make(2)->schema([
                        Action::make('browseBank')
                            ->label('Browse Bank')
                            ->icon('heroicon-o-circle-stack')
                            ->modalHeading('Browse Question Bank')
                            ->modalWidth('7xl')
                            ->modalContent(function (Get $get) {
                                $filters = [
                                    'level_id' => $get('level_id'),
                                    'grade_id' => $get('grade_id'),
                                    'subject_id' => $get('subject_id'),
                                    'topic_id' => $get('topic_id'),
                                ];
                                return view('filament.modals.bank-questions-table', ['filters' => $filters]);
                            })
                            ->modalSubmitAction(false) // We handle selection via JS events
                            ->modalCancelAction(false)
                            ->color('secondary'),

                        Action::make('uploadFile')
                            ->label('Upload from File')
                            ->icon('heroicon-o-arrow-up-tray')
                            ->color('secondary')
                            ->form([
                                Forms\Components\FileUpload::make('file')
                                    ->label('CSV File')
                                    ->required()
                                    ->acceptedFileTypes(['text/csv', 'application/vnd.ms-excel'])
                                    ->disk('local')
                                    ->directory('temp')
                                    ->visibility('private')
                                    ->helperText('Upload a CSV file with columns: type,text,option1,option2,option3,option4,answers,marks,difficulty,explanation,youtube_url,media'),
                            ])
                            ->action(function (array $data, Set $set, Get $get) {
                                $uploadedFileName = $data['file'] ?? null;
                                
                                if (!$uploadedFileName) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Upload Failed')
                                        ->body('No file provided.')
                                        ->danger()
                                        ->send();
                                    return;
                                }
                                
                                // File is stored in storage/app/private/temp/ by FileUpload component with visibility('private')
                                // The uploadedFileName comes as 'temp/filename.csv' so we need to prepend 'private/'
                                $path = storage_path('app/private') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $uploadedFileName);
                                
                                if (!file_exists($path)) {
                                    Log::error('upload_file: file not found at expected path', ['path' => $path, 'uploaded' => $uploadedFileName]);
                                    \Filament\Notifications\Notification::make()
                                        ->title('Upload Failed')
                                        ->body('Unable to read uploaded file.')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                                try {
                                    if (in_array($ext, ['csv', 'txt'])) {
                                        // Quick CSV sanity check before trying PhpSpreadsheet
                                        $fh = @fopen($path, 'r');
                                        if (! $fh) {
                                            Log::error('upload_file: unable to open CSV for reading', ['path' => $path, 'ext' => $ext]);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Upload Failed')
                                                ->body('Unable to open the uploaded CSV file.')
                                                ->danger()
                                                ->send();
                                            return;
                                        }
                                        $first = @fgetcsv($fh);
                                        if ($first === false) {
                                            $sample = @file_get_contents($path, false, null, 0, 2048);
                                            Log::error('upload_file: CSV appears empty or malformed', ['path' => $path, 'sample' => substr($sample, 0, 2048)]);
                                            fclose($fh);
                                            \Filament\Notifications\Notification::make()
                                                ->title('Upload Failed')
                                                ->body('The uploaded CSV appears empty or malformed.')
                                                ->danger()
                                                ->send();
                                            return;
                                        }
                                        // rewind and let PhpSpreadsheet parse it for consistency
                                        rewind($fh);
                                        fclose($fh);
                                    }

                                    $spreadsheet = IOFactory::load($path);
                                    $sheet = $spreadsheet->getActiveSheet();
                                    $rows = $sheet->toArray(null, true, true, true);
                                } catch (\Throwable $e) {
                                    $sample = @file_exists($path) ? @file_get_contents($path, false, null, 0, 4096) : null;
                                    Log::error('upload_file: failed to parse uploaded file', [
                                        'path' => $path,
                                        'ext' => $ext,
                                        'size' => @filesize($path),
                                        'error' => $e->getMessage(),
                                        'trace' => $e->getTraceAsString(),
                                        'sample' => $sample ? substr($sample, 0, 2048) : null,
                                    ]);
                                    \Filament\Notifications\Notification::make()
                                        ->title('Upload Failed')
                                        ->body('Unable to parse the uploaded file. Check server logs for details.')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                if (empty($rows)) {
                                    return;
                                }

                                $levelId = $get('level_id');
                                $gradeId = $get('grade_id');
                                $subjectId = $get('subject_id');
                                $topicId = $get('topic_id');

                                if (!$levelId || !$gradeId || !$subjectId) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Upload Failed')
                                        ->body('Please select Level, Grade, and Subject before uploading questions.')
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                $header = array_map('strtolower', array_values(array_shift($rows)));
                                $expectedHeaders = ['type', 'text', 'option1', 'option2', 'option3', 'option4', 'answers', 'marks', 'difficulty', 'explanation', 'youtube_url', 'media'];
                                $missingHeaders = array_diff($expectedHeaders, $header);
                                if (!empty($missingHeaders)) {
                                    \Filament\Notifications\Notification::make()
                                        ->title('Upload Failed')
                                        ->body('CSV must contain all required columns: ' . implode(', ', $expectedHeaders))
                                        ->danger()
                                        ->send();
                                    return;
                                }

                                $createdQuestions = [];
                                foreach ($rows as $row) {
                                    $row = array_combine($header, array_values($row));
                                    $type = $row['type'];
                                    $body = $row['text'];

                                    $options = [];
                                    if (in_array($type, ['mcq', 'multi'])) {
                                        $optionTexts = array_filter([$row['option1'], $row['option2'], $row['option3'], $row['option4']]);
                                        $options = array_map(fn($text) => ['text' => $text], $optionTexts);
                                    }

                                    $answers = array_map('trim', explode(',', $row['answers']));
                                    $answersArray = [];

                                    if ($type === 'mcq') {
                                        // For MCQ, store the correct answer text in answers array
                                        $correctText = $answers[0] ?? '';
                                        if ($correctText) {
                                            $answersArray = [$correctText];
                                        }
                                    } elseif ($type === 'multi') {
                                        // For multi-select, store all correct answer texts
                                        $answersArray = $answers;
                                    } else {
                                        // For other types (short, numeric, etc.), store as-is
                                        $answersArray = $answers;
                                    }

                                    $question = Question::create([
                                        'type' => $type,
                                        'body' => $body,
                                        'options' => $options,
                                        'answers' => $answersArray,
                                        'marks' => (float)$row['marks'],
                                        'difficulty' => (int)$row['difficulty'],
                                        'explanation' => $row['explanation'],
                                        'youtube_url' => $row['youtube_url'],
                                        'media_path' => $row['media'],
                                        'level_id' => $levelId,
                                        'grade_id' => $gradeId,
                                        'subject_id' => $subjectId,
                                        'topic_id' => $topicId,
                                        'is_banked' => true,
                                        'created_by' => auth()->id(),
                                    ]);

                                    $createdQuestions[] = $question->id;
                                }

                                $currentIds = $get('questions') ?? [];
                                $set('questions', array_unique(array_merge($currentIds, $createdQuestions)));

                                // Clean up the temporary file
                                try {
                                    if (file_exists($path)) {
                                        unlink($path);
                                    }
                                } catch (\Throwable $e) {
                                    Log::warning('upload_file: failed to delete temp file', ['path' => $path, 'error' => $e->getMessage()]);
                                }

                                \Filament\Notifications\Notification::make()
                                    ->title('Upload Successful')
                                    ->body('Created ' . count($createdQuestions) . ' questions.')
                                    ->success()
                                    ->send();
                            }),
                    ]),

                    Forms\Components\Placeholder::make('total_questions')
                        ->label('Selected Questions')
                        ->content(fn (Get $get): string => sprintf('%d questions', count($get('questions') ?? []))),

                    Forms\Components\TagsInput::make('rules')
                        ->placeholder('Add a rule')
                        ->splitKeys(['Enter', 'Tab', ','])
                        ->helperText('Press Enter or Tab to add a rule'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('sponsor.name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('start_date')
                    ->dateTime()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable()
                    ->color(fn (string $state): string => match ($state) {
                        'upcoming' => 'warning',
                        'active' => 'success',
                        'completed' => 'gray',
                        default => 'gray',
                    }),

                \Filament\Tables\Columns\TextColumn::make('prize_pool')
                    ->money()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('participants_count')
                    ->counts('participants')
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('grade.name')
                    ->searchable()
                    ->sortable(),

                \Filament\Tables\Columns\TextColumn::make('subject.name')
                    ->searchable()
                    ->sortable(),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('status')
                    ->options([
                        'upcoming' => 'Upcoming',
                        'active' => 'Active',
                        'completed' => 'Completed',
                    ]),

                \Filament\Tables\Filters\SelectFilter::make('grade')
                    ->relationship('grade', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('subject')
                    ->relationship('subject', 'name'),

                \Filament\Tables\Filters\SelectFilter::make('sponsor')
                    ->relationship('sponsor', 'name'),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                Action::make('generate_matches')
                    ->action(fn (Tournament $record) => $record->generateMatches())
                    ->requiresConfirmation()
                    ->visible(fn (Tournament $record): bool => $record->status === 'upcoming')
                    ->color('success')
                    ->icon('heroicon-o-play'),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Resources\TournamentResource\RelationManagers\ParticipantsRelationManager::class,
            \App\Filament\Resources\TournamentResource\RelationManagers\QuestionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTournaments::route('/'),
            'create' => Pages\CreateTournament::route('/create'),
            'edit' => Pages\EditTournament::route('/{record}/edit'),
            'view' => Pages\ViewTournament::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::where('status', 'active')->count();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount('participants');
    }
}