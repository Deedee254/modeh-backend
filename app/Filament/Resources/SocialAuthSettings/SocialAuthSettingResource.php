<?php

namespace App\Filament\Resources\SocialAuthSettings;

use App\Filament\Resources\SocialAuthSettings\Pages\CreateSocialAuthSetting;
use App\Filament\Resources\SocialAuthSettings\Pages\EditSocialAuthSetting;
use App\Filament\Resources\SocialAuthSettings\Pages\ListSocialAuthSettings;
use App\Filament\Resources\SocialAuthSettings\Pages\ViewSocialAuthSetting;
use App\Filament\Resources\SocialAuthSettings\Schemas\SocialAuthSettingForm;
use App\Filament\Resources\SocialAuthSettings\Schemas\SocialAuthSettingInfolist;
use App\Filament\Resources\SocialAuthSettings\Tables\SocialAuthSettingsTable;
use App\Models\SocialAuthSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class SocialAuthSettingResource extends Resource
{
    protected static ?string $model = SocialAuthSetting::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Key;

    protected static ?string $navigationLabel = 'Social Login Settings';
    protected static ?string $modelLabel = 'Social Login Provider';
    protected static ?string $pluralLabel = 'Social Login Providers';

    public static function getNavigationGroup(): ?string
    {
        return 'Settings';
    }

    public static function form(Schema $schema): Schema
    {
        return SocialAuthSettingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SocialAuthSettingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SocialAuthSettingsTable::configure($table);
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
            'index' => ListSocialAuthSettings::route('/'),
            'create' => CreateSocialAuthSetting::route('/create'),
            'view' => ViewSocialAuthSetting::route('/{record}'),
            'edit' => EditSocialAuthSetting::route('/{record}/edit'),
        ];
    }
}
