<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Institution;
use App\Relations\ArrayRelation;

/**
 * QuizMaster model - represents quiz creator profile
 * 
 * @property int $id
 * @property int $user_id
 * @property string|null $first_name
 * @property string|null $last_name
 * @property string|null $headline
 * @property array|null $subjects
 * @property string|null $bio
 * @property int|null $grade_id
 * @property int|null $level_id
 * @property int|null $institution_id
 * @property string|null $institution
 * @property bool $institution_verified
 * @property int|null $verified_institution_id
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 * @property-read \App\Models\User|null $user
 * @property-read \App\Models\Institution|null $institutionModel
 * @property-read \App\Models\Level|null $level
 * @property-read \App\Models\Grade|null $grade
 */
class QuizMaster extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'headline',
        'subjects',
        'bio',
        'grade_id',
        'level_id',
        'institution_id',  // NEW: Foreign key to institutions table
        'institution',  // KEEP: Text field for user input or legacy data
        'institution_verified',
        'verified_institution_id',
    ];

    protected $casts = [
        'subjects' => 'array',
    ];

    protected $appends = [
        // Expose subjectModels accessor so frontend gets full Subject objects
        // and can rely on a single `profile` payload shape.
        'subjectModels',
    ];

    /**
     * Get the full name by concatenating first_name and last_name.
     * This accessor provides a 'name' attribute that Filament expects.
     */
    public function getNameAttribute()
    {
        $firstName = $this->first_name ?? '';
        $lastName = $this->last_name ?? '';
        return trim("{$firstName} {$lastName}") ?: 'Unknown';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class, 'user_id', 'user_id');
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function level()
    {
        return $this->belongsTo(Level::class);
    }

    public function getSubjectsModelsAttribute()
    {
        return Subject::whereIn('id', $this->subjects ?? [])->get();
    }

    // Backwards-compatibility: controller expects $profile->subjectModels (camelCase, singular)
    public function getSubjectModelsAttribute()
    {
        return $this->getSubjectsModelsAttribute();
    }

    /**
     * Relation-like wrapper so `subjectModels` can be eager-loaded safely.
     */
    public function subjectModels()
    {
        return new ArrayRelation(Subject::query(), $this, 'subjects');
    }
}
