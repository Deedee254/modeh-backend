<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * @property int $id
 * @property int|null $created_by User ID who created the quiz
 * @property int|null $user_id User ID who owns the quiz
 * @property int|null $topic_id Topic ID for this quiz
 * @property int|null $subject_id Subject ID for this quiz
 * @property int|null $grade_id Grade ID for this quiz
 * @property int|null $level_id Level ID for this quiz
 * @property string|null $title Quiz title
 * @property string $slug Quiz slug for SEO URLs
 * @property string|null $description Quiz description
 * @property string|null $youtube_url YouTube URL for quiz
 * @property string|null $cover_image Cover image path for quiz
 * @property bool $is_paid Whether this quiz is paid
 * @property string|null $one_off_price One-time purchase price
 * @property int|null $timer_seconds Total timer in seconds
 * @property int|null $per_question_seconds Time per question in seconds
 * @property bool $use_per_question_timer Whether to use per-question timer
 * @property int|null $attempts_allowed Number of attempts allowed
 * @property bool $shuffle_questions Whether to shuffle questions
 * @property bool $shuffle_answers Whether to shuffle answers
 * @property string|null $visibility Quiz visibility (public, private, etc.)
 * @property \Carbon\Carbon|null $scheduled_at When this quiz is scheduled
 * @property float|null $difficulty Average difficulty of questions
 * @property bool $is_approved Whether quiz is approved
 * @property bool $is_draft Whether quiz is a draft
 * @property \Carbon\Carbon|null $approval_requested_at When approval was requested
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \App\Models\Topic|null $topic
 * @property-read \App\Models\Level|null $level
 * @property-read \App\Models\Subject|null $subject
 * @property-read \App\Models\Grade|null $grade
 * @property-read \App\Models\User|null $author
 * @property-read \App\Models\QuizMaster|null $quizMaster
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\Question[] $questions
 * @property-read \Illuminate\Database\Eloquent\Collection|\App\Models\QuizAttempt[] $attempts
 */
class Quiz extends Model
{
    use HasFactory;

    // Include user_id so tests and factory-created quizzes can set the owning user
    protected $fillable = ['topic_id', 'subject_id', 'grade_id', 'level_id', 'user_id', 'created_by', 'title', 'slug', 'description', 'youtube_url', 'cover_image', 'is_paid', 'one_off_price', 'timer_seconds', 'per_question_seconds', 'use_per_question_timer', 'attempts_allowed', 'shuffle_questions', 'shuffle_answers', 'visibility', 'scheduled_at', 'difficulty', 'is_approved', 'is_draft', 'approval_requested_at'];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_approved' => 'boolean',
        'use_per_question_timer' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'difficulty' => 'float',
        'approval_requested_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'one_off_price' => 'decimal:2',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug) && !empty($model->title)) {
                $model->slug = \App\Services\SlugService::makeUniqueSlug($model->title, static::class);
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('title') && !empty($model->title)) {
                $model->slug = \App\Services\SlugService::makeUniqueSlug($model->title, static::class, $model->id);
            }
        });
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class);
    }

    public function level()
    {
        return $this->belongsTo(\App\Models\Level::class, 'level_id');
    }

    public function subject()
    {
        return $this->belongsTo(\App\Models\Subject::class);
    }

    public function grade()
    {
        return $this->belongsTo(\App\Models\Grade::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Return the QuizMaster profile for the quiz author (if any).
     * This assumes quizzes.user_id matches quiz_masters.user_id.
     */
    public function quizMaster()
    {
        return $this->hasOne(QuizMaster::class, 'user_id', 'user_id');
    }

    public function questions()
    {
        return $this->hasMany(Question::class);
    }

    public function attempts()
    {
        return $this->hasMany(\App\Models\QuizAttempt::class, 'quiz_id');
    }

    public function likes()
    {
        return $this->belongsToMany(User::class, 'quiz_likes');
    }

    public function userLastAttempt()
    {
        return $this->hasOne(\App\Models\QuizAttempt::class, 'quiz_id')
            ->where('user_id', Auth::id())
            ->latestOfMany();
    }

    public function recalcDifficulty()
    {
        $avg = $this->questions()->avg('difficulty') ?: 0;
        $this->difficulty = $avg;
        $this->save();
        return $this->difficulty;
    }

    // Return questions optionally shuffled and with answers shuffled per settings
    public function getPreparedQuestions()
    {
        $qs = $this->questions()->where('is_approved', true)->get()->toArray();
        if ($this->shuffle_questions) {
            shuffle($qs);
        }
        if ($this->shuffle_answers) {
            foreach ($qs as &$q) {
                if (!empty($q['options']) && is_array($q['options'])) {
                    // shuffle options while keeping answers indices consistent - rebuild mapping
                    $opts = $q['options'];
                    $order = array_keys($opts);
                    shuffle($order);
                    $newOpts = [];
                    $indexMap = [];
                    foreach ($order as $newIndex => $oldIndex) {
                        $newOpts[] = $opts[$oldIndex];
                        $indexMap[$oldIndex] = $newIndex;
                    }
                    $q['options'] = $newOpts;
                    if (!empty($q['answers']) && is_array($q['answers'])) {
                        $newAnswers = [];
                        foreach ($q['answers'] as $ans) {
                            if (isset($indexMap[$ans])) $newAnswers[] = $indexMap[$ans];
                        }
                        $q['answers'] = $newAnswers;
                    }
                }
            }
        }
        return $qs;
    }
}
