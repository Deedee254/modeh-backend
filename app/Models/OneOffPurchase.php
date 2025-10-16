<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OneOffPurchase extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'item_type', 'item_id', 'amount', 'status', 'gateway', 'gateway_meta', 'meta'];

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
