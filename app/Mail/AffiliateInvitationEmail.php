<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AffiliateInvitationEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public $inviter, public $email, public $referralCode)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "You're invited to join Modeh",
        );
    }

    public function content(): Content
    {
        $frontend = env('FRONTEND_URL', config('app.url'));
        $inviteUrl = $frontend . '/register?ref=' . urlencode($this->referralCode);

        return new Content(
            markdown: 'emails.affiliate-invite',
            with: [
                'inviter' => $this->inviter,
                'email' => $this->email,
                'inviteUrl' => $inviteUrl,
                'referralCode' => $this->referralCode,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
