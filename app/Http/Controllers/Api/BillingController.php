<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BillingController extends Controller
{
    /**
     * Update billing settings for the authenticated user.
     *
     * This endpoint validates the incoming payload and returns a 200 response.
     * Currently it does not persist the data to the database — we can add
     * persistence later (user meta table or user columns) as a follow-up.
     */
    public function update(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'address' => ['required', 'string', 'max:2000'],
        ]);

        // Persist billing settings to the authenticated user
        $user = $request->user();
        if ($user) {
            // Use explicit assignment to avoid unexpected fill behavior
            $user->invoice_email = $data['email'];
            $user->billing_address = $data['address'];
            $user->save();

            try {
                Log::info('User billing update', ['user_id' => $user->id]);
            } catch (\Throwable $e) {
                // ignore logging failures
            }

            return response()->json(['ok' => true, 'data' => [
                'id' => $user->id,
                'invoice_email' => $user->invoice_email,
                'billing_address' => $user->billing_address,
            ]]);
        }

        // If no authenticated user, return unauthenticated error
        return response()->json(['ok' => false, 'message' => 'Unauthenticated'], 401);
    }
}
