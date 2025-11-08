<?php
namespace App\Filament\Resources;

use App\Models\AffiliatePayout;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;

class AffiliatePayoutResource extends Resource
{
    protected static ?string $model = AffiliatePayout::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-currency-dollar';
    protected static \UnitEnum|string|null $navigationGroup = 'Affiliate Management';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('affiliate_id')->required(),
            Forms\Components\TextInput::make('amount')->numeric()->required(),
            Forms\Components\Select::make('status')->options([
                'pending' => 'Pending',
                'processing' => 'Processing',
                'completed' => 'Completed',
                'failed' => 'Failed',
            ])->required(),
            Forms\Components\TextInput::make('paid_at')->datetime(),
            Forms\Components\Textarea::make('notes'),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('affiliate_id'),
            Tables\Columns\TextColumn::make('amount'),
            Tables\Columns\TextColumn::make('status'),
            Tables\Columns\TextColumn::make('paid_at')->dateTime(),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'pending' => 'Pending',
                'processing' => 'Processing',
                'completed' => 'Completed',
                'failed' => 'Failed',
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\AffiliatePayoutResource\Pages\ListAffiliatePayouts::route('/'),
            'create' => \App\Filament\Resources\AffiliatePayoutResource\Pages\CreateAffiliatePayout::route('/create'),
            'edit' => \App\Filament\Resources\AffiliatePayoutResource\Pages\EditAffiliatePayout::route('/{record}/edit'),
        ];
    }
}
