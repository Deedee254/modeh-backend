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
            $opts = array_values(array_map(function ($opt, $idx) {
                if (is_array($opt)) {
                    $text = isset($opt['text']) ? (string) $opt['text'] : (isset($opt['option']) ? (string) $opt['option'] : '');
                    return [
                        'option' => $text,
                        'text' => $text,
                        'is_correct' => !empty($opt['is_correct']),
                    ];
                }
                $text = is_string($opt) ? $opt : (string) ($opt ?? '');
                return [
                    'option' => $text,
                    'text' => $text,
                    'is_correct' => false,
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
            // canonical frontend key for the question HTML/text
            'question' => $this->body ?? '',
            'text' => $this->body ?? '',
            'marks' => isset($this->marks) ? $this->marks : null,
            'difficulty' => isset($this->difficulty) ? (int) $this->difficulty : null,
            'options' => $opts,
            'answers' => $answers,
            'explanation' => $this->explanation ?? null,
            'media_path' => $this->media_path ?? null,
            'media_type' => $this->media_type ?? null,
            'media_metadata' => $this->media_metadata ?? null,
            'youtube_url' => $this->youtube_url ?? null,
            'created_by' => $this->created_by ?? null,
            'quiz_id' => $this->quiz_id ?? null,
            'subject_id' => $this->subject_id ?? null,
            'topic_id' => $this->topic_id ?? null,
            'grade_id' => $this->grade_id ?? null,
            'level_id' => $this->level_id ?? null,
            'is_banked' => $this->is_banked ?? false,
            'is_approved' => $this->is_approved ?? false,
            'created_at' => $this->created_at ? $this->created_at->toDateTimeString() : null,
            'updated_at' => $this->updated_at ? $this->updated_at->toDateTimeString() : null,
        ];
    }
}
