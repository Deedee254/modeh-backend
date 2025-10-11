<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WithdrawalRequestResource\Pages;
use App\Models\WithdrawalRequest;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Filament\Resources\Resource;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use BackedEnum;

class WithdrawalRequestResource extends Resource
{
    protected static ?string $model = WithdrawalRequest::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-cog';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('quiz-master_id')->disabled(),
            TextInput::make('amount')->disabled(),
            TextInput::make('method')->disabled(),
            TextInput::make('status'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('quiz-master.name')->label('quiz-master'),
            TextColumn::make('amount')->money('KES'),
            TextColumn::make('method'),
            TextColumn::make('status'),
            TextColumn::make('created_at')->date(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWithdrawalRequests::route('/'),
            'edit' => Pages\EditWithdrawalRequest::route('/{record}/edit'),
        ];
    }
}
