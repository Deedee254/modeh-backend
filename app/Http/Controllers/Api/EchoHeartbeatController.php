<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChatMetric;

class EchoHeartbeatController extends Controller
{
    public function heartbeat(Request $request)
    {
        // Retrieve secret from configuration cache safely to avoid direct env() usage in controller
        $secret = config('site.echo_heartbeat_secret');

        // Prevent timing attack by using hash_equals for secure comparison
        if ($secret && !hash_equals((string) $secret, (string) $request->header('X-Echo-Heartbeat-Secret'))) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $now = time();
        $connections = intval($request->input('connections', 0));

        ChatMetric::updateOrCreate(['key' => 'heartbeat'], ['value' => $now, 'last_updated_at' => now()]);
        ChatMetric::updateOrCreate(['key' => 'connections'], ['value' => $connections, 'last_updated_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
