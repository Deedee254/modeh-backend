<?php

namespace App\Filament\Resources;
// ...existing use statements...

use App\Filament\Resources\TopicResource\Pages;
use App\Models\Topic;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Forms;
use Illuminate\Database\Eloquent\Builder;

class TopicResource extends Resource
{
    protected static ?string $model = Topic::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';
    protected static \UnitEnum|string|null $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 3;
    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                        
                        // Select the subject directly when creating a topic. We present subjects grouped
                        // by Level -> Grade (or Course) to make it easier for admins to find the right subject.
                        Forms\Components\Select::make('subject_id')
                            ->label('Subject')
                            ->options(function () {
                                $subjects = \App\Models\Subject::with(['grade.level'])->get();
                                $groups = [];
                                foreach ($subjects as $s) {
                                    $levelName = $s->grade?->level?->name ?? 'No level';
                                    $grade = $s->grade;
                                    $gradeLabel = $grade ? ($grade->type === 'course' ? ($grade->display_name ?? $grade->name) . ' (Course)' : ($grade->name)) : 'No grade';
                                    $label = "{$gradeLabel} — {$s->name}";
                                    $groups[$levelName][$s->id] = $label;
                                }
                                return $groups;
                            })
                            ->required()
                            ->searchable()
                            ->preload(),

                    Forms\Components\TextInput::make('created_by')
                        ->label('Created By (user id)')
                        ->numeric()
                        ->nullable(),

                    Forms\Components\Textarea::make('description')
                        ->rows(4)
                        ->maxLength(65535)
                        ->nullable(),

                    Forms\Components\FileUpload::make('image')
                        ->image()
                        ->directory('topics/images')
                        ->maxSize(2048),

                    Forms\Components\Toggle::make('is_approved')
                        ->required()
                        ->default(false),

                    Forms\Components\DateTimePicker::make('approval_requested_at')
                        ->label('Approval Requested At')
                        ->nullable(),
                ])
                ->columns(2)
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->label('Topic'),
                TextColumn::make('subject.grade.name')->label('Grade')->sortable()->searchable(),
                TextColumn::make('subject.name')->label('Subject')->sortable()->searchable(),
                IconColumn::make('is_approved')->boolean()->label('Approved'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTopics::route('/'),
            'create' => Pages\CreateTopic::route('/create'),
            'edit' => Pages\EditTopic::route('/{record}/edit'),
            'view' => Pages\ViewTopic::route('/{record}'),
        ];
    }
}
