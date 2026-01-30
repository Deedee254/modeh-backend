<?php

namespace App\Notifications;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvoiceGeneratedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        protected Invoice $invoice,
    ) {
        $this->onQueue('default');
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $invoiceService = app(\App\Services\InvoiceService::class);
        
        $subject = match ($this->invoice->invoiceable_type) {
            'App\\Models\\Subscription' => 'Your Subscription Invoice - ' . $this->invoice->invoice_number,
            'App\\Models\\OneOffPurchase' => 'Your Purchase Invoice - ' . $this->invoice->invoice_number,
            default => 'Your Invoice - ' . $this->invoice->invoice_number,
        };

        $mail = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line('Your invoice has been generated and is attached below.')
            ->line('Invoice Number: ' . $this->invoice->invoice_number)
            ->line('Amount: KES ' . number_format($this->invoice->amount, 2))
            ->line('Status: ' . ucfirst($this->invoice->status));

        // Try to attach PDF if available
        try {
            $pdfPath = $invoiceService->generatePdf($this->invoice);
            if ($pdfPath && file_exists($pdfPath)) {
                $mail->attach($pdfPath, [
                    'as' => "invoice-{$this->invoice->invoice_number}.pdf",
                    'mime' => 'application/pdf',
                ]);
            }
        } catch (\Exception $e) {
            // Log error but don't fail the email
            \Log::warning("Failed to attach PDF to invoice notification", [
                'invoice_id' => $this->invoice->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $mail
            ->action('View Dashboard', config('app.frontend_url') . '/dashboard/transactions')
            ->line('Thank you for using Modeh!');
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'invoice_id' => $this->invoice->id,
            'invoice_number' => $this->invoice->invoice_number,
            'amount' => $this->invoice->amount,
            'status' => $this->invoice->status,
        ];
    }
}
