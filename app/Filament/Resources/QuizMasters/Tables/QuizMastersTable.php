<?php

namespace App\Filament\Resources\QuizMasters\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Table;

class QuizMastersTable
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
                \Filament\Tables\Columns\TextColumn::make('subjects')
                    ->formatStateUsing(fn ($state) => implode(', ', $state ?? []))
                    ->searchable()
                    ->wrap(),
                \Filament\Tables\Columns\TextColumn::make('phone')
                    ->searchable()
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
                \Filament\Tables\Filters\SelectFilter::make('subjects')
                    ->multiple()
                    ->options([
                        'Mathematics' => 'Mathematics',
                        'Physics' => 'Physics',
                        'Chemistry' => 'Chemistry',
                        'Biology' => 'Biology',
                        'English' => 'English',
                        'History' => 'History',
                        'Geography' => 'Geography',
                        'Computer Science' => 'Computer Science'
                    ]),
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
