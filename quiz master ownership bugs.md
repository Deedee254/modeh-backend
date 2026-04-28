# Quiz Master Ownership Bugs — Analysis & Fixes

## Schema Truth (from migrations)

The `quizzes` table has **two** ownership columns:
- **`user_id`** — `foreignId('user_id')->constrained()->cascadeOnDelete()` (original, NOT NULL)
- **`created_by`** — `unsignedBigInteger('created_by')->nullable()` (legacy alias)

When a quiz is created (in `QuizController@createQuiz`), **both** are set to `$user->id`. The canonical column is **`user_id`**.

> [!NOTE]
> There is **no** `quiz_master_id` column on the `quizzes` table. The `quiz_master_id` column exists only on `transactions` and `withdrawal_requests` (and was renamed from hyphenated `quiz-master_id`).

---

## Bugs Found

### Bug 1: `QuizMasterController::show` — Shows ALL quizzes (no ownership filter)

**File:** `QuizMasterController.php:156`

```php
$user = User::with(['quizMasterProfile', 'quizzes.topic'])->findOrFail($id);
```

`User::quizzes()` is `hasMany(Quiz::class)` which filters by `user_id` only. This is **correct for most cases**, but misses quizzes where `user_id` is NULL and only `created_by` is set (legacy rows). Also, this endpoint is **public** — any visitor can see ALL quizzes including drafts/unapproved.

### Bug 2: `QuizMasterController::index` — Same issue, shows ALL quizzes

**File:** `QuizMasterController.php:45,47`

```php
$quizMasters = $query->with(['quizMasterProfile.grade', 'quizzes.topic'])->get();
```

Again loads all quizzes for each user via `User::quizzes()`, including **drafts and unapproved** quizzes, on a public endpoint.

### Bug 3: `DashboardAnalyticsController::index` — Trend calculation uses only `user_id`

**File:** `DashboardAnalyticsController.php:118-119`

```php
$currentQuizzes = Quiz::where('user_id', $user->id)->where('created_at', '>=', $thirtyDaysAgo)->count();
$previousQuizzes = Quiz::where('user_id', $user->id)->whereBetween('created_at', [$sixtyDaysAgo, $thirtyDaysAgo])->count();
```

The main `$ownedQuizzesQuery` (line 44) correctly uses both `user_id OR created_by`, but the trend queries only use `user_id`.

### Bug 4: `QuizController::update` — Ownership check only uses `created_by`

**File:** `QuizController.php:79`

```php
if ($quiz->created_by && $quiz->created_by !== $user->id && !$user->is_admin) {
```

This only checks `created_by`. If `created_by` is NULL (possible for older quizzes), **any authenticated user can edit any quiz**. Should also check `user_id`.

### Bug 5: Frontend profile page calls non-existent route

**File:** `profile.vue:257`

```js
api.get('/api/quiz-master/quizzes?per_page=10')
```

There is **no** `/api/quiz-master/quizzes` route in the backend. This will 404.

---

## Fixes Applied

1. **`User::quizzes()`** — Updated to cover both `user_id` and `created_by` ownership columns
2. **`QuizMasterController::show`** — Filter quizzes to only show approved/published ones on the public endpoint
3. **`QuizMasterController::index`** — Same filter for public listing
4. **`DashboardAnalyticsController`** — Trend queries now use both `user_id` and `created_by`
5. **`QuizController::update`** — Ownership check now checks both `user_id` and `created_by`
6. **Frontend `profile.vue`** — Fixed to call `/api/quizzes?mine=1&per_page=10`
