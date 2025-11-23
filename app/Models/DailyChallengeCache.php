<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DailyChallengeCache extends Model
{
    use HasFactory;

    protected $table = 'daily_challenges_cache';

    protected $fillable = [
        'date',
        'level_id',
        'grade_id',
        'questions',
        'is_active',
    ];

    protected $casts = [
        'date' => 'date',
        'questions' => 'array',
        'is_active' => 'boolean',
    ];

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function submissions()
    {
        return $this->hasMany(DailyChallengeSubmission::class);
    }

    public function scopeToday($query)
    {
        return $query->whereDate('date', today());
    }

    public function scopeForLevel($query, $levelId)
    {
        return $query->where('level_id', $levelId);
    }

    public function scopeForGrade($query, $gradeId)
    {
        return $query->where('grade_id', $gradeId);
    }

    public function scopeForLevelAndGrade($query, $levelId, $gradeId)
    {
        return $query->where('level_id', $levelId)->where('grade_id', $gradeId);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
