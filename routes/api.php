<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register/quizee', [AuthController::class, 'registerquizee'])->middleware('throttle:5,1');
Route::post('/register/quiz-master', [AuthController::class, 'registerQuizMaster'])->middleware('throttle:5,1');
Route::post('/register/institution-manager', [AuthController::class, 'registerInstitutionManager'])->middleware('throttle:5,1');

// Public helper for frontend to confirm verification status of an email address
Route::get('/auth/verify-status', [AuthController::class, 'verifyStatus']);
// Endpoint used by the frontend to trigger verification after landing from email link
Route::post('/auth/verify-email', [AuthController::class, 'verifyEmail']);

// Get a fresh CSRF token (public endpoint for pre-login CSRF preparation)
Route::get('/csrf-token', [AuthController::class, 'getCsrfToken'])->middleware('web');

// Password reset endpoints (public)
Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/auth/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Ensure the login route runs through the web (session) middleware so
// session() is available during cookie-based (Sanctum) authentication.
Route::post('/login', [AuthController::class, 'login'])->middleware('web');

// Logout and authenticated routes also need the session middleware.
// Social authentication routes
Route::middleware('web')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback']);
});

// Public read-only endpoints: allow anonymous users to fetch lists used by the frontend
Route::get('/quizzes', [\App\Http\Controllers\Api\QuizController::class, 'index']);
// Public quiz show (safe for anonymous users; attempt payload strips answers)
Route::get('/quizzes/{quiz}', [\App\Http\Controllers\Api\QuizAttemptController::class, 'show']);

// Guest quiz endpoints - for free quizzes only, no authentication required
Route::get('/quizzes/{quiz}/questions', [\App\Http\Controllers\Api\GuestQuizController::class, 'getQuestions']);
Route::post('/quizzes/{quiz}/submit', [\App\Http\Controllers\Api\GuestQuizController::class, 'submit'])->middleware('throttle:30,1');
// Per-question marking for guest users (allows server-side marking without exposing all answers)
Route::post('/quizzes/{quiz}/mark', [\App\Http\Controllers\Api\GuestQuizController::class, 'markQuestion'])->middleware('throttle:60,1');

// Public grades listing for frontend
Route::get('/grades', [\App\Http\Controllers\Api\GradeController::class, 'index']);
// Public levels listing (grouping of grades)
Route::get('/levels', [\App\Http\Controllers\Api\LevelController::class, 'index']);
Route::get('/levels/{level}', [\App\Http\Controllers\Api\LevelController::class, 'show']);
// Public testimonials listing for homepage
Route::get('/testimonials', [\App\Http\Controllers\Api\TestimonialController::class, 'index']);
Route::get('/subjects', [\App\Http\Controllers\Api\SubjectController::class, 'index']);
Route::get('/topics', [\App\Http\Controllers\Api\TopicController::class, 'index']);
// Public topics listing for frontend
// Get quizzes by topic
Route::get('/topics/{topic}/quizzes', [\App\Http\Controllers\Api\TopicController::class, 'quizzes']);
// Public show endpoints for detail pages
Route::get('/grades/{grade}', [\App\Http\Controllers\Api\GradeController::class, 'show']);
Route::get('/grades/{grade}/topics', [\App\Http\Controllers\Api\GradeController::class, 'topics']);
Route::get('/topics/{topic}', [\App\Http\Controllers\Api\TopicController::class, 'show']);
Route::get('/subjects/{subject}', [\App\Http\Controllers\Api\SubjectController::class, 'show']);
// Get topics by subject
Route::get('/subjects/{subject}/topics', [\App\Http\Controllers\Api\SubjectController::class, 'topics']);
// Sponsors for homepage carousel
Route::get('/sponsors', [\App\Http\Controllers\Api\SponsorController::class, 'index']);
// Public packages listing for pricing page
Route::get('/packages', [\App\Http\Controllers\Api\PackageController::class, 'index']);
// Public subscription status check (by transaction id) - available to anonymous users for frontend polling
Route::get('/subscriptions/status', [\App\Http\Controllers\Api\SubscriptionController::class, 'statusByTx']);
Route::get('/quiz-masters', [\App\Http\Controllers\Api\QuizMasterController::class, 'index']);
Route::get('/quiz-masters/{id}', [\App\Http\Controllers\Api\QuizMasterController::class, 'show']);
// quiz-master's followers (moved to authenticated group below)

// Public recommendations (grade-filtered, randomized) - available to anonymous users
Route::get('/recommendations/quizzes', [\App\Http\Controllers\Api\RecommendationController::class, 'quizzes']);

