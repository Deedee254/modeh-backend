<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\QuizMaster;
use App\Models\Affiliate;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

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
        'social_id',
        'social_provider',
        'social_avatar',
        'social_token',
        'social_refresh_token',
        'social_expires_at',
        'is_profile_completed',
        // Allow role to be mass assigned when creating/updating users
        'role',
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
}
