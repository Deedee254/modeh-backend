<?php

namespace App\DataTransferObjects\Tournament;

use App\Models\User;

class WinnerDto
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $avatar_url,
        public ?string $avatar
    ) {}

    public static function fromModel(User $winner): self
    {
        return new self(
            id: $winner->id,
            name: $winner->name,
            avatar_url: $winner->avatar_url,
            avatar: $winner->avatar
        );
    }
}
