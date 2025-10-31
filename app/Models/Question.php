<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    use HasFactory;

    /**
     * Get the text of the correct option(s) for MCQ/multi questions
     * 
     * @return string|array The text of the correct option(s)
     */
    public function getCorrectOptionText()
    {
        if (!$this->options || empty($this->options)) {
            return null;
        }

        if ($this->type === 'mcq' && isset($this->correct)) {
            $index = $this->correct;
            return isset($this->options[$index]) ? $this->options[$index]['text'] : null;
        }

        if ($this->type === 'multi' && !empty($this->corrects)) {
            return collect($this->corrects)
                ->map(fn($index) => $this->options[$index]['text'] ?? null)
                ->filter()
                ->values()
                ->all();
        }

        return null;
    }

    /**
     * Get the text of a specific option by its index
     * 
     * @param int $index The index of the option
     * @return string|null The text of the option
     */
    public function getOptionText($index)
    {
        return $this->options[$index]['text'] ?? null;
    }

    /**
     * Get all options as an array of text values
     * 
     * @return array Array of option texts
     */
    public function getAllOptionTexts()
    {
        if (!$this->options) {
            return [];
        }
        
        return collect($this->options)
            ->pluck('text')
            ->all();
    }

    /**
     * Find the index of an option by its text
     * 
     * @param string $text The text to search for
     * @return int|null The index of the option or null if not found
     */
    public function findOptionIndexByText($text)
    {
        if (!$this->options) {
            return null;
        }

        foreach ($this->options as $index => $option) {
            if (isset($option['text']) && $option['text'] === $text) {
                return $index;
            }
        }

        return null;
    }

    protected $fillable = [
        'quiz_id', 'created_by', 'type', 'body', 'options', 'answers', 
        'media_path', 'media_type', 'youtube_url', 'media_metadata',
        'explanation',
        'parts', 'fill_parts',
        'correct', 'corrects',
        'marks',
        'difficulty', 'is_quiz-master_marked', 'is_approved', 'is_banked', 
        // taxonomy references
        'subject_id', 'topic_id', 'grade_id', 'level_id'
    ];

    protected $casts = [
        'options' => 'array',
        'answers' => 'array',
        'parts' => 'array',
        'fill_parts' => 'array',
        'corrects' => 'array',
        'marks' => 'float',
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
        'level_id' => 'integer',
    ];
    
    /**
     * Get the allowed question types
     */
    public static function getAllowedTypes()
    {
        // Canonical, simplified question types. Media is represented separately via media_type.
        return [
            'mcq' => 'Multiple Choice Question',
            'multi' => 'Multiple Select',
            'short' => 'Short Answer',
            'numeric' => 'Numeric Answer',
            'fill_blank' => 'Fill in the Blanks',
            'math' => 'Math / Multipart Question',
            'code' => 'Code Answer Question',
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
