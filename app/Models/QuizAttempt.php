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
 * @property int|null $subscription_id The subscription used to reveal this attempt's results
 * @property string|null $subscription_type The type of subscription (personal or institution)
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
 * @property-read \App\Models\Subscription|null $subscription
 */
class QuizAttempt extends Model
{
    use HasFactory;

    protected $table = 'quiz_attempts';
    protected $fillable = ['user_id', 'quiz_id', 'subscription_id', 'subscription_type', 'answers', 'score', 'points_earned', 'total_time_seconds', 'per_question_time'];
    protected $attributes = [
        'points_earned' => null,
    ];

    protected $casts = [
        'answers' => 'array',
        'score' => 'float',
        'points_earned' => 'float',
        'per_question_time' => 'array',
        'total_time_seconds' => 'integer',
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

    public function subscription()
    {
        return $this->belongsTo(Subscription::class);
    }
}
