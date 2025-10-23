<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AchievementResource\Pages;
use App\Filament\Resources\Navigation\NavigationGroup;
use App\Models\Achievement;
use Filament\Forms;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Group;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ColorPicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\Action;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class AchievementResource extends Resource
{
    protected static ?string $model = Achievement::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-trophy';
    protected static \UnitEnum|string|null $navigationGroup = 'Quiz Management';
    protected static ?int $navigationSort = 4;

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            Group::make()
                ->schema([
                    Section::make('Basic Information')
                        ->schema([
                            Forms\Components\TextInput::make('name')
                                ->required()
                                ->maxLength(255),
                            Forms\Components\TextInput::make('slug')
                                ->required()
                                ->unique(ignoreRecord: true)
                                ->maxLength(255),
                            Forms\Components\Textarea::make('description')
                                ->required()
                                ->maxLength(1000)
                                ->rows(3),
                            Forms\Components\TextInput::make('icon')
                                ->maxLength(255)
                                ->helperText('Emoji or icon character (e.g., 🏆, ⭐, 📚)'),
                        ])->columns(2),
                        
                    Section::make('Achievement Settings')
                        ->schema([
                            Forms\Components\Select::make('category')
                                ->required()
                                ->options([
                                    'time' => 'Time-based Achievements',
                                    'subject' => 'Subject-based Achievements',
                                    'improvement' => 'Improvement-based Achievements',
                                    'weekend' => 'Weekend Warrior',
                                    'topic' => 'Topic-based Achievements',
                                    'daily_challenge' => 'Daily Challenge Achievements',
                                    'streak' => 'Streak Achievements',
                                ])
                                ->reactive(),
                                
                            Forms\Components\Select::make('type')
                                ->required()
                                ->options([
                                    'time' => 'Time Based',
                                    'streak' => 'Streak',
                                    'completion' => 'Completion',
                                    'score' => 'Score',
                                    'subject' => 'Subject',
                                    'topic' => 'Topic',
                                    'improvement' => 'Improvement',
                                    'daily_challenge' => 'Daily Challenge',
                                    'weekend' => 'Weekend',
                                ]),
            Forms\Components\TextInput::make('points')
                                ->required()
                                ->numeric()
                                ->default(10)
                                ->minValue(0)
                                ->maxValue(1000),
                            Forms\Components\TextInput::make('criteria_value')
                                ->required()
                                ->numeric()
                                ->helperText(fn ($get) => match($get('category')) {
                                    'time' => 'Time in seconds or minutes based on achievement',
                                    'streak' => 'Number of correct answers in a row',
                                    'subject' => 'Number of quizzes or score threshold',
                                    'improvement' => 'Percentage improvement required',
                                    'weekend' => 'Number of quizzes to complete',
                                    'topic' => 'Number of topics or score threshold',
                                    'daily_challenge' => 'Number of challenges to complete',
                                    default => 'Value required to earn this achievement',
                                }),
                        ])->columns(2),
                ]),
                
            Group::make()
                ->schema([
                    Section::make('Display Settings')
                        ->schema([
                            Forms\Components\ColorPicker::make('color')
                                ->default('#4f46e5'),
                            Forms\Components\Toggle::make('is_active')
                                ->required()
                                ->default(true)
                                ->helperText('Only active achievements can be earned'),
                        ]),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('icon')
                    ->label(''),
                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Achievement $record): string => $record->description),
                TextColumn::make('category')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'time' => 'info',
                        'subject' => 'success',
                        'improvement' => 'warning',
                        'weekend' => 'danger',
                        'topic' => 'primary',
                        'daily_challenge' => 'purple',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'time' => 'Time-based',
                        'subject' => 'Subject-based',
                        'improvement' => 'Improvement',
                        'weekend' => 'Weekend',
                        'topic' => 'Topic-based',
                        'daily_challenge' => 'Daily Challenge',
                        default => $state,
                    }),
                TextColumn::make('points')
                    ->sortable()
                    ->badge()
                    ->color('success'),
                TextColumn::make('criteria_value')
                    ->label('Criteria')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->boolean()
                    ->label('Active'),
                TextColumn::make('users_count')
                    ->counts('users')
                    ->label('Times Awarded')
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->options([
                        'time' => 'Time-based Achievements',
                        'subject' => 'Subject-based Achievements',
                        'improvement' => 'Improvement-based Achievements',
                        'weekend' => 'Weekend Warrior',
                        'topic' => 'Topic-based Achievements',
                        'daily_challenge' => 'Daily Challenge Achievements',
                        'streak' => 'Streak Achievements',
                    ])
                    ->multiple()
                    ->label('Category'),
                SelectFilter::make('type')
                    ->options([
                        'time' => 'Time Based',
                        'streak' => 'Streak',
                        'completion' => 'Completion',
                        'score' => 'Score',
                        'subject' => 'Subject',
                        'topic' => 'Topic',
                        'improvement' => 'Improvement',
                        'daily_challenge' => 'Daily Challenge',
                        'weekend' => 'Weekend',
                    ])
                    ->multiple()
                    ->label('Type'),
                TernaryFilter::make('is_active')
                    ->label('Active Status')
                    ->placeholder('All')
                    ->trueLabel('Active Only')
                    ->falseLabel('Inactive Only'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('view_users')
                    ->label('View Users')
                    ->icon('heroicon-o-users')
                    ->url(fn (Achievement $record) => static::getUrl('view-users', ['record' => $record->id]))
                    ->openUrlInNewTab(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkAction::make('update_points')
                    ->label('Update Points')
                    ->icon('heroicon-o-currency-dollar')
                    ->form([
                        TextInput::make('points')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(1000)
                            ->label('New Points Value'),
                    ])
                    ->action(function (array $data, Collection $records) {
                        foreach ($records as $record) {
                            $record->update(['points' => $data['points']]);
                            // Recalculate user points
                            $users = $record->users;
                            foreach ($users as $user) {
                                try {
                                    $user->points = $user->achievements()->sum('points');
                                    $user->save();
                                } catch (\Exception $e) {
                                    Log::error("Failed to update user points: " . $e->getMessage());
                                }
                            }
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                \Filament\Actions\BulkAction::make('toggle_active')
                    ->label('Toggle Active Status')
                    ->icon('heroicon-o-power')
                    ->action(function (Collection $records) {
                        foreach ($records as $record) {
                            $record->update(['is_active' => !$record->is_active]);
                        }
                    })
                    ->deselectRecordsAfterCompletion(),
                \Filament\Actions\DeleteBulkAction::make()
                    ->before(function (Collection $records) {
                        // Check if any achievements have been awarded
                        $hasAwards = $records->contains(function ($record) {
                            return $record->users()->count() > 0;
                        });
                        
                        if ($hasAwards) {
                            throw new \Exception('Cannot delete achievements that have been awarded to users.');
                        }
                    }),
            ]);
    }
    
    public static function getRelations(): array
    {
        return [
            //
        ];
    }
    
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAchievements::route('/'),
            'create' => Pages\CreateAchievement::route('/create'),
            'edit' => Pages\EditAchievement::route('/{record}/edit'),
            'view-users' => Pages\ViewAchievementUsers::route('/{record}/users'),
        ];
    }
}