<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Battle;

class BattleFactory extends Factory
{
    protected $model = Battle::class;

    public function definition(): array
    {
        return [
            'initiator_id' => null,
            'opponent_id' => null,
            'winner_id' => null,
            'status' => 'pending',
            'initiator_points' => 0,
            'opponent_points' => 0,
        ];
    }
}
