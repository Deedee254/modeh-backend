<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentSettingResource\Pages;
use App\Models\PaymentSetting;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class PaymentSettingResource extends Resource
{
    protected static ?string $model = PaymentSetting::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('gateway')->required(),
            Select::make('config.environment')->options(['sandbox' => 'Sandbox', 'live' => 'Live'])->required()->default('sandbox'),
            TextInput::make('config.consumer_key')->label('Consumer Key'),
            TextInput::make('config.consumer_secret')->label('Consumer Secret'),
            TextInput::make('config.shortcode')->label('Shortcode'),
            TextInput::make('config.passkey')->label('Passkey'),
            TextInput::make('config.callback_url')->label('Callback URL'),
            Textarea::make('config.raw')->label('Raw JSON (optional)')->hint('Optional raw JSON config, overrides structured fields')->rows(4),
            \Filament\Forms\Components\Toggle::make('config.simulate')->label('Simulate (sandbox only)')->helperText('When enabled, STK pushes are simulated for local development.'),
            TextInput::make('revenue_share')->numeric()->default(0)->suffix('%'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('gateway'),
            TextColumn::make('revenue_share')->suffix('%'),
            TextColumn::make('updated_at')->date(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPaymentSettings::route('/'),
            'create' => Pages\CreatePaymentSetting::route('/create'),
            'edit' => Pages\EditPaymentSetting::route('/{record}/edit'),
        ];
    }
}
