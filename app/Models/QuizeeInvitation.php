<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property int $parent_id
 * @property string $student_email (quizee's email)
 * @property string|null $student_name (quizee's name)
 * @property string $token
 * @property string $status
 * @property int|null $quizee_id
 * @property \Carbon\Carbon|null $accepted_at
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class QuizeeInvitation extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'student_email',
        'student_name',
        'token',
        'status',
        'quizee_id',
        'accepted_at',
        'expires_at',
    ];

    protected $casts = [
        'accepted_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (!$model->token) {
                $model->token = Str::random(32);
            }
            if (!$model->expires_at) {
                $model->expires_at = now()->addDays(7);
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Parent::class);
    }

    public function quizee(): BelongsTo
    {
        return $this->belongsTo(Quizee::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && !$this->isExpired();
    }

    public function accept(Quizee $quizee): void
    {
        $this->update([
            'status' => 'accepted',
            'quizee_id' => $quizee->id,
            'accepted_at' => now(),
        ]);

        $this->parent->quizees()->attach($quizee->id, [
            'student_invitation_id' => $this->id,
            'connected_at' => now(),
        ]);
    }
}
