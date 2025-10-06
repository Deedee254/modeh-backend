<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\ChatMetric;

class EchoMonitoringController extends Controller
{
    // GET /api/admin/echo/health
    public function health(Request $request)
    {
        $heartbeat = ChatMetric::where('key', 'heartbeat')->first();
        $lastMessageAt = ChatMetric::where('key', 'last_message_at')->first();
        $connections = ChatMetric::where('key', 'connections')->first();

        $now = time();
        $heartbeatTs = $heartbeat ? intval($heartbeat->value) : null;
        $alive = $heartbeatTs && ($now - $heartbeatTs) < 60; // heartbeat within last minute

        return response()->json([
            'status' => $alive ? 'ok' : 'down',
            'heartbeat' => $heartbeatTs,
            'connections' => $connections ? intval($connections->value) : 0,
            'last_message_at' => $lastMessageAt ? intval($lastMessageAt->value) : null,
            'timestamp' => $now,
        ], $alive ? 200 : 503);
    }

    // GET /api/admin/echo/stats
    public function stats(Request $request)
    {
        $messagesTotal = ChatMetric::where('key', 'messages_total')->first();
        $errors = ChatMetric::where('key', 'errors')->first();

        // support both ?window=minutes (preferred) and ?minutes=minutes (backward compatible)
        $minutes = intval($request->get('window', $request->get('minutes', 10)));
        if ($minutes < 1) { $minutes = 10; }
        if ($minutes > 240) { $minutes = 240; }

        $buckets = [];
        for ($i = $minutes - 1; $i >= 0; $i--) {
            $t = now()->subMinutes($i);
            $buckets[] = $t->format('YmdHi');
        }

        $rows = \App\Models\ChatMetricBucket::where('metric_key', 'messages_per_minute')
            ->whereIn('bucket', $buckets)
            ->get()
            ->keyBy('bucket');

        $series = [];
        foreach ($buckets as $b) {
            $series[] = isset($rows[$b]) ? intval($rows[$b]->value) : 0;
        }

        return response()->json([
            'messages_total' => $messagesTotal ? intval($messagesTotal->value) : 0,
            'messages_per_minute_series' => $series,
            'messages_per_minute_labels' => array_map(function($b){
                // label as HH:MM
                $dt = \DateTime::createFromFormat('YmdHi', $b);
                return $dt ? $dt->format('H:i') : $b;
            }, $buckets),
            'errors' => $errors ? intval($errors->value) : 0,
            'timestamp' => time(),
        ]);
    }
}
