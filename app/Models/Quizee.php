<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Institution;

class Quizee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'dob',
        'profile',
        'grade_id',
        'level_id',
        'points',
        'current_streak',
        'longest_streak',
        'subject_progress',
        'institution',
        'subjects',
    ];

    protected $casts = [
        'subjects' => 'array',
        'subject_progress' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    /**
     * Avatar accessor fallback — return profile or from related user if available.
     */
    public function getAvatarAttribute()
    {
        if (!empty($this->profile)) return $this->profile;
        if ($this->relationLoaded('user') && $this->user && !empty($this->user->avatar)) return $this->user->avatar;
        return null;
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
