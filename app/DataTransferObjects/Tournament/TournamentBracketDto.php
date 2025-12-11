<?php

namespace App\DataTransferObjects\Tournament;

class TournamentBracketDto
{
    public function __construct(
        public bool $ok,
        public object $tournament,
        public ?WinnerDto $winner,
        /** @var TournamentRoundDto[] */
        public array $bracket,
        public int $total_rounds,
        public int $current_round,
        public object $summary
    ) {}
}
