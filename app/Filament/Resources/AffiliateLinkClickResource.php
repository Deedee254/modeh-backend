<?php
namespace App\Filament\Resources;

use App\Models\AffiliateLinkClick;
use Filament\Resources\Resource;
use Filament\Tables;

class AffiliateLinkClickResource extends Resource
{
    protected static ?string $model = AffiliateLinkClick::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-link';
    protected static \UnitEnum|string|null $navigationGroup = 'Affiliate Management';

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('affiliate_id'),
            Tables\Columns\TextColumn::make('ip_address'),
            Tables\Columns\TextColumn::make('user_agent'),
            Tables\Columns\TextColumn::make('clicked_at')->dateTime(),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\AffiliateLinkClickResource\Pages\ListAffiliateLinkClicks::route('/'),
        ];
    }
}
