<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $occupation
 * @property string|null $phone
 * @property string|null $bio
 * @property array|null $metadata
 * @property bool $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ParentUser extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'occupation',
        'phone',
        'bio',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    protected $table = 'parents';

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function quizees(): BelongsToMany
    {
        return $this->belongsToMany(Quizee::class, 'parent_student')
            ->withPivot('student_invitation_id', 'package_assignment', 'connected_at')
            ->withTimestamps();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(QuizeeInvitation::class);
    }

    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->where('status', 'pending');
    }

    public function acceptedInvitations(): HasMany
    {
        return $this->invitations()->where('status', 'accepted');
    }
}
