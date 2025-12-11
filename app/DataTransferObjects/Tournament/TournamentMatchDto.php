<?php

namespace App\DataTransferObjects\Tournament;

use App\Models\TournamentBattle;
use Carbon\Carbon;

class TournamentMatchDto
{
    public function __construct(
        public int $id,
        public int $round,
        public string $status,
        public ?Carbon $scheduled_at,
        public ?Carbon $completed_at,
        public PlayerDto $player1,
        public PlayerDto $player2,
        public ?int $winner_id,
        public ?WinnerDto $winner,
        /** @var MatchQuestionDto[] */
        public array $questions,
        /** @var MatchAttemptDto[] */
        public array $attempts
    ) {}

    public static function fromModel(TournamentBattle $battle, bool $canViewDetails, bool $isRoundComplete, bool $isAdmin, ?int $userId): self
    {
        $player1Score = $battle->player1_score ?? 0;
        $player2Score = $battle->player2_score ?? 0;
        $totalQuestions = count($battle->questions ?? []);

        $player1 = $battle->player1
            ? PlayerDto::fromModel($battle->player1, $player1Score, $totalQuestions)
            : PlayerDto::fromId($battle->player1_id);

        $player2 = $battle->player2
            ? PlayerDto::fromModel($battle->player2, $player2Score, $totalQuestions)
            : PlayerDto::fromId($battle->player2_id);

        $winner = $battle->winner ? WinnerDto::fromModel($battle->winner) : null;

        $questions = [];
        $attempts = [];

        if ($canViewDetails) {
            $canViewCorrectAnswer = $isRoundComplete || $isAdmin;
            // Use the battle->questions relationship (explicit fetch) so callers can control eager-loading in the controller
            $questions = $battle->questions()->get()->map(fn($q) => MatchQuestionDto::fromModel($q, $canViewCorrectAnswer))->all();

            // Use the already-loaded relationship on the battle to avoid an extra query per match (prevent N+1)
            // Controller should eager-load `attempts` when building the battles collection.
            $attemptsCollection = $battle->attempts ?? collect();
            $attempts = collect($attemptsCollection)->map(function($a) use ($userId, $isRoundComplete, $isAdmin) {
                $canViewAnswer = $isRoundComplete || $isAdmin || ($userId && $userId === $a->player_id);
                return MatchAttemptDto::fromModel($a, $canViewAnswer);
            })->all();
        }

        return new self(
            id: $battle->id,
            round: (int) $battle->round,
            status: $battle->status,
            scheduled_at: $battle->scheduled_at,
            completed_at: $battle->completed_at,
            player1: $player1,
            player2: $player2,
            winner_id: $battle->winner_id,
            winner: $winner,
            questions: $questions,
            attempts: $attempts
        );
    }
}
