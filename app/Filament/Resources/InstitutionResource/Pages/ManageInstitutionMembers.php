<?php

namespace App\Filament\Resources\InstitutionResource\Pages;

use App\Filament\Resources\InstitutionResource;
use App\Models\Institution;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Auth;

class ManageInstitutionMembers extends Page
{
    protected static string $resource = InstitutionResource::class;

    protected string $view = 'filament.resources.institution-resource.pages.manage-institution-members';

    public Institution $record;
    public array $members = [];
    public bool $isLoading = true;

    public function mount($record): void
    {
        $this->record = $record;
        $this->authorize('view', $this->record);
        $this->loadMembers();
    }

    public function loadMembers(): void
    {
        try {
            $token = $this->getCurrentUserToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->get(route('api.institution-members.index', $this->record));

            if ($response->successful()) {
                $this->members = $response->json('data') ?? [];
            } else {
                throw new \Exception($response->json('message') ?? 'Failed to load members');
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isLoading = false;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('invite')
                ->label('Invite Member')
                ->icon('heroicon-o-envelope')
                ->schema([
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->label('Member Email'),
                    Forms\Components\Select::make('subscription_tier')
                        ->options([
                            'standard' => 'Standard',
                            'premium' => 'Premium',
                            'enterprise' => 'Enterprise',
                        ])
                        ->required(),
                ])
                ->action(function (array $data) {
                    $this->inviteMember($data);
                }),
        ];
    }

    public function inviteMember(array $data): void
    {
        try {
            $token = $this->getCurrentUserToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->post(route('api.institution-members.invite', $this->record), $data);

            if ($response->successful()) {
                \Filament\Notifications\Notification::make()
                    ->title('Member Invited')
                    ->body('Invitation sent to ' . $data['email'])
                    ->success()
                    ->send();
                $this->loadMembers();
            } else {
                \Filament\Notifications\Notification::make()
                    ->title('Error')
                    ->body($response->json('message') ?? 'Failed to send invitation')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function removeMember(int $userId): void
    {
        try {
            $token = $this->getCurrentUserToken();
            $response = \Illuminate\Support\Facades\Http::withToken($token)
                ->delete(route('api.institution-members.remove', [$this->record, $userId]));

            if ($response->successful()) {
                \Filament\Notifications\Notification::make()
                    ->title('Member Removed')
                    ->success()
                    ->send();
                $this->loadMembers();
            } else {
                \Filament\Notifications\Notification::make()
                    ->title('Error')
                    ->body($response->json('message') ?? 'Failed to remove member')
                    ->danger()
                    ->send();
            }
        } catch (\Exception $e) {
            \Filament\Notifications\Notification::make()
                ->title('Error')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    /**
     * Retrieve the current authenticated user's plain text API token.
     *
     * @return string
     *
     * @throws \Exception When the user isn't authenticated or token is missing.
     */
    private function getCurrentUserToken(): string
    {
        /** @var \App\Models\User|null $user */
        $user = Auth::user();
        if (! $user) {
            throw new \Exception('User not authenticated.');
        }
        $token = $user->currentAccessToken();
        if (! $token) {
            throw new \Exception('No current access token available.');
        }
        return $token->plainTextToken;
    }

    public function getHeading(): string
    {
        return 'Manage Members: ' . $this->record->name;
    }
}
