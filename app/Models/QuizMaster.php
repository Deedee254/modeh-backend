<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Institution;

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
        'subjectModels',  // Include subject models when converting to array
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
}
