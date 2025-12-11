<?php

namespace App\DataTransferObjects\Tournament;

use App\Models\Question;

class MatchQuestionDto
{
    public function __construct(
        public int $id,
        public ?string $text,
        public int $marks,
        public ?string $type,
        public mixed $correct,
        public mixed $corrects
    ) {}

    public static function fromModel(Question $question, bool $canViewCorrectAnswer): self
    {
        return new self(
            id: $question->id,
            text: $question->text ?? ($question->title ?? null),
            marks: $question->marks ?? 1,
            type: $question->type ?? null,
            correct: $canViewCorrectAnswer ? ($question->correct ?? null) : null,
            corrects: $canViewCorrectAnswer ? ($question->corrects ?? null) : null
        );
    }
}
