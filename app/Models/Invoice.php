<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Invoice Model
 * 
 * Represents invoices for transactions (subscriptions, one-off purchases, etc.)
 * Uses polymorphic relationships to support different invoiceable types
 * 
 * @property int $id
 * @property int $user_id
 * @property string $invoice_number
 * @property string $invoiceable_type
 * @property int $invoiceable_id
 * @property float $amount
 * @property string $currency
 * @property string $description
 * @property string $status (pending|paid|overdue|cancelled)
 * @property string $payment_method
 * @property string $transaction_id
 * @property \Illuminate\Support\Carbon|null $paid_at
 * @property \Illuminate\Support\Carbon|null $due_at
 * @property array $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Model $invoiceable
 */
class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_number',
        'user_id',
        'invoiceable_type',
        'invoiceable_id',
        'amount',
        'currency',
        'description',
        'status',
        'payment_method',
        'transaction_id',
        'paid_at',
        'due_at',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'paid_at' => 'datetime',
        'due_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function invoiceable()
    {
        return $this->morphTo();
    }

    /**
     * Mark invoice as paid
     */
    public function markAsPaid($transactionId = null, $paymentMethod = null)
    {
        $this->update([
            'status' => 'paid',
            'paid_at' => now(),
            'transaction_id' => $transactionId,
            'payment_method' => $paymentMethod,
        ]);
        return $this;
    }

    /**
     * Generate next invoice number (e.g., INV-2026-0001)
     */
    public static function generateInvoiceNumber()
    {
        $year = now()->year;
        $count = self::whereYear('created_at', $year)->count() + 1;
        return sprintf('INV-%d-%04d', $year, $count);
    }
}
