<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyChallenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'difficulty', // easy, medium, hard
        'grade_id',
        'subject_id',
        'points_reward',
        'date',
        'is_active',
    ];

    protected $casts = [
        'date' => 'date',
        'is_active' => 'boolean',
    ];

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class);
    }

    public function quizees()
    {
        return $this->belongsToMany(quizee::class, 'user_daily_challenges')->withPivot('completed_at')->withTimestamps();
    }
}