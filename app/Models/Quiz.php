<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Quiz extends Model
{
    use HasFactory;

    // Include user_id so tests and factory-created quizzes can set the owning user
    protected $fillable = ['topic_id', 'subject_id', 'grade_id', 'user_id', 'created_by', 'title', 'description', 'youtube_url', 'cover_image', 'is_paid', 'one_off_price', 'timer_seconds', 'per_question_seconds', 'use_per_question_timer', 'attempts_allowed', 'shuffle_questions', 'shuffle_answers', 'visibility', 'scheduled_at', 'difficulty', 'is_approved', 'is_draft', 'approval_requested_at'];

    protected $casts = [
        'is_paid' => 'boolean',
        'is_approved' => 'boolean',
        'shuffle_questions' => 'boolean',
        'shuffle_answers' => 'boolean',
        'difficulty' => 'float',
        'approval_requested_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'one_off_price' => 'decimal:2',
    ];
    public function topic()
    {
        return $this->belongsTo(Topic::class);
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
