<?php

namespace App\Services;

use App\Models\Quiz;
use App\Models\User;
use App\Models\Institution;
use Illuminate\Support\Facades\Log;

/**
 * QuizAccessService
 * 
 * Determines whether a user has access to a quiz and what payment (if any) is required.
 * 
 * Business Rules:
 * 1. Institutional Quizzes (is_institutional=true):
 *    - Free for members of the quiz's institution
 *    - Must pay one_off_price for non-members
 * 
 * 2. Public Quizzes (is_institutional=false):
 *    - Free if not marked as paid (is_paid=false)
 *    - Must pay one_off_price if marked as paid (is_paid=true)
 */
class QuizAccessService
{
    /**
     * Determine the access level and any required payment for a user to take a quiz
     * 
     * @param Quiz $quiz
     * @param User $user
     * @return array{
     *     can_access: bool,
     *     is_free: bool,
     *     institution_member: bool,
     *     institution_id: ?int,
     *     price: ?float,
     *     message: string
     * }
     */
    public static function checkAccess(Quiz $quiz, User $user): array
    {
        // If quiz is institutional
        if ($quiz->is_institutional && $quiz->institution_id) {
            $isMember = $quiz->institution->users()
                ->where('user_id', $user->id)
                ->exists();

            if ($isMember) {
                // Member gets free access
                return [
                    'can_access' => true,
                    'is_free' => true,
                    'institution_member' => true,
                    'institution_id' => $quiz->institution_id,
                    'price' => null,
                    'message' => 'Free access as institution member'
                ];
            }

            // Non-members cannot access institutional assessments.
            return [
                'can_access' => false,
                'is_free' => false,
                'institution_member' => false,
                'institution_id' => $quiz->institution_id,
                'price' => null,
                'message' => 'Only members of the assigned institution can take this assessment.'
            ];
        }

        // Public quiz
        if (!$quiz->is_paid) {
            // Free public quiz
            return [
                'can_access' => true,
                'is_free' => true,
                'institution_member' => false,
                'institution_id' => null,
                'price' => null,
                'message' => 'Free access to public quiz'
            ];
        }

        // Paid public quiz
        $price = (float) ($quiz->one_off_price ?? self::getDefaultQuizPrice());
        return [
            'can_access' => true,
            'is_free' => false,
            'institution_member' => false,
            'institution_id' => null,
            'price' => $price,
            'message' => "Pay-per-attempt required: {$price}"
        ];
    }

    /**
     * Verify that a user has paid for a quiz attempt (if required)
     * For now, this checks if they have an active one_off_purchase or institutional membership
     * 
     * @param Quiz $quiz
     * @param User $user
     * @return bool
     */
    public static function hasAccessOrPaid(Quiz $quiz, User $user): bool
    {
        $access = self::checkAccess($quiz, $user);
        
        if ($access['is_free']) {
            return true;
        }

        // Check if user has a confirmed one-off purchase for this quiz
        return \App\Models\OneOffPurchase::where('user_id', $user->id)
            ->where('item_type', 'quiz')
            ->where('item_id', $quiz->id)
            ->where('status', 'confirmed')
            ->exists();
    }

    /**
     * Check if a quiz can be accessed by a user without payment
     * 
     * @param Quiz $quiz
     * @param User|null $user
     * @return bool
     */
    public static function isFreeAccess(Quiz $quiz, ?User $user = null): bool
    {
        // Public free quizzes
        if (!$quiz->is_institutional && !$quiz->is_paid) {
            return true;
        }

        // If no user, can only access if truly free
        if (!$user) {
            return false;
        }

        // Institutional member can access for free
        if ($quiz->is_institutional && $quiz->institution_id) {
            return $quiz->institution->users()
                ->where('user_id', $user->id)
                ->exists();
        }

        return false;
    }

    /**
     * Log an access attempt
     * 
     * @param Quiz $quiz
     * @param User $user
     * @param array $accessResult Result from checkAccess()
     * @return void
     */
    public static function logAccess(Quiz $quiz, User $user, array $accessResult): void
    {
        try {
            Log::channel('quiz_access')->info('Quiz access check', [
                'quiz_id' => $quiz->id,
                'user_id' => $user->id,
                'is_institutional' => $quiz->is_institutional,
                'institution_member' => $accessResult['institution_member'],
                'is_free' => $accessResult['is_free'],
                'price' => $accessResult['price'],
            ]);
        } catch (\Throwable $e) {
            // Ignore logging failures
        }
    }

    /**
     * Get the global default one-off price for quizzes.
     * Falls back to 0 if no setting is configured.
     * 
     * @return float
     */
    private static function getDefaultQuizPrice(): float
    {
        try {
            $setting = \App\Models\PricingSetting::singleton();
            return (float) ($setting->default_quiz_one_off_price ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Get the global default one-off price for battles.
     * Falls back to 0 if no setting is configured.
     * 
     * @return float
     */
    private static function getDefaultBattlePrice(): float
    {
        try {
            $setting = \App\Models\PricingSetting::singleton();
            return (float) ($setting->default_battle_one_off_price ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
