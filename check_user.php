<?php

// Load Laravel
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(\Illuminate\Contracts\Http\Kernel::class);
$request = \Illuminate\Http\Request::capture();
$response = $kernel->handle($request);

// Now we can use Laravel models
$user = \App\Models\User::where('email', 'quizee@example.com')->first();

if (!$user) {
    echo "User not found!\n";
    exit(1);
}

echo "=== User Details ===\n";
echo "Email: " . $user->email . "\n";
echo "Name: " . $user->name . "\n";
echo "Role: " . $user->role . "\n";
echo "Phone: " . ($user->phone ?? 'N/A') . "\n";
echo "Avatar: " . ($user->avatar_url ?? $user->avatar ?? 'N/A') . "\n";

if ($user->quizeeProfile) {
    echo "\n=== Quizee Profile ===\n";
    $profile = $user->quizeeProfile;
    echo "Profile ID: " . $profile->id . "\n";
    echo "Institution: " . ($profile->institution ?? 'N/A') . "\n";
    echo "Grade ID: " . ($profile->grade_id ?? 'N/A') . "\n";
    echo "Level ID: " . ($profile->level_id ?? 'N/A') . "\n";
    echo "Subjects: " . json_encode($profile->subjects ?? []) . "\n";
    echo "First Name: " . ($profile->first_name ?? 'N/A') . "\n";
    echo "Last Name: " . ($profile->last_name ?? 'N/A') . "\n";
    echo "Bio/Profile: " . ($profile->profile ?? 'N/A') . "\n";
    echo "Created: " . $profile->created_at . "\n";
    echo "Updated: " . $profile->updated_at . "\n";
} else {
    echo "No quizee profile found!\n";
}

$kernel->terminate($request, $response);
