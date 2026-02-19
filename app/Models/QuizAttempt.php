<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Quiz attempt model
 *
 * @property int $id
 * @property int $user_id
 * @property int $quiz_id
 * @property bool $paid_for Whether this attempt was paid for
 * @property bool $institution_access Whether access was granted via institution membership
 * @property int|null $institution_id The institution (if any) that provided free access
 * @property array $answers The user's submitted answers
 * @property float|null $score The computed score (0-100) after marking
 * @property float|null $points_earned The points awarded for this attempt
 * @property int|null $total_time_seconds Total time spent on this attempt
 * @property array|null $per_question_time Time spent on each question (keyed by question id)
 * @property \DateTimeInterface|null $started_at When the attempt was started
 * @property \DateTimeInterface $created_at
 * @property \DateTimeInterface $updated_at
 *
 * @property-read \App\Models\Quiz $quiz
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Institution|null $institution
 */
class QuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'quiz_attempts';
    protected $fillable = ['user_id', 'quiz_id', 'paid_for', 'institution_access', 'institution_id', 'answers', 'score', 'points_earned', 'total_time_seconds', 'per_question_time'];
    protected $attributes = [
        'points_earned' => null,
        'paid_for' => false,
        'institution_access' => false,
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
        'points_earned' => 'float',
        'per_question_time' => 'array',
        'total_time_seconds' => 'integer',
        'paid_for' => 'boolean',
        'institution_access' => 'boolean',
    ];

    protected $dates = ['started_at'];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function institution()
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
