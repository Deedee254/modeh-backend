<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentSetting extends Model
{
    use HasFactory;

    protected $fillable = ['gateway','config','revenue_share'];

    protected $casts = [
        'config' => 'array',
        'revenue_share' => 'decimal:2',
    ];
}
