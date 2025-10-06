<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UserOnboarding extends Model
{
    // Migration creates the table named `user_onboarding` (singular).
    // Eloquent would otherwise look for `user_onboardings` (plural).
    protected $table = 'user_onboarding';
    protected $fillable = [
        'user_id',
        'profile_completed',
        'institution_added',
        'role_selected',
        'subject_selected',
        'grade_selected',
        'completed_steps',
        'last_step_completed_at'
    ];

    protected $casts = [
        'profile_completed' => 'boolean',
        'institution_added' => 'boolean',
        'role_selected' => 'boolean',
        'subject_selected' => 'boolean',
        'grade_selected' => 'boolean',
        'completed_steps' => 'array',
        'last_step_completed_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
