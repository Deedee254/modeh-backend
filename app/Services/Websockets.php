<?php

namespace App\Services;

use App\Events\GenericBroadcast;
use Illuminate\Support\Facades\Log;

class Websockets
{
    /**
     * Broadcast a payload to a channel.
     */
    public static function broadcast(string $channelName, array $payload = [], bool $isPrivate = false, bool $isPresence = false): void
    {
        try {
            event(new GenericBroadcast($channelName, $payload, $isPrivate, $isPresence));
        } catch (\Throwable $e) {
            Log::warning('Websockets broadcast failed: '.$e->getMessage());
        }
    }
}