// Public tournament routes for listing and viewing
Route::get('/tournaments', [\App\Http\Controllers\Api\TournamentController::class, 'index']);
Route::get('/tournaments/{tournament}', [\App\Http\Controllers\Api\TournamentController::class, 'show']);
Route::get('/tournaments/{tournament}/tree', [\App\Http\Controllers\Api\TournamentController::class, 'tree']);
// Global public leaderboard (supports pagination, sorting and search)
Route::get('/leaderboard', [\App\Http\Controllers\Api\LeaderboardController::class, 'index']);
// Public badges endpoint
Route::get('/badges', [\App\Http\Controllers\Api\BadgeController::class, 'index']);
// Public daily challenge leaderboard
Route::get('/daily-challenges/leaderboard', [\App\Http\Controllers\Api\DailyChallengeController::class, 'leaderboard']);

// Public institutions endpoints
Route::get('/institutions', [\App\Http\Controllers\Api\InstitutionController::class, 'index'] ?? function () { return response()->json([], 200); });
Route::get('/institutions/{institution}', [\App\Http\Controllers\Api\InstitutionController::class, 'show']);
// Public invitation endpoint - allows unauthenticated users to view invitation details
Route::get('/institutions/invitation/{token}', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'getInvitationDetails']);

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    // Institution creation (authenticated users become institution-manager)
    Route::post('/institutions', [\App\Http\Controllers\Api\InstitutionController::class, 'store']);
    // Institution update (institution manager only)
    Route::patch('/institutions/{institution}', [\App\Http\Controllers\Api\InstitutionController::class, 'update']);
    Route::put('/institutions/{institution}', [\App\Http\Controllers\Api\InstitutionController::class, 'update']);
    // Institution member management (institution manager only)
    Route::get('/institutions/{institution}/members', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'index']);
    Route::get('/institutions/{institution}/requests', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'requests']);
    Route::get('/institutions/{institution}/members/invites', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'listInvites']);
    Route::post('/institutions/{institution}/members/accept', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'accept']);
    Route::delete('/institutions/{institution}/members/{user}', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'remove']);
    
    // Institution approval workflows (institution manager only)
    Route::get('/institutions/{institution}/approvals/pending', [\App\Http\Controllers\Api\InstitutionApprovalController::class, 'pending']);
    Route::post('/institution-approvals/{approvalRequest}/approve', [\App\Http\Controllers\Api\InstitutionApprovalController::class, 'approve']);
    Route::post('/institution-approvals/{approvalRequest}/reject', [\App\Http\Controllers\Api\InstitutionApprovalController::class, 'reject']);
    
    // Subscription & assignment endpoints for institutions
    Route::get('/institutions/{institution}/subscription', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'subscription']);
    Route::post('/institutions/{institution}/assignment/revoke', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'revokeAssignment']);
    // Direct invitations
    Route::post('/institutions/{institution}/members/invite', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'invite']);
    // Generate an invite token (no email sent) so frontend can compose and send the invite link
    Route::post('/institutions/{institution}/members/generate-token', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'generateInviteToken']);
    Route::post('/institutions/{institution}/members/accept-invitation/{token}', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'acceptInvitation']);
    Route::delete('/institutions/{institution}/members/invites/{token}', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'revokeInvite']);
    Route::get('/institutions/{institution}/members/invites/accepted', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'listAcceptedInvites']);
    // Analytics endpoints
    Route::get('/institutions/{institution}/analytics/overview', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'analyticsOverview']);
    Route::get('/institutions/{institution}/analytics/activity', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'analyticsActivity']);
    Route::get('/institutions/{institution}/analytics/performance', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'analyticsPerformance']);
    Route::get('/institutions/{institution}/analytics/member/{user}', [\App\Http\Controllers\Api\InstitutionMemberController::class, 'analyticsMember']);

    Route::get('/me', function (Request $request) {
        $user = $request->user();
        // Load common relations and profile relationship based on role
        $relations = ['affiliate', 'institutions'];
        if ($user->role === 'quiz-master') {
            $relations[] = 'quizMasterProfile.grade';
            $relations[] = 'quizMasterProfile.level';
            $relations[] = 'quizMasterProfile.institution';
        } elseif ($user->role === 'quizee') {
            $relations[] = 'quizeeProfile.grade';
            $relations[] = 'quizeeProfile.level';
            $relations[] = 'quizeeProfile.institution';
        }

        $user->loadMissing($relations);

        // Ensure profile accessors (like subjectModels) are included when serializing
        if ($user->role === 'quizee' && $user->quizeeProfile) {
            $user->quizeeProfile->append(['subjectModels']);
        } elseif ($user->role === 'quiz-master' && $user->quizMasterProfile) {
            $user->quizMasterProfile->append('subjectModels');
        }

        // Compute missing profile fields so frontend can guide completion.
        $missing = [];
        // role
        if (empty($user->role)) $missing[] = 'role';

        // institution: consider explicit pivot membership or profile institution text
        $hasInstitution = false;
        try {
            if ($user->institutions && count($user->institutions)) $hasInstitution = true;
            if (!$hasInstitution) {
                if ($user->role === 'quizee' && optional($user->quizeeProfile)->institution) $hasInstitution = true;
                if ($user->role === 'quiz-master' && optional($user->quizMasterProfile)->institution) $hasInstitution = true;
            }
        } catch (\Throwable $_) {}
        if (! $hasInstitution) $missing[] = 'institution';

        // role-specific optional checks
        if ($user->role === 'quizee') {
            if (! optional($user->quizeeProfile)->grade_id) $missing[] = 'grade';
        }
        if ($user->role === 'quiz-master') {
            // subjects stored as array/json on quizMasterProfile
            $subjects = optional($user->quizMasterProfile)->subjects ?? null;
            if (! $subjects || (is_array($subjects) && count($subjects) === 0)) $missing[] = 'subjects';
        }

        // If there are no missing fields but user.is_profile_completed is false, mark it complete
        if (empty($missing) && !$user->is_profile_completed) {
            try {
                $user->is_profile_completed = true;
                $user->save();
            } catch (\Throwable $_) {}
        }

        // Return the user along with missing fields and onboarding/completed steps so frontend can display guidance
        $payload = $user->toArray();
        
        // Make sure nested profile relationships (grade, level) are properly serialized
        if ($user->role === 'quizee' && isset($payload['quizee_profile'])) {
            // Ensure grade and level are included in the profile array
            if ($user->quizeeProfile && $user->quizeeProfile->relationLoaded('grade') && $user->quizeeProfile->grade) {
                $payload['quizee_profile']['grade'] = $user->quizeeProfile->grade->toArray();
            }
            if ($user->quizeeProfile && $user->quizeeProfile->relationLoaded('level') && $user->quizeeProfile->level) {
                $payload['quizee_profile']['level'] = $user->quizeeProfile->level->toArray();
            }
            // Ensure subjectModels are included
            if (!isset($payload['quizee_profile']['subject_models']) && $user->quizeeProfile->relationLoaded('subjectModels')) {
                $payload['quizee_profile']['subject_models'] = $user->quizeeProfile->subjectModels->toArray();
            }
        }
        
        if ($user->role === 'quiz-master' && isset($payload['quiz_master_profile'])) {
            // Ensure grade and level are included in the profile array
            if ($user->quizMasterProfile && $user->quizMasterProfile->relationLoaded('grade') && $user->quizMasterProfile->grade) {
                $payload['quiz_master_profile']['grade'] = $user->quizMasterProfile->grade->toArray();
            }
            if ($user->quizMasterProfile && $user->quizMasterProfile->relationLoaded('level') && $user->quizMasterProfile->level) {
                $payload['quiz_master_profile']['level'] = $user->quizMasterProfile->level->toArray();
            }
            // Ensure subjectModels are included
            if (!isset($payload['quiz_master_profile']['subject_models']) && $user->quizMasterProfile->relationLoaded('subjectModels')) {
                $payload['quiz_master_profile']['subject_models'] = $user->quizMasterProfile->subjectModels->toArray();
            }
        }
        
        $payload['missing_profile_fields'] = $missing;

        // Human-friendly messages for missing fields
        $messages = [];
        $map = [
            'role' => 'Account role (quizee or quiz-master) is missing',
            'institution' => 'Please select or confirm your institution/school',
            'grade' => 'Please select your grade',
            'subjects' => 'Please select at least one subject specialization',
        ];
        foreach ($missing as $k) {
            $messages[$k] = $map[$k] ?? 'Please complete: ' . $k;
        }
        $payload['missing_profile_messages'] = $messages;

        // Attach onboarding steps if available
        try {
            $onboarding = null;
            if (class_exists(\App\Models\UserOnboarding::class)) {
                $onboarding = \App\Models\UserOnboarding::where('user_id', $user->id)->first();
            }
            if ($onboarding) {
                $payload['onboarding'] = [
                    'profile_completed' => (bool)($onboarding->profile_completed ?? false),
                    'completed_steps' => $onboarding->completed_steps ?? [],
                    'institution_added' => (bool)($onboarding->institution_added ?? false),
                    'role_selected' => (bool)($onboarding->role_selected ?? false),
                    'grade_selected' => (bool)($onboarding->grade_selected ?? false),
                    'subject_selected' => (bool)($onboarding->subject_selected ?? false),
                ];
            } else {
                $payload['onboarding'] = null;
            }
        } catch (\Throwable $_) {
            $payload['onboarding'] = null;
        }

        return response()->json($payload);
    });

    // Return only the authenticated user's affiliate record (smaller payload)
    Route::get('/affiliates/me', function (Request $request) {
        $user = $request->user();
        if (!$user) return response()->json(null, 401);
        // load relation if not loaded
        $affiliate = $user->affiliate()->first();
        // Return a consistent shape when no affiliate exists so frontends don't get `null`.
        // Frontend expects at least the referral_code attribute; return it as null if absent.
        if (!$affiliate) return response()->json(['referral_code' => null], 200);
        return response()->json($affiliate);
    });
    
    // Affiliate routes
    Route::prefix('affiliates')->group(function () {
        Route::get('/stats', [\App\Http\Controllers\Api\AffiliateController::class, 'stats']);
        Route::get('/referrals', [\App\Http\Controllers\Api\AffiliateController::class, 'referrals']);
        Route::post('/generate-code', [\App\Http\Controllers\Api\AffiliateController::class, 'generateCode']);
        Route::post('/send-invite', [\App\Http\Controllers\Api\AffiliateController::class, 'sendInvite']);
        Route::post('/payout-request', [\App\Http\Controllers\Api\AffiliateController::class, 'payoutRequest']);
    });

    // Profile updates
    Route::patch('/me', [\App\Http\Controllers\Api\UserController::class, 'update']);
    Route::patch('/profile/quiz-master', [\App\Http\Controllers\Api\ProfileController::class, 'updateQuizMasterProfile']);
    Route::patch('/profile/quizee', [\App\Http\Controllers\Api\ProfileController::class, 'updateQuizeeProfile']);
    
    // Password change
    Route::post('/me/password', [\App\Http\Controllers\Api\UserController::class, 'changePassword']);
    Route::post('/me/theme', [\App\Http\Controllers\Api\UserController::class, 'setTheme']);
    Route::get('/me/theme', [\App\Http\Controllers\Api\UserController::class, 'getTheme']);

    // quiz-master Quiz endpoints
    Route::post('/quizzes', [\App\Http\Controllers\Api\QuizController::class, 'store']);
    Route::patch('/quizzes/{quiz}', [\App\Http\Controllers\Api\QuizController::class, 'update']);
    // Admin approve quiz
    Route::post('/quizzes/{quiz}/approve', [\App\Http\Controllers\Api\QuizController::class, 'approve']);

    // Quiz analytics for owners/admins
    Route::get('/quizzes/{quiz}/analytics', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'show']);
    // Exports (throttled)
    Route::get('/quizzes/{quiz}/export/csv', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'exportCsv'])->middleware('throttle:10,1');
    Route::get('/quizzes/{quiz}/export/pdf', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'exportPdf'])->middleware('throttle:5,1');
    // Get users who have liked a quiz (public endpoint)
    Route::get('/quizzes/{quiz}/likers', [\App\Http\Controllers\Api\InteractionController::class, 'quizLikers']);

    // quizee quiz take endpoints (show quiz without answers, submit answers)
    Route::post('/quizzes/{quiz}/submit', [\App\Http\Controllers\Api\QuizAttemptController::class, 'submit']);
    // server-side start attempt (creates draft attempt with server started_at)
    Route::post('/quizzes/{quiz}/start', [\App\Http\Controllers\Api\QuizAttemptController::class, 'startAttempt']);
    // mark a previously saved attempt (requires subscription)
    Route::post('/quiz-attempts/{attempt}/mark', [\App\Http\Controllers\Api\QuizAttemptController::class, 'markAttempt']);
    // Fetch a user's attempt details
    Route::get('/quiz-attempts/{attempt}', [\App\Http\Controllers\Api\QuizAttemptController::class, 'showAttempt']);
    // Review attempt (returns per-question details to the attempt owner without requiring subscription)
    Route::get('/quiz-attempts/{attempt}/review', [\App\Http\Controllers\Api\QuizAttemptController::class, 'reviewAttempt']);
    // List authenticated user's quiz attempts (quizee)
    Route::get('/quiz-attempts', [\App\Http\Controllers\Api\QuizAttemptController::class, 'index']);
    // Aggregated quiz stats for dashboard
    Route::get('/user/quiz-stats', [\App\Http\Controllers\Api\QuizAttemptController::class, 'getUserStats']);
    
    // Daily Challenge endpoints
    Route::get('/daily-challenges/today', [\App\Http\Controllers\Api\DailyChallengeController::class, 'today']);
    Route::get('/user/daily-challenges', [\App\Http\Controllers\Api\DailyChallengeController::class, 'userHistory']);
    Route::get('/daily-challenge-submissions/{submission}', [\App\Http\Controllers\Api\DailyChallengeController::class, 'getSubmissionDetails']);
    Route::post('/daily-challenges/submit', [\App\Http\Controllers\Api\DailyChallengeController::class, 'submit']);

    // Subjects
    Route::post('/subjects', [\App\Http\Controllers\Api\SubjectController::class, 'store']);
    Route::post('/subjects/{subject}/approve', [\App\Http\Controllers\Api\SubjectController::class, 'approve']);
    Route::post('/subjects/{subject}/upload-icon', [\App\Http\Controllers\Api\SubjectController::class, 'uploadIcon']);

    // Grades (create/update/delete)
    Route::post('/grades', [\App\Http\Controllers\Api\GradeController::class, 'store']);
    Route::patch('/grades/{grade}', [\App\Http\Controllers\Api\GradeController::class, 'update']);
    Route::delete('/grades/{grade}', [\App\Http\Controllers\Api\GradeController::class, 'destroy']);

    // Topics
    Route::post('/topics', [\App\Http\Controllers\Api\TopicController::class, 'store']);
    Route::post('/topics/{topic}/approve', [\App\Http\Controllers\Api\TopicController::class, 'approve']);
    Route::post('/topics/{topic}/upload-image', [\App\Http\Controllers\Api\TopicController::class, 'uploadImage']);

    // Levels (create/update/delete)
    Route::post('/levels', [\App\Http\Controllers\Api\LevelController::class, 'store']);
    Route::patch('/levels/{level}', [\App\Http\Controllers\Api\LevelController::class, 'update']);
    Route::delete('/levels/{level}', [\App\Http\Controllers\Api\LevelController::class, 'destroy']);

    // Question bank
    Route::get('/questions', [\App\Http\Controllers\Api\QuestionController::class, 'index']);
    // Public question bank endpoint (global bank queries)
    Route::get('/question-bank', [\App\Http\Controllers\Api\QuestionController::class, 'bank']);
    Route::post('/questions', [\App\Http\Controllers\Api\QuestionController::class, 'store']);
    Route::get('/questions/{question}', [\App\Http\Controllers\Api\QuestionController::class, 'show']);
    // Per-quiz question endpoints (used by quiz-master UI)
    Route::post('/quizzes/{quiz}/questions', [\App\Http\Controllers\Api\QuestionController::class, 'storeForQuiz']);
    Route::patch('/quizzes/{quiz}/questions', [\App\Http\Controllers\Api\QuestionController::class, 'bulkUpdateForQuiz']);
    // Admin approve question
    Route::post('/questions/{question}/approve', [\App\Http\Controllers\Api\QuestionController::class, 'approve']);
    Route::post('/questions/{question}', [\App\Http\Controllers\Api\QuestionController::class, 'update']);
    // Support PATCH for updates (clients may use PATCH) and allow deleting questions
    Route::patch('/questions/{question}', [\App\Http\Controllers\Api\QuestionController::class, 'update']);
    Route::delete('/questions/{question}', [\App\Http\Controllers\Api\QuestionController::class, 'destroy']);

    // Generic uploads helper (used by frontend to upload files before attaching URLs)
    Route::post('/uploads', [\App\Http\Controllers\Api\UploadController::class, 'store']);

    // Approval requests (quiz-master -> admin)
    Route::post('/{resource}/{id}/request-approval', [\App\Http\Controllers\Api\ApprovalRequestController::class, 'store']);
    
    // Notifications
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/{id}/mark-read', [\App\Http\Controllers\Api\NotificationController::class, 'markRead']);

    // Notification preferences (per-user)
    Route::get('/me/notification-preferences', [\App\Http\Controllers\Api\NotificationPreferenceController::class, 'show']);
    Route::post('/me/notification-preferences', [\App\Http\Controllers\Api\NotificationPreferenceController::class, 'update']);

    // User search for group invites
    Route::get('/users/search', [\App\Http\Controllers\Api\UserController::class, 'search']);
    // find user by email
    Route::get('/users/find-by-email', [\App\Http\Controllers\Api\UserController::class, 'findByEmail']);
    // User badges (recent)
    Route::get('/user/badges', [\App\Http\Controllers\Api\UserController::class, 'badges']);
    
    // User achievements progress
    Route::get('/achievements/progress', [\App\Http\Controllers\Api\AchievementController::class, 'progress']);
    
    // User stats (including level)
    Route::get('/user/stats', [\App\Http\Controllers\Api\UserStatsController::class, 'stats']);

    // Onboarding endpoints (mark steps and finalize)
    Route::post('/onboarding/step', [\App\Http\Controllers\Api\OnboardingController::class, 'completeStep']);
    Route::post('/onboarding/finalize', [\App\Http\Controllers\Api\OnboardingController::class, 'finalize']);
    
    // Recommendations (personalized to user/grade)
    // Note: route moved to public area to allow anonymous grade-based recommendations.
    
    // Chat
    Route::get('/chat/threads', [\App\Http\Controllers\Api\ChatController::class, 'threads']);
    Route::get('/chat/messages', [\App\Http\Controllers\Api\ChatController::class, 'messages']);
    Route::post('/chat/send', [\App\Http\Controllers\Api\ChatController::class, 'send']);
    Route::post('/chat/threads', [\App\Http\Controllers\Api\ChatController::class, 'ensureThread']);
    Route::post('/chat/threads/mark-read', [\App\Http\Controllers\Api\ChatController::class, 'markThreadRead']);
    Route::post('/chat/groups/mark-read', [\App\Http\Controllers\Api\ChatController::class, 'markGroupRead']);
    // Chat groups
    Route::get('/chat/groups', [\App\Http\Controllers\Api\GroupController::class, 'index']);
    Route::post('/chat/groups', [\App\Http\Controllers\Api\GroupController::class, 'store']);

    // Chat message editing and deletion
    Route::put('/chat/messages/{id}', [\App\Http\Controllers\Api\ChatController::class, 'updateMessage']);
    Route::delete('/chat/messages/{id}', [\App\Http\Controllers\Api\ChatController::class, 'deleteMessage']);
    // Typing indicators
    Route::post('/chat/typing', [\App\Http\Controllers\Api\ChatController::class, 'typing']);
    Route::post('/chat/typing-stopped', [\App\Http\Controllers\Api\ChatController::class, 'typingStopped']);

    // Admin echo monitoring (require admin role)
    Route::middleware(['can:viewFilament'])->group(function () {
        Route::get('/admin/echo/health', [\App\Http\Controllers\Api\EchoMonitoringController::class, 'health']);
        Route::get('/admin/echo/stats', [\App\Http\Controllers\Api\EchoMonitoringController::class, 'stats']);
        Route::get('/admin/echo/settings', [\App\Http\Controllers\Api\EchoAdminController::class, 'settings']);
        Route::post('/admin/echo/prune', [\App\Http\Controllers\Api\EchoAdminController::class, 'prune']);
        // Admin: assign or upgrade a user's subscription to a chosen package (idempotent)
        Route::post('/admin/subscriptions/assign/{user}', [\App\Http\Controllers\Api\AdminSubscriptionController::class, 'assign']);
    });
    
    // Wallet (quiz-master)
    Route::get('/wallet', [\App\Http\Controllers\Api\WalletController::class, 'mine']);
    Route::get('/wallet/transactions', [\App\Http\Controllers\Api\WalletController::class, 'transactions']);
    Route::post('/wallet/withdraw', [\App\Http\Controllers\Api\WalletController::class, 'requestWithdrawal']);
    Route::get('/wallet/withdrawals', [\App\Http\Controllers\Api\WalletController::class, 'myWithdrawals']);
    // Admin: settle pending funds into available for a quiz-master
    Route::post('/wallet/settle/{quizMasterId}', [\App\Http\Controllers\Api\WalletController::class, 'settlePending']);
    
    // quizee rewards endpoint (points, vouchers, next threshold)
    Route::get('/rewards/my', [\App\Http\Controllers\Api\WalletController::class, 'rewardsMy']);
    
    // Packages & subscriptions
    // packages index is public (defined above)
    Route::post('/packages/{package}/subscribe', [\App\Http\Controllers\Api\PackageController::class, 'subscribe']);
    Route::post('/subscriptions', [\App\Http\Controllers\Api\SubscriptionApiController::class, 'store']);
    // legacy alias used by some frontends
    Route::get('/subscriptions/history', [\App\Http\Controllers\Api\SubscriptionController::class, 'mine']);
    // subscription status check endpoints
    Route::get('/subscriptions/{subscription}/status', [\App\Http\Controllers\Api\SubscriptionController::class, 'status']);
    Route::post('/payments/subscriptions/{subscription}/mpesa/initiate', [\App\Http\Controllers\Api\PaymentController::class, 'initiateMpesa']);
    // One-off purchases (pay-to-unlock a single quiz or battle)
    Route::post('/one-off-purchases', [\App\Http\Controllers\Api\OneOffPurchaseController::class, 'store']);
    Route::get('/one-off-purchases/{purchase}', [\App\Http\Controllers\Api\OneOffPurchaseController::class, 'show']);
    Route::get('/subscriptions/mine', [\App\Http\Controllers\Api\SubscriptionController::class, 'mine']);

    
    // Interactions
    Route::post('/quizzes/{quiz}/like', [\App\Http\Controllers\Api\InteractionController::class, 'likeQuiz']);
    Route::post('/quizzes/{quiz}/unlike', [\App\Http\Controllers\Api\InteractionController::class, 'unlikeQuiz']);
    Route::post('/quiz-masters/{quiz_master}/follow', [\App\Http\Controllers\Api\InteractionController::class, 'followQuizMaster']);
    Route::post('/quiz-masters/{quiz_master}/unfollow', [\App\Http\Controllers\Api\InteractionController::class, 'unfollowQuizMaster']);
    // quiz-master followers (authenticated)
    Route::get('/quiz-master/followers', [\App\Http\Controllers\Api\InteractionController::class, 'quizMasterFollowers']);
    // User's followed quiz masters (authenticated)
    Route::get('/user/following', [\App\Http\Controllers\Api\InteractionController::class, 'userFollowing']);
    // User's liked quizzes (authenticated)
    Route::get('/user/liked-quizzes', [\App\Http\Controllers\Api\InteractionController::class, 'userLikedQuizzes']);

    // Direct Messages
    Route::get('/messages/contacts', [\App\Http\Controllers\Api\MessageController::class, 'contacts']);
    Route::get('/messages/{contactId}', [\App\Http\Controllers\Api\MessageController::class, 'messages']);
    Route::post('/messages', [\App\Http\Controllers\Api\MessageController::class, 'store']);
    Route::post('/messages/{messageId}/read', [\App\Http\Controllers\Api\MessageController::class, 'markAsRead']);
    Route::post('/messages/mark-read', [\App\Http\Controllers\Api\MessageController::class, 'markMultipleAsRead']);
    Route::get('/users/search', [\App\Http\Controllers\Api\MessageController::class, 'searchUsers']);
    Route::get('/messages/support-chat', [\App\Http\Controllers\Api\MessageController::class, 'supportChat']);

    // Battles
    Route::get('/battles', [\App\Http\Controllers\Api\BattleController::class, 'index']);
    Route::get('/me/battles', [\App\Http\Controllers\Api\BattleController::class, 'myBattles']);
    Route::post('/battles', [\App\Http\Controllers\Api\BattleController::class, 'store']);
    Route::get('/battles/{battle}', [\App\Http\Controllers\Api\BattleController::class, 'show']);
    Route::post('/battles/{battle}/join', [\App\Http\Controllers\Api\BattleController::class, 'join']);
    Route::post('/battles/{battle}/start-solo', [\App\Http\Controllers\Api\BattleController::class, 'startSolo']);

    // Tournaments
    Route::post('/tournaments/{tournament}/join', [\App\Http\Controllers\Api\TournamentController::class, 'join']);
    Route::get('/tournaments/{tournament}/battles', [\App\Http\Controllers\Api\TournamentController::class, 'battles']);
    Route::get('/tournaments/{tournament}/battles/{battle}', [\App\Http\Controllers\Api\TournamentController::class, 'showBattle']);
    Route::get('/tournaments/{tournament}/registration-status', [\App\Http\Controllers\Api\TournamentController::class, 'registrationStatus']);
    Route::get('/tournaments/{tournament}/leaderboard', [\App\Http\Controllers\Api\TournamentController::class, 'leaderboard']);
    Route::get('/tournaments/{tournament}/qualifier-leaderboard', [\App\Http\Controllers\Api\TournamentController::class, 'qualifierLeaderboard']);
    Route::get('/tournaments/{tournament}/qualification-status', [\App\Http\Controllers\Api\TournamentController::class, 'qualificationStatus']);
    Route::post('/tournaments/battles/{battle}/submit', [\App\Http\Controllers\Api\TournamentController::class, 'submitBattle']);
    Route::post('/tournaments/battles/{battle}/forfeit', [\App\Http\Controllers\Api\TournamentController::class, 'forfeitBattle']);
    Route::post('/tournaments/battles/{battle}/draft', [\App\Http\Controllers\Api\TournamentController::class, 'saveDraft']);
    Route::get('/tournaments/battles/{battle}/draft', [\App\Http\Controllers\Api\TournamentController::class, 'loadDraft']);
    Route::post('/tournaments/{tournament}/battles/{battle}/mark', [\App\Http\Controllers\Api\TournamentController::class, 'mark']);
    Route::get('/tournaments/{tournament}/battles/{battle}/result', [\App\Http\Controllers\Api\TournamentController::class, 'result']);
    Route::post('/tournaments/{tournament}/qualify/submit', [\App\Http\Controllers\Api\TournamentController::class, 'qualifySubmit']);

    // Admin tournament management (requires admin role)
    Route::middleware(['can:viewFilament'])->group(function() {
        Route::post('/admin/tournaments', [\App\Http\Controllers\Api\AdminTournamentController::class, 'store']);
        Route::put('/admin/tournaments/{tournament}', [\App\Http\Controllers\Api\AdminTournamentController::class, 'update']);
    Route::post('/admin/tournaments/{tournament}/questions', [\App\Http\Controllers\Api\AdminTournamentController::class, 'attachQuestions']);
    Route::post('/admin/tournaments/{tournament}/battles/{battle}/attach-questions', [\App\Http\Controllers\Api\AdminTournamentController::class, 'attachQuestionsToBattle']);
    Route::post('/admin/tournaments/{tournament}/generate-matches', [\App\Http\Controllers\Api\AdminTournamentController::class, 'generateMatches']);
    Route::post('/admin/tournaments/{tournament}/advance-round', [\App\Http\Controllers\Api\AdminTournamentController::class, 'advanceRound']);
    Route::post('/admin/tournaments/{tournament}/finalize-qualification', [\App\Http\Controllers\Api\AdminTournamentController::class, 'finalizeQualification']);
        Route::delete('/admin/tournaments/{tournament}', [\App\Http\Controllers\Api\AdminTournamentController::class, 'destroy']);
        // Admin approve/reject tournament registrations
        Route::post('/admin/tournaments/{tournament}/registrations/{user}/approve', [\App\Http\Controllers\Api\TournamentController::class, 'approveRegistration']);
        Route::post('/admin/tournaments/{tournament}/registrations/{user}/reject', [\App\Http\Controllers\Api\TournamentController::class, 'rejectRegistration']);
    });
    Route::post('/battles/{battle}/submit', [\App\Http\Controllers\Api\BattleController::class, 'submit']);
    Route::post('/battles/{battle}/mark', [\App\Http\Controllers\Api\BattleController::class, 'mark']);
    Route::get('/battles/{battle}/result', [\App\Http\Controllers\Api\BattleController::class, 'result']);
    Route::post('/battles/{battle}/attach-questions', [\App\Http\Controllers\Api\BattleController::class, 'attachQuestions']);
    Route::post('/battles/{battle}/solo-complete', [\App\Http\Controllers\Api\BattleController::class, 'soloComplete']);

    // Quiz Master Analytics
    Route::get('/quiz-master/analytics', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'index']);
    Route::get('/quiz-master/analytics/quizzes/{quiz}', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'show']);
    Route::get('/quiz-master/analytics/quizzes/{quiz}/export/csv', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'exportCsv']);
    Route::get('/quiz-master/analytics/quizzes/{quiz}/export/pdf', [\App\Http\Controllers\Api\QuizAnalyticsController::class, 'exportPdf']);
});

// Public webhook for mpesa callbacks
Route::post('/payments/mpesa/callback', [\App\Http\Controllers\Api\PaymentController::class, 'mpesaCallback']);

// Echo server heartbeat (POST)
Route::post('/echo/heartbeat', [\App\Http\Controllers\Api\EchoHeartbeatController::class, 'heartbeat']);

// Broadcasting auth is handled in web routes for test compatibility.
