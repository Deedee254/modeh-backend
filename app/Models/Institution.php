<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'email',
        'phone',
        'logo_url',
        'website',
        'address',
        'metadata',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class, 'institution_user')
            ->withPivot('role', 'status', 'invited_by')
            ->withTimestamps();
    }

    public function quizMasters()
    {
        return $this->hasMany(QuizMaster::class);
    }

    public function quizees()
    {
        return $this->hasMany(Quizee::class);
    }

    /**
     * QuizMasters that have the institution name/slug in their profile `institution` string.
     * This supports automatic membership based on profile text (legacy field).
     */
    public function profileQuizMasters()
    {
        return QuizMaster::where('institution', $this->name)->orWhere('institution', $this->slug);
    }

    /**
     * Combined set of quiz masters: those explicitly linked by institution_id and those whose profile string matches.
     */
    public function allQuizMasters()
    {
        $explicit = $this->quizMasters()->get();
        $profile = $this->profileQuizMasters()->get();
        return $explicit->merge($profile)->unique('id')->values();
    }

    public function profileQuizees()
    {
        return Quizee::where('institution', $this->name)->orWhere('institution', $this->slug);
    }

    public function allQuizees()
    {
        $explicit = $this->quizees()->get();
        $profile = $this->profileQuizees()->get();
        return $explicit->merge($profile)->unique('id')->values();
    }

    /**
     * Users who have signalled interest by setting their profile institution to this institution
     * and are NOT yet members in the pivot table. Returns a collection of User models.
     */
    public function pendingRequests()
    {
        // collect user ids from profile-matched quizmasters and quizees
        $qm = $this->profileQuizMasters()->pluck('user_id')->filter()->unique()->values()->toArray();
        $qz = $this->profileQuizees()->pluck('user_id')->filter()->unique()->values()->toArray();
        $userIds = array_values(array_unique(array_merge($qm, $qz)));

        if (empty($userIds)) return collect([]);

        // exclude those already in pivot
        $existing = \DB::table('institution_user')->where('institution_id', $this->id)->whereIn('user_id', $userIds)->pluck('user_id')->toArray();
        $pendingIds = array_values(array_diff($userIds, $existing));
        return \App\Models\User::whereIn('id', $pendingIds)->get();
    }

    public function subjects()
    {
        return $this->hasMany(Subject::class);
    }

    public function topics()
    {
        return $this->hasMany(Topic::class);
    }

    public function subscriptions()
    {
        return $this->morphMany(Subscription::class, 'owner');
    }
}
