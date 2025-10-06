<?php

namespace App\Filament\Resources\UserOnboardings;

use App\Filament\Resources\UserOnboardings\Pages\CreateUserOnboarding;
use App\Filament\Resources\UserOnboardings\Pages\EditUserOnboarding;
use App\Filament\Resources\UserOnboardings\Pages\ListUserOnboardings;
use App\Filament\Resources\UserOnboardings\Pages\ViewUserOnboarding;
use App\Filament\Resources\UserOnboardings\Schemas\UserOnboardingForm;
use App\Filament\Resources\UserOnboardings\Schemas\UserOnboardingInfolist;
use App\Filament\Resources\UserOnboardings\Tables\UserOnboardingsTable;
use App\Models\UserOnboarding;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class UserOnboardingResource extends Resource
{
    protected static ?string $model = UserOnboarding::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'User Onboarding';

    public static function form(Schema $schema): Schema
    {
        return UserOnboardingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return UserOnboardingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UserOnboardingsTable::configure($table);
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
            'index' => ListUserOnboardings::route('/'),
            'create' => CreateUserOnboarding::route('/create'),
            'view' => ViewUserOnboarding::route('/{record}'),
            'edit' => EditUserOnboarding::route('/{record}/edit'),
        ];
    }
}
