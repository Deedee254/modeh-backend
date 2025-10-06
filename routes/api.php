<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Auth\SocialAuthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register/student', [AuthController::class, 'registerStudent']);
Route::post('/register/tutor', [AuthController::class, 'registerTutor']);

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
// Public testimonials listing for homepage
Route::get('/testimonials', [\App\Http\Controllers\Api\TestimonialController::class, 'index']);
Route::get('/subjects', [\App\Http\Controllers\Api\SubjectController::class, 'index']);
Route::get('/topics', [\App\Http\Controllers\Api\TopicController::class, 'index']);
// Public grades listing for frontend
Route::get('/grades', [\App\Http\Controllers\Api\GradeController::class, 'index']);
// Sponsors for homepage carousel
Route::get('/sponsors', [\App\Http\Controllers\Api\SponsorController::class, 'index']);
// Public packages listing for pricing page
Route::get('/packages', [\App\Http\Controllers\Api\PackageController::class, 'index']);
Route::get('/tutors', [\App\Http\Controllers\Api\TutorController::class, 'index']);
Route::get('/tutors/{id}', [\App\Http\Controllers\Api\TutorController::class, 'show']);

// Public recommendations (grade-filtered, randomized) - available to anonymous users
Route::get('/recommendations/quizzes', [\App\Http\Controllers\Api\RecommendationController::class, 'quizzes']);

