<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id', 'created_by', 'type', 'body', 'options', 'answers', 
        'media_path', 'media_type', 'youtube_url', 'media_metadata',
        'explanation',
        'parts',
        'difficulty', 'is_quiz-master_marked', 'is_approved', 'is_banked', 
        'hint',
        // taxonomy references
        'subject_id', 'topic_id', 'grade_id'
    ];

    protected $casts = [
        'options' => 'array',
        'answers' => 'array',
        'parts' => 'array',
        'solution_steps' => 'array',
        'media_metadata' => 'array',
    'explanation' => 'string',
        'is_quiz-master_marked' => 'boolean',
        'is_approved' => 'boolean',
        'is_banked' => 'boolean',
        'approval_requested_at' => 'datetime',
        // ensure ids are cast to integers when present
        'subject_id' => 'integer',
        'topic_id' => 'integer',
        'grade_id' => 'integer',
    ];
    
    /**
     * Get the allowed question types
     */
    public static function getAllowedTypes()
    {
        return [
            'mcq' => 'Multiple Choice Question',
            'multi' => 'Multiple Select',
            'short' => 'Short Answer',
            'numeric' => 'Numeric Answer',
            'fill_blank' => 'Fill in the Blanks',
            'image_mcq' => 'Image Multiple Choice',
            'audio_mcq' => 'Audio Multiple Choice',
            'video_mcq' => 'Video Multiple Choice',
            'math' => 'Math / Multipart Question',
            'code' => 'Code Answer Question'
        ];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    /**
     * Taxonomy relationships
     */
    public function grade()
    {
        return $this->belongsTo(Grade::class, 'grade_id');
    }

    public function subject()
    {
        return $this->belongsTo(Subject::class, 'subject_id');
    }

    public function topic()
    {
        return $this->belongsTo(Topic::class, 'topic_id');
    }

    /**
     * Optional level relationship — some questions are organized by level
     */
    public function level()
    {
        return $this->belongsTo(Level::class, 'level_id');
    }
}
