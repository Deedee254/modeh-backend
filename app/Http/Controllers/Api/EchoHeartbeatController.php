<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChatMetric;

class EchoHeartbeatController extends Controller
{
    public function heartbeat(Request $request)
    {
        // optional secret to prevent open POSTs
        $secret = env('ECHO_HEARTBEAT_SECRET');
        if ($secret && $request->header('X-Echo-Heartbeat-Secret') !== $secret) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $now = time();
        $connections = intval($request->input('connections', 0));

        ChatMetric::updateOrCreate(['key' => 'heartbeat'], ['value' => $now, 'last_updated_at' => now()]);
        ChatMetric::updateOrCreate(['key' => 'connections'], ['value' => $connections, 'last_updated_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
