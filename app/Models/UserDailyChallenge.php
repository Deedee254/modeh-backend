<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserDailyChallenge extends Model
{
    use HasFactory;

    protected $table = 'user_daily_challenges';

    protected $fillable = [
        'user_id',
        'daily_challenge_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function quizee()
    {
        return $this->belongsTo(quizee::class, 'user_id');
    }

    public function dailyChallenge()
    {
        return $this->belongsTo(DailyChallenge::class);
    }
}