<?php

namespace Database\Factories;

use App\Models\WithdrawalRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

class WithdrawalRequestFactory extends Factory
{
    protected $model = WithdrawalRequest::class;

    public function definition(): array
    {
        return [
            'quiz_master_id' => null,
            'amount' => 0,
            'method' => null,
            'status' => 'pending',
        ];
    }
}
