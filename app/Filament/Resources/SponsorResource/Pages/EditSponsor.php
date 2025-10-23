<?php

namespace App\Filament\Resources\SponsorResource\Pages;

use App\Filament\Resources\SponsorResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditSponsor extends EditRecord
{
    protected static string $resource = SponsorResource::class;

    protected function getActions(): array
    {
        return [
            DeleteAction::make()
                ->before(function () {
                    if ($this->record->tournaments()->count() > 0) {
                        Notification::make()
                            ->warning()
                            ->title('Cannot delete sponsor')
                            ->body('This sponsor has tournaments associated with it.')
                            ->persistent()
                            ->send();

                        return false;
                    }
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}