<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InstitutionInvitationEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(
        public $institution,
        public $email,
        public $token,
        public $expiresAt,
        public $invitedBy,
        public $ftoken = null
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join {$this->institution->name} on Modeh",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $frontend = env('FRONTEND_URL', config('app.url'));
        $inviteUrl = $frontend . '/email-verified?invite=' . $this->token;
        if ($this->ftoken) {
            $inviteUrl .= '&ftoken=' . $this->ftoken;
        }
        
        return new Content(
            markdown: 'emails.institution-invite',
            with: [
                'institution' => $this->institution,
                'email' => $this->email,
                'inviteUrl' => $inviteUrl,
                'expiresAt' => $this->expiresAt,
                'invitedBy' => $this->invitedBy,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
