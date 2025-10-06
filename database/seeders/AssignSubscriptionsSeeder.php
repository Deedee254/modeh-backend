<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Package;
use App\Models\Student;
use App\Models\Subscription;

class AssignSubscriptionsSeeder extends Seeder
{
    /**
     * Assign every student a subscription to a default package (idempotent).
     */
    public function run()
    {
        // Prefer a free 'starter' package if available, otherwise pick the first active package or any package.
        $package = Package::where('slug', 'starter')->first() ?: Package::where('is_active', true)->first() ?: Package::first();

        if (!$package) {
            $this->command->warn('No package found. Skipping AssignSubscriptionsSeeder.');
            return;
        }

        $students = Student::with('user')->get();
        $this->command->info('Found '.$students->count().' student records. Assigning subscriptions...');

        foreach ($students as $student) {
            $user = $student->user;
            if (!$user) {
                $this->command->warn('Student id '.$student->id.' has no linked user; skipping.');
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
