<?php

namespace App\Filament\Resources\QuizMasters;

use App\Filament\Resources\QuizMasters\Pages\CreateQuizMaster;
use App\Filament\Resources\QuizMasters\Pages\EditQuizMaster;
use App\Filament\Resources\QuizMasters\Pages\ListQuizMasters;
use App\Filament\Resources\QuizMasters\Pages\ViewQuizMaster;
use App\Filament\Resources\QuizMasters\Schemas\QuizMasterForm;
use App\Filament\Resources\QuizMasters\Schemas\QuizMasterInfolist;
use App\Filament\Resources\QuizMasters\Tables\QuizMastersTable;
use App\Models\QuizMaster;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class QuizMasterResource extends Resource
{
    protected static ?string $model = QuizMaster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Briefcase;
    protected static ?string $navigationLabel = 'Quiz Masters';
    protected static ?string $modelLabel = 'Quiz Master';
    protected static ?string $pluralLabel = 'Quiz Masters';
    protected static ?string $recordTitleAttribute = 'user.name';

    public static function getNavigationGroup(): ?string
    {
        return 'User Management';
    }

    public static function form(Schema $schema): Schema
    {
        return QuizMasterForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return QuizMasterInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return QuizMastersTable::configure($table);
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
            'index' => ListQuizMasters::route('/'),
            'create' => CreateQuizMaster::route('/create'),
            'view' => ViewQuizMaster::route('/{record}'),
            'edit' => EditQuizMaster::route('/{record}/edit'),
        ];
    }
}
