<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Message;
use Carbon\Carbon;

class ChatMessageSeeder extends Seeder
{
    public function run(): void
    {
        $quizMaster = User::where('email', 'quiz-master@example.com')->first();
        $quizee = User::where('email', 'quizee@example.com')->first();
        $admin = User::where('email', 'admin@example.com')->first();

        if ($quizMaster && $quizee && $admin) {
            // Conversation between quiz master and quizee
            $this->createConversation([
                [
                    'from' => $quizMaster->id,
                    'to' => $quizee->id,
                    'message' => 'Hello! How are you finding the quizzes so far?',
                    'minutes_ago' => 60
                ],
                [
                    'from' => $quizee->id,
                    'to' => $quizMaster->id,
                    'message' => 'They\'re great! I especially enjoyed the science quiz.',
                    'minutes_ago' => 58
                ],
                [
                    'from' => $quizMaster->id,
                    'to' => $quizee->id,
                    'message' => 'That\'s wonderful to hear! I\'ve just added some new questions to it.',
                    'minutes_ago' => 55
                ],
            ]);

            // Conversation with admin
            $this->createConversation([
                [
                    'from' => $quizee->id,
                    'to' => $admin->id,
                    'message' => 'Hi admin, I need help with accessing my achievements.',
                    'minutes_ago' => 45
                ],
                [
                    'from' => $admin->id,
                    'to' => $quizee->id,
                    'message' => 'Of course! I can help you with that. What seems to be the issue?',
                    'minutes_ago' => 43
                ],
                [
                    'from' => $quizee->id,
                    'to' => $admin->id,
                    'message' => 'I completed a quiz but my badge hasn\'t shown up yet.',
                    'minutes_ago' => 40
                ],
                [
                    'from' => $admin->id,
                    'to' => $quizee->id,
                    'message' => 'Let me check that for you right away.',
                    'minutes_ago' => 38
                ],
            ]);
        }
    }

    private function createConversation(array $messages): void
    {
        foreach ($messages as $msg) {
            Message::create([
                'sender_id' => $msg['from'],
                'recipient_id' => $msg['to'],
                'content' => $msg['message'],
                'type' => 'direct',
                'created_at' => Carbon::now()->subMinutes($msg['minutes_ago']),
                'updated_at' => Carbon::now()->subMinutes($msg['minutes_ago']),
            ]);
        }
    }
}