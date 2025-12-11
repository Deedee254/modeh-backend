<?php

namespace App\DataTransferObjects\Tournament;

use Carbon\Carbon;

class TournamentRoundDto
{
    public function __construct(
        public int $round,
        /** @var TournamentMatchDto[] */
        public array $matches,
        public int $match_count,
        public array $status,
        public ?Carbon $round_end_date,
        public bool $is_complete,
        public bool $is_current
    ) {}
}
