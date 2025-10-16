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
        'difficulty', 'is_quiz-master_marked', 'is_approved', 'is_banked', 
        'tags', 'hint', 'solution_steps'
    ];

    protected $casts = [
        'options' => 'array',
        'answers' => 'array',
        'tags' => 'array',
        'solution_steps' => 'array',
        'media_metadata' => 'array',
    'explanation' => 'string',
        'is_quiz-master_marked' => 'boolean',
        'is_approved' => 'boolean',
        'is_banked' => 'boolean',
        'approval_requested_at' => 'datetime',
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
            'video_mcq' => 'Video Multiple Choice'
        ];
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}
