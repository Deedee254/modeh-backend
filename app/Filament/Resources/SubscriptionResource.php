<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubscriptionResource\Pages;
use App\Models\Subscription;
use Filament\Resources\Resource;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;

class SubscriptionResource extends Resource
{
    protected static ?string $model = Subscription::class;

    public static function schema(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')->relationship('user', 'name')->required(),
            Select::make('package_id')->relationship('package', 'title')->required(),
            Select::make('status')->options([
                'pending' => 'Pending',
                'active' => 'Active',
                'cancelled' => 'Cancelled',
            ])->required(),
            DateTimePicker::make('started_at'),
            DateTimePicker::make('ends_at'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('user.name')->label('User'),
            TextColumn::make('package.title')->label('Package'),
            BadgeColumn::make('status')->colors([
                'primary' => 'pending',
                'success' => 'active',
                'danger' => 'cancelled',
            ]),
            TextColumn::make('started_at')->dateTime(),
            TextColumn::make('ends_at')->dateTime(),
        ])->actions([
            Action::make('activate')
                ->label('Activate')
                ->requiresConfirmation()
                ->color('success')
                ->action(function (Subscription $record, array $data = []) {
                    // set started_at to now and ends_at from package duration_days or default 30
                    $package = $record->package;
                    $days = $package->duration_days ?? 30;
                    $record->status = 'active';
                    $record->started_at = now();
                    $record->ends_at = now()->addDays($days);
                    $record->save();
                })
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscriptions::route('/'),
            'edit' => Pages\EditSubscription::route('/{record}/edit'),
        ];
    }
}
