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
     * Create an invoice with a unique invoice number, retrying if a race condition occurs.
     */
    public static function createWithUniqueNumber(array $attributes)
    {
        $attempts = 0;
        $maxAttempts = 5;

        while ($attempts < $maxAttempts) {
            try {
                $attributes['invoice_number'] = self::generateInvoiceNumber();
                return self::create($attributes);
            } catch (\Illuminate\Database\QueryException $e) {
                // Catch unique constraint violation (SQLSTATE 23000 / error code 1062)
                if ($e->getCode() == 23000 || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1062)) {
                    $attempts++;
                    if ($attempts >= $maxAttempts) {
                        throw $e;
                    }
                    usleep(50000); // Wait 50ms before retrying
                } else {
                    throw $e;
                }
            }
        }
    }

    /**
     * Generate next invoice number (e.g., INV-2026-0001), skipping existing ones
     */
    public static function generateInvoiceNumber()
    {
        $year = now()->year;
        $count = self::whereYear('created_at', $year)->count() + 1;
        
        while (self::where('invoice_number', sprintf('INV-%d-%04d', $year, $count))->exists()) {
            $count++;
        }

        return sprintf('INV-%d-%04d', $year, $count);
    }
}
