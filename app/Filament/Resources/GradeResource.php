<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GradeResource\Pages;
use App\Models\Grade;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use BackedEnum;

class GradeResource extends Resource
{
    protected static ?string $model = Grade::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-academic-cap';
    protected static ?int $navigationSort = 1;

    public static function getNavigationGroup(): ?string
    {
        return 'Content Management';
    }
    

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\Select::make('level_id')
                        ->label('Level')
                        ->options(function () {
                            return \App\Models\Level::orderBy('order')->pluck('name', 'id')->toArray();
                        })
                        ->searchable()
                        ->nullable(),

                    Forms\Components\Select::make('type')
                        ->label('Type')
                        ->options([
                            'grade' => 'Grade',
                            'course' => 'Course (Tertiary)'
                        ])
                        ->default('grade')
                        ->required(),

                    
                    Forms\Components\TextInput::make('display_name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Textarea::make('description')
                        ->maxLength(65535),

                    Forms\Components\Toggle::make('is_active')
                        ->required()
                        ->default(true),
                ])
                ->columns(2)
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                    
                Tables\Columns\TextColumn::make('display_name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subjects_count')
                    ->counts('subjects')
                    ->sortable()
                    ->label('Subjects'),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
                    ->before(function (Grade $record) {
                        if ($record->subjects()->count() > 0) {
                            \Filament\Notifications\Notification::make()
                                ->warning()
                                ->title('Cannot delete grade')
                                ->body('This grade has subjects associated with it.')
                                ->persistent()
                                ->send();

                            return false;
                        }
                    }),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGrades::route('/'),
            'create' => Pages\CreateGrade::route('/create'),
            'edit' => Pages\EditGrade::route('/{record}/edit'),
            'view' => Pages\ViewGrade::route('/{record}'),
        ];
    }
}