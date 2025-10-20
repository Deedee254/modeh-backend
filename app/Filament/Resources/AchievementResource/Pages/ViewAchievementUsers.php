<?php

namespace App\Filament\Resources\AchievementResource\Pages;

use App\Filament\Resources\AchievementResource;
use Filament\Resources\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Filament\Tables;
use Illuminate\Database\Eloquent\Builder;

class ViewAchievementUsers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = AchievementResource::class;

    protected string $view = 'filament.resources.achievement-resource.pages.view-achievement-users';

    public function table(Table $table): Table
    {
        return $table
            ->query(
                $this->getRecord()->users()->getQuery()
            )
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.completed_at')
                    ->label('Awarded At')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.attempt_id')
                    ->label('Quiz Attempt')
                    ->url(fn ($record) => $record->pivot->attempt_id 
                        ? route('filament.resources.quiz-attempts.view', ['record' => $record->pivot->attempt_id])
                        : null
                    ),
            ])
            ->filters([
                Tables\Filters\Filter::make('awarded_at')
                    ->form([
                        Forms\Components\DatePicker::make('awarded_from')
                            ->label('Awarded From'),
                        Forms\Components\DatePicker::make('awarded_until')
                            ->label('Awarded Until'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['awarded_from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('user_achievements.completed_at', '>=', $date),
                            )
                            ->when(
                                $data['awarded_until'],
                                fn (Builder $query, $date): Builder => $query->whereDate('user_achievements.completed_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('revoke')
                    ->label('Revoke')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->action(function ($record) {
                        // Detach the achievement from the user
                        $record->achievements()->detach($this->getRecord()->id);
                        
                        // Remove the points that were awarded
                        $record->decrement('points', $this->getRecord()->points);
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('revoke')
                    ->label('Revoke Selected')
                    ->icon('heroicon-o-x-mark')
                    ->requiresConfirmation()
                    ->action(function ($records) {
                        foreach ($records as $user) {
                            // Detach the achievement from the user
                            $user->achievements()->detach($this->getRecord()->id);
                            
                            // Remove the points that were awarded
                            $user->decrement('points', $this->getRecord()->points);
                        }
                    }),
            ]);
    }

    public function getRecord(): Achievement
    {
        return static::$resource::getModel()::find($this->record);
    }
}