<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Invitation Model
 * 
 * Represents user referral/signup invitations with token-based validation
 * Supports both email-based and authenticated invitations
 * 
 * @property int $id
 * @property int|null $inviter_id User who sent the invitation (nullable for system invitations)
 * @property string $token Unique invitation token (URL-safe)
 * @property string $email Email address being invited
 * @property string $status Status of invitation: 'pending', 'accepted', 'expired', 'revoked'
 * @property \DateTime|null $accepted_at When the invitation was accepted
 * @property int|null $accepted_by_user_id The user ID that accepted this invitation
 * @property \DateTime $expires_at When the invitation expires
 * @property array|null $metadata Additional metadata (perks, referral rewards, etc)
 * @property \DateTime $created_at
 * @property \DateTime $updated_at
 */
class Invitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'inviter_id',
        'token',
        'email',
        'status',
        'accepted_at',
        'accepted_by_user_id',
        'expires_at',
        'metadata',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            // Generate unique invitation token if not set
            if (!$model->token) {
                $model->token = \Illuminate\Support\Str::random(32);
            }
            
            // Set default expiration to 30 days from now if not set
            if (!$model->expires_at) {
                $model->expires_at = now()->addDays(30);
            }
            
            // Set default status to 'pending' if not set
            if (!$model->status) {
                $model->status = 'pending';
            }
        });
    }

    /**
     * Get the inviter user
     */
    public function inviter()
    {
        return $this->belongsTo(User::class, 'inviter_id');
    }

    /**
     * Get the user who accepted this invitation
     */
    public function acceptedByUser()
    {
        return $this->belongsTo(User::class, 'accepted_by_user_id');
    }

    /**
     * Check if invitation is still valid
     */
    public function isValid(): bool
    {
        return $this->status === 'pending' && now()->isBefore($this->expires_at);
    }

    /**
     * Check if invitation is expired
     */
    public function isExpired(): bool
    {
        return now()->isAfter($this->expires_at);
    }

    /**
     * Accept the invitation
     */
    public function accept(User $user): void
    {
        $this->update([
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by_user_id' => $user->id,
        ]);
    }

    /**
     * Revoke the invitation
     */
    public function revoke(): void
    {
        $this->update(['status' => 'revoked']);
    }

    /**
     * Find a valid invitation by token
     */
    public static function findByToken(string $token): ?self
    {
        return self::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();
    }

    /**
     * Get invitation details for display (without sensitive info)
     */
    public function getPublicDetails(): array
    {
        return [
            'token' => $this->token,
            'email' => $this->email,
            'expires_at' => $this->expires_at,
            'inviter_name' => $this->inviter?->name ?? 'Modeh Team',
        ];
    }
}
