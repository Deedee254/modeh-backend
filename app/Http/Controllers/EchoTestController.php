<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Events\TestEchoEvent;

class EchoTestController extends Controller
{
    /**
     * Send a test message via Echo
     */
    public function sendTestMessage(Request $request)
    {
        $validated = $request->validate([
            'channel' => 'required|string',
            'event' => 'required|string',
            'data' => 'nullable|array',
        ]);

        try {
            // Create sample test data if not provided
            $data = $validated['data'] ?? [
                'message' => 'Test message from Echo Server',
                'timestamp' => now()->toIso8601String(),
                'user_id' => Auth::id(),
            ];

            // Broadcast test event
            broadcast(new TestEchoEvent($data))->toOthers();

            return response()->json([
                'ok' => true,
                'message' => 'Test message sent successfully',
                'channel' => $validated['channel'],
                'event' => $validated['event'],
                'data' => $data,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Failed to send test message: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get Echo connection status
     */
    public function getStatus(Request $request)
    {
        $config = [
            'broadcaster' => config('broadcasting.default'),
            'pusher' => [
                'app_id' => config('broadcasting.connections.pusher.app_id'),
                'key' => config('broadcasting.connections.pusher.key'),
                'secret' => '***' . substr(config('broadcasting.connections.pusher.secret'), -4),
                'cluster' => config('broadcasting.connections.pusher.options.cluster'),
            ],
            'database' => [
                'host' => config('database.connections.mysql.host'),
                'port' => config('database.connections.mysql.port'),
                'database' => config('database.connections.mysql.database'),
            ],
        ];

        return response()->json([
            'ok' => true,
            'message' => 'Echo server status',
            'config' => $config,
            'user_id' => Auth::id(),
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
