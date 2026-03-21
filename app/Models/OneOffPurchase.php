<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * OneOffPurchase Model
 * 
 * Represents one-time purchases (not subscriptions)
 * Uses polymorphic relationships for item_type/item_id
 * 
 * Status lifecycle: pending → confirmed → completed
 * - pending: Initial state, awaiting payment
 * - confirmed: Payment received from gateway (M-PESA receipt confirmed)
 * - completed: Transaction records created and distributed
 * - failed: Payment failed or declined
 * - cancelled: User cancelled the payment
 * 
 * @property int $id
 * @property int|null $user_id
 * @property string|null $guest_identifier
 * @property string $item_type
 * @property int $item_id
 * @property float $amount
 * @property string $status (pending|confirmed|completed|failed|cancelled)
 * @property string $gateway (mpesa|stripe|etc)
 * @property array $gateway_meta
 * @property array $meta
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User $user
 */
class OneOffPurchase extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'guest_identifier', 'item_type', 'item_id', 'amount', 'status', 'gateway', 'gateway_meta', 'meta'];

    protected $casts = [
        'gateway_meta' => 'array',
        'meta' => 'array',
        'amount' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
