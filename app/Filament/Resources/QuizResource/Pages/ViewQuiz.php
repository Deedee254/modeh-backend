<?php

namespace App\Filament\Resources\QuizResource\Pages;

use App\Filament\Resources\QuizResource;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

class ViewQuiz extends ViewRecord
{
    protected static string $resource = QuizResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Simple Save button: save the current inline form state stored in $this->data
            \Filament\Actions\Action::make('save')
                ->label('Save')
                ->color('primary')
                ->requiresConfirmation(false)
                ->button()
                ->visible(fn () => true)
                ->action(function (): void {
                    $this->record->update($this->data ?? []);
                    // Use refresh to reload the page data; do not call notify() here
                    $this->refresh();
                }),

            \Filament\Actions\EditAction::make(),
        ];
    }

    /**
     * Return the resource form configured for the current record and enabled
     * so fields are editable directly on the view page.
     */
    public function form(Schema $schema): Schema
    {
        return static::getResource()::form($schema)
            ->columns($this->hasInlineLabels() ? 1 : 2)
            ->model($this->getRecord())
            ->operation('edit')
            ->statePath('data')
            ->disabled(false);
    }
}