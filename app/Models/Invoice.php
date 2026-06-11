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
     * Create an invoice with atomically-generated unique invoice number.
     * Uses pessimistic locking to prevent race conditions.
     */
    public static function createWithUniqueNumber(array $attributes)
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($attributes) {
            $year = now()->year;
            
            // Lock all invoices for this year to guarantee atomicity
            // This prevents concurrent threads from generating the same invoice number
            $lastInvoice = self::where(\Illuminate\Support\Facades\DB::raw('YEAR(created_at)'), $year)
                ->lockForUpdate()
                ->orderByDesc('id')
                ->first();
            
            $nextCount = 1;
            if ($lastInvoice) {
                // Extract numeric suffix from last invoice (e.g., "INV-2026-0042" → 42)
                if (preg_match('/(\d{4})$/', $lastInvoice->invoice_number, $matches)) {
                    $nextCount = (int) $matches[1] + 1;
                }
            }
            
            $attributes['invoice_number'] = sprintf('INV-%d-%04d', $year, $nextCount);
            return self::create($attributes);
        });
    }

    /**
     * Generate next invoice number (deprecated - use createWithUniqueNumber instead)
     */
    public static function generateInvoiceNumber()
    {
        $year = now()->year;
        $lastInvoice = self::where(\Illuminate\Support\Facades\DB::raw('YEAR(created_at)'), $year)
            ->orderByDesc('id')
            ->first();
        
        $count = 1;
        if ($lastInvoice && preg_match('/(\d{4})$/', $lastInvoice->invoice_number, $matches)) {
            $count = (int) $matches[1] + 1;
        }

        return sprintf('INV-%d-%04d', $year, $count);
    }
}
