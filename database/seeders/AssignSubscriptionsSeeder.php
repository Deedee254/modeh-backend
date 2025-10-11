<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;
use App\Models\quizee;
use App\Models\Subscription;

class AssignSubscriptionsSeeder extends Seeder
{
    /**
     * Assign every quizee a subscription to a default package (idempotent).
     */
    public function run()
    {
        // Prefer a free 'starter' package if available, otherwise pick the first active package or any package.
        $package = Package::where('slug', 'starter')->first() ?: Package::where('is_active', true)->first() ?: Package::first();

        if (!$package) {
            $this->command->warn('No package found. Skipping AssignSubscriptionsSeeder.');
            return;
        }

        $quizees = quizee::with('user')->get();
        $this->command->info('Found '.$quizees->count().' quizee records. Assigning subscriptions...');

        foreach ($quizees as $quizee) {
            $user = $quizee->user;
            if (!$user) {
                $this->command->warn('quizee id '.$quizee->id.' has no linked user; skipping.');
                continue;
            }

            // Create or update a subscription record for this user. Keep it idempotent.
            $values = [
                'package_id' => $package->id,
                'status' => 'active',
                'gateway' => 'seed',
                'gateway_meta' => ['seeded' => true],
                'started_at' => now(),
                'ends_at' => now()->addDays($package->duration_days ?? 30),
            ];

            Subscription::updateOrCreate(
                ['user_id' => $user->id],
                $values
            );
        }

        $this->command->info('AssignSubscriptionsSeeder complete.');
    }
}
