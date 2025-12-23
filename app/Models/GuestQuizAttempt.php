<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;

/**
 * Guest Quiz Attempt model - for unauthenticated quiz submissions
 *
 * @property string $id UUID primary key
 * @property int $quiz_id
 * @property string $guest_identifier Unique identifier for guest session
 * @property int $score Total score (0-100)
 * @property int $percentage Percentage score
 * @property int $correct_count Number of correct answers
 * @property int $incorrect_count Number of incorrect answers
 * @property int $skipped_count Number of skipped questions
 * @property int $time_taken Time taken in seconds
 * @property array $results Detailed results for each question
 * @property int|null $user_id User ID if guest converted to user
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 *
 * @property-read \App\Models\Quiz $quiz
 * @property-read \App\Models\User|null $user
 */
class GuestQuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'guest_quiz_attempts';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'quiz_id',
        'guest_identifier',
        'score',
        'percentage',
        'correct_count',
        'incorrect_count',
        'skipped_count',
        'time_taken',
        'results',
        'user_id',
    ];

    protected $casts = [
        'results' => 'array',
        'score' => 'integer',
        'percentage' => 'integer',
        'correct_count' => 'integer',
        'incorrect_count' => 'integer',
        'skipped_count' => 'integer',
        'time_taken' => 'integer',
    ];

    protected static function boot()
    {
        parent::boot();

        // Generate UUID on creation
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = Str::uuid()->toString();
            }
        });
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
