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
        'institution',
    ];

    protected $casts = [
        'subjects' => 'array',
    ];

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
