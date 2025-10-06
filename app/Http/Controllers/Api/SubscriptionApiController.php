<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\Package;
use Illuminate\Support\Facades\Auth;

class SubscriptionApiController extends Controller
{
    public function store(Request $request)
    {
        $user = Auth::user();
        if (!$user) return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);

        $data = $request->validate([
            'package_id' => 'required|exists:packages,id',
            'gateway' => 'nullable|string',
            'gateway_meta' => 'nullable|array',
        ]);

        $package = Package::findOrFail($data['package_id']);

        $sub = Subscription::create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'status' => 'pending',
            'gateway' => $data['gateway'] ?? 'mpesa',
            'gateway_meta' => $data['gateway_meta'] ?? null,
        ]);

        return response()->json(['ok' => true, 'subscription' => $sub]);
    }
}
