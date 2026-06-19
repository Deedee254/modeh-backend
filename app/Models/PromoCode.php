<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_amount',
        'max_uses_overall',
        'max_uses_per_user',
        'uses',
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected $casts = [
        'valid_from' => 'datetime',
        'valid_until' => 'datetime',
        'is_active' => 'boolean',
        'discount_amount' => 'decimal:2',
    ];

    public function usages()
    {
        return $this->hasMany(PromoCodeUsage::class);
    }
    
    public function isValid()
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->valid_from && $this->valid_from->isFuture()) {
            return false;
        }

        if ($this->valid_until && $this->valid_until->isPast()) {
            return false;
        }

        if ($this->max_uses_overall !== null && $this->uses >= $this->max_uses_overall) {
            return false;
        }

        return true;
    }

    public function calculateDiscount(float $amount)
    {
        if ($this->discount_type === 'fixed') {
            return min($amount, $this->discount_amount);
        }

        if ($this->discount_type === 'percentage') {
            return ($amount * $this->discount_amount) / 100;
        }

        return 0;
    }
}
