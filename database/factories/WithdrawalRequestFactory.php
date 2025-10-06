<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\WithdrawalRequest;

class WithdrawalRequestFactory extends Factory
{
    protected $model = WithdrawalRequest::class;

    public function definition(): array
    {
        return [
            'tutor_id' => null,
            'amount' => 0,
            'method' => null,
            'status' => 'pending',
        ];
    }
}
