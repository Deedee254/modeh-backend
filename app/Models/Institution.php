<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Institution model
 * 
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int|null $parent_id
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $logo_url
 * @property string|null $website
 * @property string|null $address
 * @property array|null $metadata
 * @property bool $is_active
 * @property int|null $created_by
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
class Institution extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'parent_id',
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
            ->withPivot('role', 'status', 'invited_by', 'invitation_token', 'invitation_expires_at', 'invitation_status', 'invited_email', 'last_activity_at')
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
        // Match either explicit foreign key (institution_id) or the legacy/text field
        return QuizMaster::where(function($q) {
            $q->where('institution_id', $this->id)
              ->orWhere('institution', $this->name)
              ->orWhere('institution', $this->slug);
        });
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
        // Match either explicit foreign key (institution_id) or the legacy/text field
        return Quizee::where(function($q) {
            $q->where('institution_id', $this->id)
              ->orWhere('institution', $this->name)
              ->orWhere('institution', $this->slug);
        });
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

    public function parent()
    {
        return $this->belongsTo(Institution::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Institution::class, 'parent_id');
    }

    /**
     * Use slug for route-model binding so controllers can accept institution slugs in URLs.
     */
    public function getRouteKeyName()
    {
        return 'slug';
    }

    /**
     * Get the active subscription for this institution.
     */
    public function activeSubscription()
    {
        return Subscription::where('owner_type', self::class)
            ->where('owner_id', $this->id)
            ->where('status', 'active')
            ->orderByDesc('started_at')
            ->first();
    }

    /**
     * Get all quiz attempts from institution members.
     */
    public function quizAttempts()
    {
        return QuizAttempt::whereIn('user_id', $this->users()->pluck('users.id'));
    }

    /**
     * Approval requests for this institution
     */
    public function approvalRequests()
    {
        return $this->hasMany(\App\Models\InstitutionApprovalRequest::class, 'institution_name', 'name');
    }
}
