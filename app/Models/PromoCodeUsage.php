<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCodeUsage extends Model
{
    use HasFactory;

    protected $fillable = [
        'promo_code_id',
        'user_id',
        'purchasable_type',
        'purchasable_id',
        'discount_applied',
    ];

    protected $casts = [
        'discount_applied' => 'decimal:2',
    ];

    public function promoCode()
    {
        return $this->belongsTo(PromoCode::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function purchasable()
    {
        return $this->morphTo();
    }
}
