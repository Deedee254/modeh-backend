<?php

require 'vendor/autoload.php';
require 'bootstrap/app.php';

use Illuminate\Support\Facades\Auth;
use App\Models\User;

// Get the user we've been working with
$user = User::where('email', 'quizee@example.com')->with([
    'quizeeProfile.grade',
    'quizeeProfile.level',
    'affiliate',
    'institutions'
])->first();

if (!$user) {
    echo "User not found\n";
    exit;
}

echo "=== User toArray() Output ===\n";
$userArray = $user->toArray();
echo json_encode($userArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

echo "\n=== Direct Profile Access ===\n";
if ($user->quizeeProfile) {
    echo "Profile ID: " . $user->quizeeProfile->id . "\n";
    echo "Grade ID: " . $user->quizeeProfile->grade_id . "\n";
    echo "Level ID: " . $user->quizeeProfile->level_id . "\n";
    
    // Check if relations are loaded
    echo "Grade loaded? " . ($user->quizeeProfile->relationLoaded('grade') ? 'YES' : 'NO') . "\n";
    echo "Level loaded? " . ($user->quizeeProfile->relationLoaded('level') ? 'YES' : 'NO') . "\n";
    
    // Check if they're accessible
    if ($user->quizeeProfile->grade) {
        echo "Grade Object: " . $user->quizeeProfile->grade->name . "\n";
    }
    if ($user->quizeeProfile->level) {
        echo "Level Object: " . $user->quizeeProfile->level->name . "\n";
    }
    
    // Check what toArray includes
    echo "\n=== Profile toArray() Output ===\n";
    echo json_encode($user->quizeeProfile->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
