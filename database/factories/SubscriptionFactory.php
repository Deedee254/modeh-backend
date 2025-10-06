<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Subscription;
use App\Models\Package;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'user_id' => null,
            'package_id' => Package::factory(),
            'status' => 'active',
            'auto_renew' => false,
            'gateway' => null,
        ];
    }
}
