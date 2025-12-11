<?php

namespace App\DataTransferObjects\Tournament;

use App\Models\TournamentBattleAttempt;
use App\DataTransferObjects\Tournament\WinnerDto;

class MatchAttemptDto
{
    public function __construct(
        public ?int $player_id,
        public ?int $question_id,
        public mixed $answer,
        public float $points,
        public ?WinnerDto $player = null
    ) {}

    public static function fromModel(TournamentBattleAttempt $attempt, bool $canViewAnswer): self
    {
        $playerDto = null;
        if (isset($attempt->player) && $attempt->player) {
            $playerDto = WinnerDto::fromModel($attempt->player);
        }
        // Resolve IDs defensively: the $attempt may be an Eloquent model or a plain array/object
        $playerId = null;
        $questionId = null;

        if (method_exists($attempt, 'getAttribute')) {
            // Eloquent model: use getAttribute to avoid undefined property notices
            $playerId = $attempt->getAttribute('player_id');
            $questionId = $attempt->getAttribute('question_id');
        } else {
            // Fallback for arrays / stdClass
            if (is_array($attempt)) {
                $playerId = $attempt['player_id'] ?? null;
                $questionId = $attempt['question_id'] ?? null;
            } elseif (is_object($attempt)) {
                $playerId = $attempt->player_id ?? ($attempt->player->id ?? null);
                $questionId = $attempt->question_id ?? ($attempt->question->id ?? null);
            }
        }

        // If IDs still missing, try relation objects
        if (empty($playerId) && isset($attempt->player) && isset($attempt->player->id)) {
            $playerId = $attempt->player->id;
        }
        if (empty($questionId) && isset($attempt->question) && isset($attempt->question->id)) {
            $questionId = $attempt->question->id;
        }

        return new self(
            player_id: $playerId,
            question_id: $questionId,
            answer: $canViewAnswer ? ($attempt->answer ?? null) : null,
            points: $attempt->points ?? 0,
            player: $playerDto
        );
    }
}
