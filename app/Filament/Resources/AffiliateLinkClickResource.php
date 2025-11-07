<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliateLinkClickResource\Pages;
use App\Models\AffiliateLinkClick;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Support\Colors\Color;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

class AffiliateLinkClickResource extends Resource
{
    protected static ?string $model = AffiliateLinkClick::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cursor-click';

    protected static \UnitEnum|string|null $navigationGroup = 'Affiliates';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('affiliate_code')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source_url')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('target_url')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('utm_source')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('utm_medium')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('utm_campaign')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('ip_address')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('converted_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                Filter::make('converted')
                    ->query(fn (Builder $query): Builder => $query->whereNotNull('converted_at')),
                Filter::make('not_converted')
                    ->query(fn (Builder $query): Builder => $query->whereNull('converted_at')),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAffiliateLinkClicks::route('/'),
            'view' => Pages\ViewAffiliateLinkClick::route('/{record}'),
        ];
    }
}