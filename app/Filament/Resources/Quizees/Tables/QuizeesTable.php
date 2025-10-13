<?php

namespace App\Filament\Resources\Quizees\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

class QuizeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                \Filament\Tables\Columns\TextColumn::make('user.name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('user.email')
                    ->label('Email')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('institution')
                    ->searchable()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('grade')
                    ->label('Grade Level')
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('user.social_provider')
                    ->label('Login Method')
                    ->formatStateUsing(fn ($state) => $state ? ucfirst($state) : 'Email')
                    ->sortable(),
                \Filament\Tables\Columns\IconColumn::make('user.is_profile_completed')
                    ->label('Profile Complete')
                    ->boolean()
                    ->sortable(),
                \Filament\Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                \Filament\Tables\Filters\SelectFilter::make('grade')
                    ->options(array_combine(range(1, 12), range(1, 12))),
                \Filament\Tables\Filters\SelectFilter::make('social_provider')
                    ->options([
                        'google' => 'Google',
                        null => 'Email',
                    ]),
                \Filament\Tables\Filters\TernaryFilter::make('is_profile_completed')
                    ->label('Profile Status')
                    ->placeholder('All Profiles')
                    ->trueLabel('Completed')
                    ->falseLabel('Incomplete'),
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
