<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['tx_id','user_id','tutor_id','quiz_id','amount','tutor_share','platform_share','gateway','meta','status'];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
        'tutor_share' => 'decimal:2',
        'platform_share' => 'decimal:2',
    ];

    public function tutor()
    {
        return $this->belongsTo(User::class, 'tutor_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
