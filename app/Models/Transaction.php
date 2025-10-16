<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = ['tx_id','user_id','quiz_master_id','quiz_id','amount','quiz_master_share','platform_share','gateway','meta','status'];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
    'quiz_master_share' => 'decimal:2',
        'platform_share' => 'decimal:2',
    ];

    public function quizMaster()
    {
        return $this->belongsTo(User::class, 'quiz_master_id');
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
