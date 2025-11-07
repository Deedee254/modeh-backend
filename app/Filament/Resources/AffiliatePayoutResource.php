<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AffiliatePayoutResource\Pages;
use App\Models\AffiliatePayout;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Support\Colors\Color;

class AffiliatePayoutResource extends Resource
{
    protected static ?string $model = AffiliatePayout::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cash';

    protected static \UnitEnum|string|null $navigationGroup = 'Affiliate Management';

    public static function form(\Filament\Schemas\Schema $schema): \Filament\Schemas\Schema
    {
        return $schema->schema([
            \Filament\Schemas\Components\Section::make('Payout Details')
                ->schema([
                    \Filament\Forms\Components\Select::make('user_id')
                        ->relationship('user', 'name')
                        ->required()
                        ->searchable(),
                    \Filament\Forms\Components\TextInput::make('amount')
                        ->required()
                        ->numeric()
                        ->prefix('KES')
                        ->step(0.01),
                    \Filament\Forms\Components\Select::make('status')
                        ->options([
                            AffiliatePayout::STATUS_PENDING => 'Pending',
                            AffiliatePayout::STATUS_PROCESSING => 'Processing',
                            AffiliatePayout::STATUS_COMPLETED => 'Completed',
                            AffiliatePayout::STATUS_FAILED => 'Failed',
                        ])
                        ->required()
                        ->native(false),
                    \Filament\Forms\Components\TextInput::make('payment_reference')
                        ->helperText('Reference number from payment provider'),
                    \Filament\Forms\Components\Textarea::make('notes')
                        ->rows(3),
                ])
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money('KES')
                    ->sortable(),
                TextColumn::make('status')
                    ->sortable(),
                TextColumn::make('payment_reference'),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        AffiliatePayout::STATUS_PENDING => 'Pending',
                        AffiliatePayout::STATUS_PROCESSING => 'Processing',
                        AffiliatePayout::STATUS_COMPLETED => 'Completed',
                        AffiliatePayout::STATUS_FAILED => 'Failed',
                    ]),
            ])
            ->actions([
                \Filament\Actions\ActionGroup::make([
                    \Filament\Actions\Action::make('process')
                        ->icon('heroicon-m-play')
                        ->color(Color::Amber)
                        ->action(function (AffiliatePayout $record): void {
                            $record->update(['status' => AffiliatePayout::STATUS_PROCESSING]);
                        })
                        ->requiresConfirmation()
                        ->visible(fn (AffiliatePayout $record): bool => $record->status === AffiliatePayout::STATUS_PENDING),
                    \Filament\Actions\Action::make('complete')
                        ->icon('heroicon-m-check')
                        ->color(Color::Green)
                        ->action(function (AffiliatePayout $record): void {
                            $record->update([
                                'status' => AffiliatePayout::STATUS_COMPLETED,
                                'paid_at' => now(),
                            ]);
                        })
                        ->requiresConfirmation()
                        ->visible(fn (AffiliatePayout $record): bool => $record->status === AffiliatePayout::STATUS_PROCESSING),
                    \Filament\Actions\Action::make('view')
                        ->icon('heroicon-m-eye')
                        ->color(Color::Gray)
                        ->url(fn (AffiliatePayout $record): string => route('filament.resources.affiliate-payouts.view', $record)),
                ]),
            ])
            ->bulkActions([
                \Filament\Actions\DeleteBulkAction::make()
                    ->icon('heroicon-m-trash')
                    ->color(Color::Red),
            ]);
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
            'index' => Pages\ListAffiliatePayouts::route('/'),
            'create' => Pages\CreateAffiliatePayout::route('/create'),
            'edit' => Pages\EditAffiliatePayout::route('/{record}/edit'),
        ];
    }
}