<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\QuizMaster;

class User extends Authenticatable
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
     * Helper to check admin role (used throughout the codebase).
     */
    public function isAdmin(): bool
    {
        return ($this->role ?? '') === 'admin';
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

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function achievements()
    {
        return $this->belongsToMany(Achievement::class, 'user_achievements')
            ->withTimestamps()
            ->withPivot('progress', 'completed_at', 'attempt_id');
    }
}
