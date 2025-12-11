<?php

namespace App\Filament\Resources\UserOnboardings\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;

class UserOnboardingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
                ->columns([
                    TextColumn::make('user.name')->label('User')->searchable()->sortable(),
                    TextColumn::make('user.email')->label('Email')->searchable(),
                    BadgeColumn::make('profile_completed')
                        ->label('Profile')
                        ->formatStateUsing(fn ($state) => $state ? 'Complete' : 'Incomplete')
                        ->colors([
                            'danger' => 0,
                            'success' => 1,
                        ]),
                    BadgeColumn::make('institution_added')
                        ->label('Institution')
                        ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No')
                        ->colors([
                            'danger' => 0,
                            'success' => 1,
                        ]),
                    TextColumn::make('completed_steps')
                        ->label('Completed Steps')
                        ->formatStateUsing(fn ($state) => is_array($state) ? count($state) : ($state ? 1 : 0)),
                    TextColumn::make('last_step_completed_at')->label('Last Step')->dateTime(),
                    TextColumn::make('created_at')->label('Created')->dateTime(),
                ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
