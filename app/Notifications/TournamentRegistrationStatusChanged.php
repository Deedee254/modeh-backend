<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\DatabaseMessage;

class TournamentRegistrationStatusChanged extends Notification
{
    use Queueable;

    protected $tournament;
    protected $status;

    public function __construct($tournament, string $status)
    {
        $this->tournament = $tournament;
        $this->status = $status;
    }

    public function via($notifiable)
    {
        return ['database'];
    }

    public function toDatabase($notifiable)
    {
        return [
            'message' => "Your registration for tournament '" . ($this->tournament->name ?? $this->tournament->id) . "' was {$this->status}.",
            'tournament_id' => $this->tournament->id ?? null,
            'status' => $this->status,
        ];
    }
}
