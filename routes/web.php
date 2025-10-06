<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthWebController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

Route::get('/', [AuthWebController::class, 'showLogin'])->name('login');
Route::get('/login', [AuthWebController::class, 'showLogin']);
Route::post('/login', [AuthWebController::class, 'login']);
Route::post('/logout', [AuthWebController::class, 'logout']);

Route::get('/dashboard', [AuthWebController::class, 'dashboard'])->middleware('auth');

// Broadcasting auth endpoint for tests that call /broadcasting/auth directly.
// Implement a lightweight auth checker that mirrors the callbacks registered in routes/channels.php.
Route::post('/broadcasting/auth', function (Request $request) {
	$channel = $request->input('channel_name');
	$user = $request->user();

	if (! $user) {
		return response()->json(['message' => 'Unauthenticated'], 403);
	}

	// Strip Private/Presence prefixes if present (e.g., 'private-user.1', 'presence-user.1')
	$channel = preg_replace('/^(private|presence)-/', '', $channel);

	if (str_starts_with($channel, 'user.')) {
		$parts = explode('.', $channel);
		$id = $parts[1] ?? null;
		if ((int) $user->id === (int) $id) {
			return response()->json([], 200);
		}
		return response()->json([], 403);
	}

	if (str_starts_with($channel, 'group.')) {
		$parts = explode('.', $channel);
		$groupId = $parts[1] ?? null;
		try {
			$isMember = \App\Models\Group::where('id', $groupId)->whereHas('members', function ($q) use ($user) {
				$q->where('users.id', $user->id);
			})->exists();
			return response()->json([], $isMember ? 200 : 403);
		} catch (\Throwable $e) {
			return response()->json([], 403);
		}
	}

	// Default deny
	return response()->json([], 403);
});
