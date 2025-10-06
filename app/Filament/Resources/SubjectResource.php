<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubjectResource\Pages;
use App\Models\Subject;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;

class SubjectResource extends Resource
{
    protected static ?string $model = Subject::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-s-book-open';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('icon')->label('Icon')->rounded(),
                TextColumn::make('id')->sortable(),
                TextColumn::make('name')->searchable()->label('Subject'),
                TextColumn::make('grade.name')->label('Grade'),
                IconColumn::make('is_approved')->boolean()->label('Approved'),
            ])
            ->actions([]);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required(),
            TextInput::make('grade_id')->required()->numeric(),
            FileUpload::make('icon')->image()->directory('subjects/icons')->maxSize(2048),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubjects::route('/'),
            'create' => Pages\CreateSubject::route('/create'),
            'edit' => Pages\EditSubject::route('/{record}/edit'),
        ];
    }
}
