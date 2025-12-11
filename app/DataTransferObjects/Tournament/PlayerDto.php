<?php

namespace App\DataTransferObjects\Tournament;

use App\Models\User;

class PlayerDto
{
    public function __construct(
        public ?int $id,
        public string $name,
        public ?string $avatar_url,
        public ?string $avatar,
        public float $score,
        public int $total_questions
    ) {}

    public static function fromModel(User $player, float $score, int $total_questions): self
    {
        return new self(
            id: $player->id,
            name: $player->name ?? 'Unknown',
            avatar_url: $player->avatar_url,
            avatar: $player->avatar,
            score: round($score, 2),
            total_questions: $total_questions
        );
    }

    public static function fromId(?int $id): self
    {
        return new self(
            id: $id,
            name: 'Unknown',
            avatar_url: null,
            avatar: null,
            score: 0,
            total_questions: 0
        );
    }
}
