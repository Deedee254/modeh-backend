<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Package;
use App\Models\Subscription;

class AdminSubscriptionController extends Controller
{
    /**
     * Assign or upgrade the given user's subscription to the chosen package.
     * Only accessible to admins (route protected by can:viewFilament middleware).
     *
     * Request shape: { package_id: int }
     */
    public function assign(Request $request, User $user)
    {
        // Only allow assigning packages to quizees via this admin endpoint
        if ($user->role !== 'quizee') {
            return response()->json(['ok' => false, 'message' => 'Can only assign packages to quizees'], 422);
        }

        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
        ]);

        $package = Package::findOrFail($data['package_id']);

        // If the user already has an active (non-expired) subscription, do nothing
        $existing = Subscription::where('user_id', $user->id)->orderByDesc('created_at')->first();
        if ($existing && $existing->status === 'active' && (is_null($existing->ends_at) || $existing->ends_at->gt(now()))) {
            $existing->load('package');
            return response()->json(['ok' => true, 'subscription' => $existing]);
        }

        // Create or update subscription for the user and mark active using model helper
        // Use updateOrCreate to remain idempotent; do not manually set started/ends here
        $sub = Subscription::updateOrCreate([
            'user_id' => $user->id,
        ], [
            'package_id' => $package->id,
            'status' => 'active',
            'gateway' => 'admin',
            'gateway_meta' => null,
        ]);

        // Ensure package relation is available, then activate so the model computes ends_at
        $sub->load('package');
        $sub->activate();

        return response()->json(['ok' => true, 'subscription' => $sub->fresh()->load('package')]);
    }
}
