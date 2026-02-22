<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GuestUnlockToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'token',
        'guest_identifier',
        'item_type',
        'item_id',
        'purchase_id',
        'guest_attempt_id',
        'expires_at',
        'used_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(OneOffPurchase::class, 'purchase_id');
    }
}

