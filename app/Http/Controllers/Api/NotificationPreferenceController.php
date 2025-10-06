<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\NotificationPreference;

class NotificationPreferenceController extends Controller
{
    // GET /api/me/notification-preferences
    public function show(Request $request)
    {
        $user = $request->user();
        $pref = NotificationPreference::where('user_id', $user->id)->first();
        return response()->json(['preferences' => $pref ? $pref->preferences : null]);
    }

    // POST /api/me/notification-preferences
    public function update(Request $request)
    {
        $user = $request->user();
        $data = $request->all();

        // Expect preferences as JSON object or array in body
        $prefs = $data['preferences'] ?? null;
        if (is_string($prefs)) {
            // try to decode
            $decoded = json_decode($prefs, true);
            if (json_last_error() === JSON_ERROR_NONE) $prefs = $decoded;
        }

        if (!is_array($prefs) && !is_null($prefs)) {
            return response()->json(['message' => 'preferences must be a JSON object or array'], 422);
        }

        $pref = NotificationPreference::updateOrCreate(['user_id' => $user->id], ['preferences' => $prefs]);

        return response()->json(['preferences' => $pref->preferences]);
    }
}