Route::middleware(['web', 'auth:sanctum'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/me', function (Request $request) {
        return $request->user();
    });

    // Profile update and password change
    Route::patch('/me', [\App\Http\Controllers\Api\UserController::class, 'update']);
    Route::post('/me/password', [\App\Http\Controllers\Api\UserController::class, 'changePassword']);
    Route::post('/me/theme', [\App\Http\Controllers\Api\UserController::class, 'setTheme']);
    Route::get('/me/theme', [\App\Http\Controllers\Api\UserController::class, 'getTheme']);

    // Tutor Quiz endpoints
    Route::post('/quizzes', [\App\Http\Controllers\Api\QuizController::class, 'store']);
    // Admin approve quiz
    Route::post('/quizzes/{quiz}/approve', [\App\Http\Controllers\Api\QuizController::class, 'approve']);

    // Student quiz take endpoints (show quiz without answers, submit answers)
    Route::post('/quizzes/{quiz}/submit', [\App\Http\Controllers\Api\QuizAttemptController::class, 'submit']);
    // Fetch a user's attempt details
    Route::get('/quiz-attempts/{attempt}', [\App\Http\Controllers\Api\QuizAttemptController::class, 'showAttempt']);
    // List authenticated user's quiz attempts (student)
    Route::get('/quiz-attempts', [\App\Http\Controllers\Api\QuizAttemptController::class, 'index']);
    
    // Daily Challenge endpoints
    Route::post('/daily-challenges/{challenge}/submit', [\App\Http\Controllers\Api\DailyChallengeController::class, 'submit']);

    // Subjects
    Route::post('/subjects', [\App\Http\Controllers\Api\SubjectController::class, 'store']);
    Route::post('/subjects/{subject}/approve', [\App\Http\Controllers\Api\SubjectController::class, 'approve']);
    Route::post('/subjects/{subject}/upload-icon', [\App\Http\Controllers\Api\SubjectController::class, 'uploadIcon']);

    // Topics
    Route::post('/topics', [\App\Http\Controllers\Api\TopicController::class, 'store']);
    Route::post('/topics/{topic}/approve', [\App\Http\Controllers\Api\TopicController::class, 'approve']);
    Route::post('/topics/{topic}/upload-image', [\App\Http\Controllers\Api\TopicController::class, 'uploadImage']);

    // Question bank
    Route::get('/questions', [\App\Http\Controllers\Api\QuestionController::class, 'index']);
    // Public question bank endpoint (global bank queries)
    Route::get('/question-bank', [\App\Http\Controllers\Api\QuestionController::class, 'bank']);
    Route::post('/questions', [\App\Http\Controllers\Api\QuestionController::class, 'store']);
    // Admin approve question
    Route::post('/questions/{question}/approve', [\App\Http\Controllers\Api\QuestionController::class, 'approve']);
    Route::post('/questions/{question}', [\App\Http\Controllers\Api\QuestionController::class, 'update']);

    // Approval requests (tutor -> admin)
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
    
    // Wallet (tutor)
    Route::get('/wallet', [\App\Http\Controllers\Api\WalletController::class, 'mine']);
    Route::get('/wallet/transactions', [\App\Http\Controllers\Api\WalletController::class, 'transactions']);
    Route::post('/wallet/withdraw', [\App\Http\Controllers\Api\WalletController::class, 'requestWithdrawal']);
    Route::get('/wallet/withdrawals', [\App\Http\Controllers\Api\WalletController::class, 'myWithdrawals']);
    
    // Student rewards endpoint (points, vouchers, next threshold)
    Route::get('/rewards/my', [\App\Http\Controllers\Api\WalletController::class, 'rewardsMy']);
    
    // Packages & subscriptions
    // packages index is public (defined above)
    Route::post('/packages/{package}/subscribe', [\App\Http\Controllers\Api\PackageController::class, 'subscribe']);
    Route::post('/subscriptions', [\App\Http\Controllers\Api\SubscriptionApiController::class, 'store']);
    // subscription status check endpoints
    Route::get('/subscriptions/status', [\App\Http\Controllers\Api\SubscriptionController::class, 'statusByTx']);
    Route::get('/subscriptions/{subscription}/status', [\App\Http\Controllers\Api\SubscriptionController::class, 'status']);
    Route::post('/payments/subscriptions/{subscription}/mpesa/initiate', [\App\Http\Controllers\Api\PaymentController::class, 'initiateMpesa']);
    Route::get('/subscriptions/mine', [\App\Http\Controllers\Api\SubscriptionController::class, 'mine']);
    Route::get('/subscriptions/mine', [\App\Http\Controllers\Api\SubscriptionController::class, 'mine']);
    // Interactions
    Route::post('/quizzes/{quiz}/like', [\App\Http\Controllers\Api\InteractionController::class, 'likeQuiz']);
    Route::post('/quizzes/{quiz}/unlike', [\App\Http\Controllers\Api\InteractionController::class, 'unlikeQuiz']);
    Route::post('/tutors/{tutor}/follow', [\App\Http\Controllers\Api\InteractionController::class, 'followTutor']);
    Route::post('/tutors/{tutor}/unfollow', [\App\Http\Controllers\Api\InteractionController::class, 'unfollowTutor']);

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
    Route::post('/battles', [\App\Http\Controllers\Api\BattleController::class, 'store']);
    Route::get('/battles/{battle}', [\App\Http\Controllers\Api\BattleController::class, 'show']);
    Route::post('/battles/{battle}/join', [\App\Http\Controllers\Api\BattleController::class, 'join']);

    // Tournaments
    Route::get('/tournaments', [\App\Http\Controllers\Api\TournamentController::class, 'index']);
    Route::get('/tournaments/{tournament}', [\App\Http\Controllers\Api\TournamentController::class, 'show']);
    Route::post('/tournaments/{tournament}/join', [\App\Http\Controllers\Api\TournamentController::class, 'join']);
    Route::post('/tournaments/battles/{battle}/submit', [\App\Http\Controllers\Api\TournamentController::class, 'submitBattle']);
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
    Route::get('/battles/{battle}/result', [\App\Http\Controllers\Api\BattleController::class, 'result']);
    Route::post('/battles/{battle}/attach-questions', [\App\Http\Controllers\Api\BattleController::class, 'attachQuestions']);
});

// Public webhook for mpesa callbacks
Route::post('/payments/mpesa/callback', [\App\Http\Controllers\Api\PaymentController::class, 'mpesaCallback']);

// Echo server heartbeat (POST)
Route::post('/echo/heartbeat', [\App\Http\Controllers\Api\EchoHeartbeatController::class, 'heartbeat']);

// Broadcasting auth is handled in web routes for test compatibility.
