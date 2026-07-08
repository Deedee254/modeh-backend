<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class QuestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * We map backend model fields to the canonical API shape expected by the frontend.
     */
    public function toArray($request)
    {
        // Ensure options are in the form { option, text, is_correct }
        $opts = null;
        if (is_array($this->options)) {
            $answers = is_array($this->answers) ? array_map('strval', $this->answers) : [];
            $opts = array_values(array_map(function ($opt, $idx) use ($answers) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['option']) ? (string) $opt['option'] : '');
                    $media = $opt['media'] ?? $opt['media_path'] ?? null;
                    $mediaUrl = $media ? ((\Illuminate\Support\Str::startsWith($media, ['http://', 'https://'])) ? $media : (\Illuminate\Support\Str::startsWith($media, '/') ? url($media) : url('storage/' . $media))) : null;
                    
                    // Prioritize is_correct flag if it exists in the option array, 
                    // otherwise fall back to checking if the index is in the answers array.
                    $isCorrect = !empty($opt['is_correct']) || in_array((string)$idx, $answers, true);
                    
                    return [
                        'option' => $text,
                        'text' => $text,
                        'is_correct' => $isCorrect,
                        'media' => $media,
                        'media_url' => $mediaUrl,
                        'media_type' => $opt['media_type'] ?? null,
                    ];
                }
                $text = is_string($opt) ? $opt : (string) ($opt ?? '');
                return [
                    'option' => $text,
                    'text' => $text,
                    'is_correct' => in_array((string)$idx, $answers, true),
                ];
            }, $this->options, array_keys($this->options)));
        }

        $answers = [];
        if (is_array($this->answers)) {
            $answers = array_values(array_map(function ($a) {
                return is_null($a) ? '' : (string) $a;
            }, $this->answers));
        }

        return [
            'id' => $this->id ?? null,
            'uid' => $this->uid ?? null,
            'type' => $this->type ?? 'mcq',
            'question' => $this->body ?? '',
            'text' => $this->body ?? '',
            'body' => $this->body ?? '',
            'marks' => isset($this->marks) ? (float) $this->marks : null,
            'difficulty' => isset($this->difficulty) ? (int) $this->difficulty : null,
            'options' => $opts,
            'answers' => $answers,
            'explanation' => $this->explanation ?? null,
            'media_path' => $this->media_path ?? null,
            'media_url' => $this->media_path ? ((\Illuminate\Support\Str::startsWith($this->media_path, ['http://', 'https://'])) ? $this->media_path : (\Illuminate\Support\Str::startsWith($this->media_path, '/') ? url($this->media_path) : url('storage/' . $this->media_path))) : null,
            'media_type' => $this->media_type ?? null,
            'media_metadata' => $this->media_metadata ?? null,
            'youtube_url' => $this->youtube_url ?? null,
            'created_by' => $this->created_by ?? null,
            'quiz_id' => $this->quiz_id ?? null,
            'quiz_title' => $this->quiz->title ?? ($this->quiz->name ?? null),
            'subject_id' => $this->subject_id ?? null,
            'subject_name' => $this->subject->name ?? null,
            'topic_id' => $this->topic_id ?? null,
            'topic_name' => $this->topic->name ?? null,
            'grade_id' => $this->grade_id ?? null,
            'grade_name' => $this->grade->name ?? null,
            'level_id' => $this->level_id ?? null,
            'level_name' => $this->grade->level->name ?? ($this->level->name ?? null),
            'is_banked' => $this->is_banked ?? false,
            'is_approved' => $this->is_approved ?? false,
            'pending_flags_count' => $this->pendingFlags()->count(),
            'flags' => $this->relationLoaded('flags') ? $this->flags : [],
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
