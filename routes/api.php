<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register/quizee', [AuthController::class, 'registerquizee']);
Route::post('/register/quiz-master', [AuthController::class, 'registerQuizMaster']);

// Ensure the login route runs through the web (session) middleware so
// session() is available during cookie-based (Sanctum) authentication.
Route::post('/login', [AuthController::class, 'login'])->middleware('web');

// Logout and authenticated routes also need the session middleware.
// Social authentication routes
Route::middleware('web')->group(function () {
    Route::get('auth/{provider}/redirect', [SocialAuthController::class, 'redirect']);
    Route::get('auth/{provider}/callback', [SocialAuthController::class, 'callback']);
});

// Public read-only endpoints: allow anonymous users to fetch lists used by the frontend
Route::get('/quizzes', [\App\Http\Controllers\Api\QuizController::class, 'index']);
// Public quiz show (safe for anonymous users; attempt payload strips answers)
Route::get('/quizzes/{quiz}', [\App\Http\Controllers\Api\QuizAttemptController::class, 'show']);
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
// Global public leaderboard (supports pagination, sorting and search)
Route::get('/leaderboard', [\App\Http\Controllers\Api\LeaderboardController::class, 'index']);
// Public badges endpoint
Route::get('/badges', [\App\Http\Controllers\Api\BadgeController::class, 'index']);
// Public daily challenge leaderboard
Route::get('/daily-challenges/leaderboard', [\App\Http\Controllers\Api\DailyChallengeController::class, 'leaderboard']);

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        // Ensure the affiliate relation is loaded so the frontend can access
        // the user's referral code without an additional request.
        return $request->user()->loadMissing('affiliate');
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
    Route::get('/user/daily-challenges', [\App\Http\Controllers\Api\DailyChallengeController::class, 'history']);
    Route::post('/daily-challenges/{challenge}/submit', [\App\Http\Controllers\Api\DailyChallengeController::class, 'submit']);

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

    // Tournaments
    Route::post('/tournaments/{tournament}/join', [\App\Http\Controllers\Api\TournamentController::class, 'join']);
    Route::post('/tournaments/battles/{battle}/submit', [\App\Http\Controllers\Api\TournamentController::class, 'submitBattle']);
    Route::post('/tournaments/{tournament}/battles/{battle}/mark', [\App\Http\Controllers\Api\TournamentController::class, 'mark']);
    // Allow a tournament-battle mark call that only provides the battle id (used by some frontends)
    Route::post('/tournaments/battles/{battle}/mark', [\App\Http\Controllers\Api\TournamentController::class, 'mark']);
    Route::get('/tournaments/{tournament}/battles/{battle}/result', [\App\Http\Controllers\Api\TournamentController::class, 'result']);
    Route::get('/tournaments/{tournament}/leaderboard', [\App\Http\Controllers\Api\TournamentController::class, 'leaderboard']);

    // Admin tournament management (requires admin role)
    Route::middleware(['can:viewFilament'])->group(function() {
        Route::post('/admin/tournaments', [\App\Http\Controllers\Api\AdminTournamentController::class, 'store']);
        Route::put('/admin/tournaments/{tournament}', [\App\Http\Controllers\Api\AdminTournamentController::class, 'update']);
        Route::post('/admin/tournaments/{tournament}/questions', [\App\Http\Controllers\Api\AdminTournamentController::class, 'attachQuestions']);
        Route::post('/admin/tournaments/{tournament}/generate-matches', [\App\Http\Controllers\Api\AdminTournamentController::class, 'generateMatches']);
        Route::delete('/admin/tournaments/{tournament}', [\App\Http\Controllers\Api\AdminTournamentController::class, 'destroy']);
    });
    Route::post('/battles/{battle}/submit', [\App\Http\Controllers\Api\BattleController::class, 'submit']);
    Route::post('/battles/{battle}/mark', [\App\Http\Controllers\Api\BattleController::class, 'mark']);
    Route::get('/battles/{battle}/result', [\App\Http\Controllers\Api\BattleController::class, 'result']);
    Route::post('/battles/{battle}/attach-questions', [\App\Http\Controllers\Api\BattleController::class, 'attachQuestions']);
    Route::post('/battles/{battle}/solo-complete', [\App\Http\Controllers\Api\BattleController::class, 'soloComplete']);
});

// Public webhook for mpesa callbacks
Route::post('/payments/mpesa/callback', [\App\Http\Controllers\Api\PaymentController::class, 'mpesaCallback']);

// Echo server heartbeat (POST)
Route::post('/echo/heartbeat', [\App\Http\Controllers\Api\EchoHeartbeatController::class, 'heartbeat']);

// Broadcasting auth is handled in web routes for test compatibility.
