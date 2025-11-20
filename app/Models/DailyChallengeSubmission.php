<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyChallengeSubmission extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'daily_challenge_cache_id',
        'answers',
        'score',
        'is_correct',
        'time_taken',
        'completed_at',
    ];

    protected $casts = [
        'answers' => 'array',
        'is_correct' => 'array',
        'completed_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function cache()
    {
        return $this->belongsTo(DailyChallengeCache::class, 'daily_challenge_cache_id');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('completed_at', today());
    }
}
