<?php

namespace App\Filament\Resources\quiz-masters;

use App\Filament\Resources\quiz-masters\Pages\Createquiz-master;
use App\Filament\Resources\quiz-masters\Pages\Editquiz-master;
use App\Filament\Resources\quiz-masters\Pages\Listquiz-masters;
use App\Filament\Resources\quiz-masters\Pages\Viewquiz-master;
use App\Filament\Resources\quiz-masters\Schemas\quiz-masterForm;
use App\Filament\Resources\quiz-masters\Schemas\quiz-masterInfolist;
use App\Filament\Resources\quiz-masters\Tables\quiz-mastersTable;
use App\Models\quiz-master;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class quiz-masterResource extends Resource
{
    protected static ?string $model = quiz-master::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Briefcase;
    protected static ?string $navigationLabel = 'quiz-masters';
    protected static ?string $modelLabel = 'quiz-master';
    protected static ?string $pluralLabel = 'quiz-masters';
    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function form(Schema $schema): Schema
    {
        return quiz-masterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return quiz-masterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return quiz-mastersTable::configure($table);
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
            'index' => Listquiz-masters::route('/'),
            'create' => Createquiz-master::route('/create'),
            'view' => Viewquiz-master::route('/{record}'),
            'edit' => Editquiz-master::route('/{record}/edit'),
        ];
    }
}
