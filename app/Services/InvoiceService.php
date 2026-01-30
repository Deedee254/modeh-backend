<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use Illuminate\Support\Facades\Log;

class InvoiceService
{
    /**
     * Create an invoice for a subscription payment
     */
    public function createForSubscription(Subscription $sub, ?string $description = null): Invoice
    {
        $invoice = Invoice::create([
            'invoice_number' => Invoice::generateInvoiceNumber(),
            'user_id' => $sub->user_id,
            'invoiceable_type' => Subscription::class,
            'invoiceable_id' => $sub->id,
            'amount' => $sub->package->price ?? 0,
            'currency' => $sub->package->currency ?? 'KES',
            'description' => $description ?? ($sub->package->name . ' Subscription'),
            'status' => 'pending',
            'due_at' => now()->addDays(30),
            'meta' => [
                'package_id' => $sub->package_id,
                'package_name' => $sub->package->name,
                'duration_days' => $sub->package->duration_days ?? 30,
                'subscription_id' => $sub->id,
            ],
        ]);

        Log::info('[Invoice] Created for subscription', [
            'invoice_id' => $invoice->id,
            'subscription_id' => $sub->id,
            'user_id' => $sub->user_id,
            'amount' => $invoice->amount,
        ]);

        return $invoice;
    }

    /**
     * Mark invoice as paid (called after successful payment callback)
     */
    public function markAsPaid(Invoice $invoice, ?string $txId = null, string $paymentMethod = 'mpesa'): Invoice
    {
        $invoice->markAsPaid($txId, $paymentMethod);

        Log::info('[Invoice] Marked as paid', [
            'invoice_id' => $invoice->id,
            'tx_id' => $txId,
            'payment_method' => $paymentMethod,
        ]);

        return $invoice;
    }

    /**
     * Generate HTML for invoice (can be converted to PDF)
     */
    public function generateHtml(Invoice $invoice): string
    {
        $company = config('app.name');
        $invoiceableModel = $invoice->invoiceable;
        
        return view('invoices.template', [
            'invoice' => $invoice,
            'company' => $company,
            'user' => $invoice->user,
            'invoiceable' => $invoiceableModel,
        ])->render();
    }

    /**
     * Generate PDF invoice (requires barryvdh/laravel-dompdf)
     * Install with: composer require barryvdh/laravel-dompdf
     */
    public function generatePdf(Invoice $invoice)
    {
        $html = $this->generateHtml($invoice);
        
        // If DomPDF is not installed, log warning and return null
        if (!class_exists('Barryvdh\DomPDF\Facade\Pdf')) {
            Log::warning('[Invoice] DomPDF not installed, returning null');
            return null;
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html);
        return $pdf->download("invoice-{$invoice->invoice_number}.pdf");
    }
}
