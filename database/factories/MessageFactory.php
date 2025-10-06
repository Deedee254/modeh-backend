<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use App\Models\Message;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'sender_id' => null,
            'recipient_id' => null,
            'group_id' => null,
            'content' => fake()->sentence(),
            'type' => 'direct',
            'is_read' => false,
        ];
    }
}
