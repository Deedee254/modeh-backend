<?php

namespace App\Filament\Resources\InstitutionResource\Pages;

use App\Models\Institution;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\Http;

class InvitationAcceptance extends Page
{
    protected string $view = 'filament.resources.institution-resource.pages.invitation-acceptance';

    protected static bool $shouldRegisterNavigation = false;

    public ?string $token = null;
    public ?array $invitationData = null;
    public bool $isLoading = true;
    public bool $isProcessing = false;
    public string $error = '';

    public function mount(?string $token = null): void
    {
        $this->token = $token;
        if ($this->token) {
            $this->loadInvitationDetails();
        }
    }

    public function loadInvitationDetails(): void
    {
        try {
            $response = Http::get(route('api.invitation.details', $this->token));

            if ($response->successful()) {
                $this->invitationData = $response->json('data');
            } else {
                $this->error = $response->json('message') ?? 'Invalid or expired invitation link.';
            }
        } catch (\Exception $e) {
            $this->error = 'An error occurred while loading your invitation. Please try again.';
        } finally {
            $this->isLoading = false;
        }
    }

    public function acceptInvitation(): void
    {
        if (!$this->token || !auth()->check()) {
            $this->error = 'You must be logged in to accept an invitation.';
            return;
        }

        try {
            $this->isProcessing = true;

            $response = Http::withToken(auth()->user()->currentAccessToken()->plainTextToken)
                ->post(route('api.invitation.accept', [$this->invitationData['institution']['id'], $this->token]));

            if ($response->successful()) {
                \Filament\Notifications\Notification::make()
                    ->title('Invitation Accepted')
                    ->body('You have successfully joined ' . $this->invitationData['institution']['name'])
                    ->success()
                    ->send();

                redirect()->route('filament.admin.dashboard');
            } else {
                $this->error = $response->json('message') ?? 'Failed to accept invitation.';
            }
        } catch (\Exception $e) {
            $this->error = 'An error occurred. Please try again.';
        } finally {
            $this->isProcessing = false;
        }
    }

    public function declineInvitation(): void
    {
        session(['declined_invitation_' . $this->token => true]);
        $this->error = 'You have declined the invitation.';
        redirect()->route('filament.admin.dashboard');
    }

    public function getHeading(): string
    {
        return 'Invitation';
    }
}
