<?php

namespace Database\Factories;

use App\Models\Wallet;
use Illuminate\Database\Eloquent\Factories\Factory;

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
