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
                        
                        Forms\Components\Select::make('grade_filter')
                            ->label('Grade')
                            ->options(function () {
                                return \App\Models\Grade::orderBy('name')->pluck('name', 'id')->toArray();
                            })
                        ->reactive()
                        ->searchable()
                        ->preload(),
                        
                    Forms\Components\Select::make('subject_id')
                        ->relationship('subject', 'name', function (Builder $query, $get) {
                            $query->when($get('grade_filter'), function ($query, $gradeId) {
                                $query->where('grade_id', $gradeId);
                            });
                        })
                        ->required()
                        ->searchable()
                        ->preload()
                        ->visible(fn ($get) => filled($get('grade_filter'))),

                    Forms\Components\Toggle::make('is_approved')
                        ->required()
                        ->default(false),
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
                TextColumn::make('grade.name')->label('Grade')->sortable()->searchable(),
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
            // create/edit pages are not present in Pages folder; register only existing pages
            'view' => Pages\ViewTopic::route('/{record}'),
        ];
    }
}
