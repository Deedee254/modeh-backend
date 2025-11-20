<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Institution;
use App\Models\User;
use App\Models\Package;
use App\Models\Subscription;

class InstitutionManagerSeeder extends Seeder
{
    public function run()
    {
        // Create or update a test institution manager user
        $user = User::updateOrCreate(
            ['email' => 'inst.manager@example.com'],
            [
                'name' => 'Institution Manager',
                'password' => bcrypt('password'),
                // this user acts as an institution manager in the seeded data
                'role' => 'institution-manager',
                // mark profile complete so the UI doesn't force complete-profile on login
                'is_profile_completed' => true,
            ]
        );

        // Create an institution
        $institution = Institution::updateOrCreate(
            ['slug' => 'seeded-institution'],
            [
                'name' => 'Seeded Institution',
                'email' => 'institution@example.com',
                'slug' => 'seeded-institution',
                'website' => null,
            ]
        );

        // Attach user as institution-manager on pivot
        $institution->users()->syncWithoutDetaching([
            $user->id => [
                'role' => 'institution-manager',
                'status' => 'active',
                'invited_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        // Ensure two packages exist for institution subscriptions
        $basic = Package::updateOrCreate(['slug' => 'institution-basic'], [
            'title' => 'Institution Basic',
            'short_description' => 'Basic institutional package',
            'description' => 'Gives institutions limited seats and basic features.',
            'price' => 199.00,
            'currency' => 'KES',
            'features' => ['display' => ['Seats: 10', 'Basic analytics']],
            'is_active' => true,
            'duration_days' => 30,
            'seats' => 10,
            'audience' => 'institution',
        ]);

        $plus = Package::updateOrCreate(['slug' => 'institution-plus'], [
            'title' => 'Institution Plus',
            'short_description' => 'Premium institutional package',
            'description' => 'More seats and advanced analytics for institutions.',
            'price' => 999.00,
            'currency' => 'KES',
            'features' => ['display' => ['Seats: 100', 'Advanced analytics', 'Priority support']],
            'is_active' => true,
            'duration_days' => 30,
            'seats' => 100,
            'audience' => 'institution',
        ]);

        // Activate the basic package for this institution (seeded subscription)
        $values = [
            'owner_type' => Institution::class,
            'owner_id' => $institution->id,
            // keep user_id populated because subscriptions.user_id column is non-nullable in the schema
            'user_id' => $user->id,
            'package_id' => $basic->id,
            'status' => 'active',
            'gateway' => 'seed',
            'gateway_meta' => ['seeded' => true],
            'started_at' => now(),
            'ends_at' => now()->addDays($basic->duration_days ?? 30),
        ];

        // Use updateOrCreate by owner_type/owner_id to be idempotent
        Subscription::updateOrCreate([
            'owner_type' => Institution::class,
            'owner_id' => $institution->id,
        ], $values);

        $this->command->info('Institution manager, institution and packages seeded.');
    }
}
