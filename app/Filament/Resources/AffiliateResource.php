<?php
namespace App\Filament\Resources;

use App\Models\Affiliate;
use Filament\Forms;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Tables;

class AffiliateResource extends Resource
{
    protected static ?string $model = Affiliate::class;
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-user-group';
    protected static \UnitEnum|string|null $navigationGroup = 'Affiliate Management';

    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Forms\Components\TextInput::make('user_id')->required(),
            Forms\Components\TextInput::make('referral_code')->required(),
            Forms\Components\TextInput::make('commission_rate')->numeric()->required(),
            Forms\Components\TextInput::make('total_earnings')->numeric()->disabled(),
            Forms\Components\Select::make('status')->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
            ])->required(),
        ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table->columns([
            Tables\Columns\TextColumn::make('id')->sortable(),
            Tables\Columns\TextColumn::make('user_id'),
            Tables\Columns\TextColumn::make('referral_code'),
            Tables\Columns\TextColumn::make('commission_rate'),
            Tables\Columns\TextColumn::make('total_earnings'),
            Tables\Columns\TextColumn::make('status'),
            Tables\Columns\TextColumn::make('created_at')->dateTime(),
        ])->filters([
            Tables\Filters\SelectFilter::make('status')->options([
                'active' => 'Active',
                'inactive' => 'Inactive',
            ]),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\AffiliateResource\Pages\ListAffiliates::route('/'),
            'create' => \App\Filament\Resources\AffiliateResource\Pages\CreateAffiliate::route('/create'),
            'edit' => \App\Filament\Resources\AffiliateResource\Pages\EditAffiliate::route('/{record}/edit'),
        ];
    }
}
