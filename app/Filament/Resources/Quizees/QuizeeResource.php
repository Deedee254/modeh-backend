<?php

namespace App\Filament\Resources\Quizees;

use App\Filament\Resources\Quizees\Pages\CreateQuizee;
use App\Filament\Resources\Quizees\Pages\EditQuizee;
use App\Filament\Resources\Quizees\Pages\ListQuizees;
use App\Filament\Resources\Quizees\Pages\ViewQuizee;
use App\Filament\Resources\Quizees\Schemas\QuizeeForm;
use App\Filament\Resources\Quizees\Schemas\QuizeeInfolist;
use App\Filament\Resources\Quizees\Tables\QuizeesTable;
use App\Models\Quizee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class QuizeeResource extends Resource
{
    protected static ?string $model = Quizee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;
    protected static ?string $navigationLabel = 'Quizees';
    protected static ?string $modelLabel = 'Quizee';
    protected static ?string $pluralLabel = 'Quizees';
    protected static ?string $recordTitleAttribute = 'first_name';

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function form(Schema $schema): Schema
    {
        return QuizeeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuizeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuizeesTable::configure($table);
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
            'index' => ListQuizees::route('/'),
            'create' => CreateQuizee::route('/create'),
            'view' => ViewQuizee::route('/{record}'),
            'edit' => EditQuizee::route('/{record}/edit'),
        ];
    }
}
