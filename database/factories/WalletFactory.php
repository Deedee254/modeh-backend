<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Wallet;

class WalletFactory extends Factory
{
    protected $model = Wallet::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'available' => 0,
            'pending' => 0,
            'lifetime_earned' => 0,
        ];
    }
}
