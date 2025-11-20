<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\QuizMaster;
use App\Models\Affiliate;
use App\Models\Quizee;
use App\Models\Institution;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $role User's role (quizee, quiz-master, etc.)
 * @property string|null $social_id
 * @property string|null $social_provider
 * @property string|null $social_avatar
 * @property string|null $social_token
 * @property string|null $social_refresh_token
 * @property string|null $avatar_url
 * @property string|null $bio
 * @property-read \App\Models\QuizMaster|null $quizMasterProfile
 * @property-read \App\Models\Quizee|null $quizeeProfile
 * @property string|null $social_expires_at
 * @property bool|null $is_profile_completed
 * @property \Carbon\Carbon|null $email_verified_at
 * @property string|null $remember_token
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\QuizMaster|null $quizMaster
 * @property-read \App\Models\Quizee|null $quizee
 * @property-read \App\Models\Affiliate|null $affiliate
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'social_id',
        'social_provider',
        'social_avatar',
        'social_token',
        'social_refresh_token',
        'social_expires_at',
        'is_profile_completed',
        'avatar_url',
        'bio',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    /**
     * The attributes that should be cast to native types.
     *
     * @var array<string,string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'social_expires_at' => 'datetime',
        'is_profile_completed' => 'boolean',
    ];

    /**
     * Attributes to append to the serialized model.
     * We expose affiliate_code for convenience on the frontend.
     *
     * @var array<int,string>
     */
    protected $appends = [
        'affiliate_code',
    ];

    /**
     * Helper to check admin role (used throughout the codebase).
     */
    public function isAdmin(): bool
    {
        return ($this->role ?? '') === 'admin';
    }

    /**
     * Filament contract: determine whether the user can access the given panel.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        // Only users with role 'admin' may access the Filament admin panel.
        return $this->isAdmin();
    }

    /**
     * Backwards-compatible accessor so `$user->is_admin` works.
     */
    public function getIsAdminAttribute(): bool
    {
        return $this->isAdmin();
    }

    public function quizMasterProfile()
    {
        return $this->hasOne(QuizMaster::class);
    }

    public function quizeeProfile()
    {
        return $this->hasOne(Quizee::class);
    }

    /**
     * Relation to the Affiliate record (if any) for this user.
     */
    public function affiliate()
    {
        return $this->hasOne(Affiliate::class);
    }

    /**
     * Convenience accessor: $user->affiliate_code
     */
    public function getAffiliateCodeAttribute()
    {
        return $this->affiliate ? $this->affiliate->referral_code : null;
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    /**
     * Institutions this user belongs to (membership/pivot stores role like institution-manager)
     */
    public function institutions()
    {
        return $this->belongsToMany(Institution::class, 'institution_user')
            ->withPivot('role', 'status', 'invited_by')
            ->withTimestamps();
    }

    public function quizAttempts()
    {
        return $this->hasMany(\App\Models\QuizAttempt::class);
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withTimestamps()
            ->withPivot('progress', 'completed_at', 'attempt_id');
    }

    /**
     * Assignments from subscriptions granted to this user.
     */
    public function subscriptionAssignments()
    {
        return $this->hasMany(\App\Models\SubscriptionAssignment::class);
    }
}
