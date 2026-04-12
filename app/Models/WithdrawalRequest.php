<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class WithdrawalRequest extends Model
{
    use HasFactory;

    protected $fillable = ['quiz_master_id','amount','method','status','meta','processed_by_admin_id'];


    protected $casts = [
        'meta' => 'array',
        'amount' => 'decimal:2',
    ];

    protected $dates = ['paid_at'];


    public function quizMaster()
    {
        return $this->belongsTo(User::class, 'quiz_master_id');
    }
    
}
