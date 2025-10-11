<?php

namespace App\Filament\Resources\quizees;

use App\Filament\Resources\quizees\Pages\Createquizee;
use App\Filament\Resources\quizees\Pages\Editquizee;
use App\Filament\Resources\quizees\Pages\Listquizees;
use App\Filament\Resources\quizees\Pages\Viewquizee;
use App\Filament\Resources\quizees\Schemas\quizeeForm;
use App\Filament\Resources\quizees\Schemas\quizeeInfolist;
use App\Filament\Resources\quizees\Tables\quizeesTable;
use App\Models\quizee;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class quizeeResource extends Resource
{
    protected static ?string $model = quizee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AcademicCap;
    protected static ?string $navigationLabel = 'quizees';
    protected static ?string $modelLabel = 'quizee';
    protected static ?string $pluralLabel = 'quizees';
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function form(Schema $schema): Schema
    {
        return quizeeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return quizeeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return quizeesTable::configure($table);
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
            'index' => Listquizees::route('/'),
            'create' => Createquizee::route('/create'),
            'view' => Viewquizee::route('/{record}'),
            'edit' => Editquizee::route('/{record}/edit'),
        ];
    }
}
