<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Institution;

/**
 * Quizee model - represents quiz taker profile
 * 
 * @property int $id
 * @property int $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $dob
 * @property string|null $profile
 * @property int|null $grade_id
 * @property int|null $level_id
 * @property int|null $institution_id
 * @property int $points
 * @property int $current_streak
 * @property int $longest_streak
 * @property array|null $subject_progress
 * @property string|null $institution
 * @property array|null $subjects
 * @property bool $institution_verified
 * @property int|null $verified_institution_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Institution|null $institutionModel
 * @property-read \App\Models\Level|null $level
 * @property-read \App\Models\Grade|null $grade
 */
class Quizee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'dob',
        'profile', // also referred to as 'bio' in some APIs
        'grade_id',
        'level_id',
        'institution_id',  // NEW: Foreign key to institutions table
        'points',
        'current_streak',
        'longest_streak',
        'subject_progress',
        'institution',  // KEEP: Text field for user input or legacy data
        'subjects',
        'institution_verified',
        'verified_institution_id',
    ];

    protected $casts = [
        'subjects' => 'array',
        'subject_progress' => 'array',
    ];

    protected $appends = [
        'bio',  // Alias profile column as bio in API responses for consistency with QuizMaster
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

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    /**
     * Get the full Subject models for the subject IDs stored in the 'subjects' JSON array.
     * Backwards-compatibility accessor: subjectModels
     * Note: We DO NOT mutate the 'subjects' field - it stays as array of IDs.
     * Full objects are accessed via 'subjectModels' accessor instead.
     */
    public function getSubjectModelsAttribute()
    {
        return Subject::whereIn('id', $this->subjects ?? [])->get();
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

    /**
     * Bio accessor — return profile field for API consistency
     * (The 'profile' field in Quizee is aliased as 'bio' in API responses)
     */
    public function getBioAttribute()
    {
        return $this->profile;
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

    public function parents()
    {
        return $this->belongsToMany(Parent::class, 'parent_student')
            ->withPivot('student_invitation_id', 'package_assignment', 'connected_at')
            ->withTimestamps();
    }
}
