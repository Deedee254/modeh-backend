<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = ['quiz-master_id','amount','method','status','meta'];

    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
    ];

    public function quiz-master()
    {
        return $this->belongsTo(User::class, 'quiz-master_id');
    }
}
