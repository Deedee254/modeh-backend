<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class quizee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'dob',
        'profile',
        'grade_id',
        'points',
        'current_streak',
        'longest_streak',
        'subject_progress',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function badges()
    {
        return $this->belongsToMany(Badge::class, 'user_badges')->withPivot('earned_at', 'attempt_id')->withTimestamps();
    }

    public function battles()
    {
        return $this->hasMany(Battle::class, 'initiator_id')->orWhere('opponent_id', $this->id);
    }

    public function dailyChallenges()
    {
        return $this->belongsToMany(DailyChallenge::class, 'user_daily_challenges')->withPivot('completed_at')->withTimestamps();
    }
}
