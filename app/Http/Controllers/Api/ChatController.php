<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;
use App\Events\MessageSent;
use App\Models\ChatMetric;

class ChatController extends Controller
{
    public function threads(Request $request)
    {
        $user = $request->user();

        // fetch all 1:1 messages involving the user (use new sender/recipient fields)
        $msgs = Message::where(function ($q) use ($user) {
            $q->where('sender_id', $user->id)->orWhere('recipient_id', $user->id);
        })->orderBy('created_at', 'desc')->get();

        // summarize into conversations keyed by other_user_id
        $conversations = [];
        foreach ($msgs as $m) {
            if (!empty($m->group_id)) continue; // skip group messages here
            $other = ($m->sender_id == $user->id) ? $m->recipient_id : $m->sender_id;
            if (!$other) continue;

            if (!isset($conversations[$other])) {
                $conversations[$other] = [
                    'other_user_id' => $other,
                    'other_name' => optional(\App\Models\User::find($other))->name,
                    'last_message' => $m->content,
                    'last_at' => $m->created_at,
                    'unread_count' => 0,
                ];
            }
            // count unread messages from the other user
            if ($m->sender_id == $other && !$m->is_read) {
                $conversations[$other]['unread_count']++;
            }
        }

        // turn into list sorted by last_at
        $conversations = array_values($conversations);
        usort($conversations, function ($a, $b) {
            return strtotime($b['last_at']) <=> strtotime($a['last_at']);
        });

        // groups the user belongs to
        $groups = \App\Models\Group::whereHas('members', function ($q) use ($user) {
            $q->where('users.id', $user->id);
        })->with(['members'])->get();

        // add unread_count and last_message for groups
        foreach ($groups as $g) {
            $last = Message::where('group_id', $g->id)->orderBy('created_at', 'desc')->first();
            $g->last_message = $last?->content ?? null;
            $g->last_at = $last?->created_at ?? null;
            $g->unread_count = Message::where('group_id', $g->id)->where('sender_id', '!=', $user->id)->where('is_read', false)->count();
        }

        return response()->json(['conversations' => $conversations, 'groups' => $groups]);
    }

    public function messages(Request $request)
    {
        $user = $request->user();
        if ($request->has('user_id')) {
            $other = intval($request->get('user_id'));

            // pagination support for scroll-up: before_id and per_page
            $perPage = intval($request->get('per_page', 50));
            $beforeId = $request->has('before_id') ? intval($request->get('before_id')) : null;

            $query = Message::where(function ($q) use ($user, $other) {
                $q->where('sender_id', $user->id)->where('recipient_id', $other);
            })->orWhere(function ($q) use ($user, $other) {
                $q->where('sender_id', $other)->where('recipient_id', $user->id);
            });

            if ($beforeId) {
                // fetch messages older than the message with id = beforeId
                $before = Message::find($beforeId);
                if ($before) {
                    $query->where('created_at', '<', $before->created_at);
                }
            }

            $msgs = $query->orderBy('created_at', 'asc')->limit($perPage)->get();
            return response()->json(['messages' => $msgs]);
        }

        if ($request->has('group_id')) {
            $gid = intval($request->get('group_id'));
            $msgs = Message::where('group_id', $gid)->orderBy('created_at', 'asc')->get();
            return response()->json(['messages' => $msgs]);
        }

        return response()->json(['messages' => []]);
    }

    // create or return an existing 1:1 thread meta by recipient_id
    public function ensureThread(Request $request)
    {
        // Require the new 'recipient_id' parameter only
        $request->validate(['recipient_id' => 'required|integer']);
        $user = $request->user();
        $to = intval($request->recipient_id);

        // There's no separate threads table; return last message preview if available.
        $last = Message::where(function ($q) use ($user, $to) {
            $q->where('sender_id', $user->id)->where('recipient_id', $to);
        })->orWhere(function ($q) use ($user, $to) {
            $q->where('sender_id', $to)->where('recipient_id', $user->id);
        })->orderBy('created_at', 'desc')->first();

        return response()->json(['thread' => $last]);
    }

    public function markThreadRead(Request $request)
    {
        $user = $request->user();
        $request->validate(['other_user_id' => 'required|integer']);
        $other = intval($request->other_user_id);
        Message::where('sender_id', $other)->where('recipient_id', $user->id)->update(['is_read' => true]);
        return response()->json(['ok' => true]);
    }

    public function markGroupRead(Request $request)
    {
        $user = $request->user();
        $request->validate(['group_id' => 'required|integer']);
        $gid = intval($request->group_id);
        // Mark all messages in group as read for this user by toggling a pivot or message read flag
        // Simpler approach: set read = true for messages in group that are not from this user
        Message::where('group_id', $gid)->where('sender_id', '!=', $user->id)->update(['is_read' => true]);
        return response()->json(['ok' => true]);
    }

    public function send(Request $request)
    {

        // Require new field names only.
        $request->validate([
            'content' => 'required|string',
            'recipient_id' => 'nullable|integer',
            'group_id' => 'nullable|integer'
        ]);
        $fromId = $request->user()->id;

        if ($request->filled('group_id')) {
            // Group message
            $msg = Message::create([
                'sender_id' => $fromId,
                'group_id' => $request->group_id,
                'content' => $request->content,
                'type' => 'group',
                'is_read' => false,
            ]);
        } else {
            // Direct message
            $msg = Message::create([
                'sender_id' => $fromId,
                'recipient_id' => $request->recipient_id,
                'content' => $request->content,
                'type' => 'direct',
                'is_read' => false,
            ]);
        }

        // Update metrics: messages_total, messages_last_minute (simple rolling window), last_message_at
        try {
            // messages_total (atomic increment)
            \DB::table('chat_metrics')->where('key', 'messages_total')->increment('value', 1, ['last_updated_at' => now()]);

            // per-minute bucket key, format YYYYMMDDHHMM
            $bucket = now()->format('YmdHi');
            \App\Models\ChatMetricBucket::updateOrCreate(
                ['metric_key' => 'messages_per_minute', 'bucket' => $bucket],
                ['value' => \DB::raw('COALESCE(value,0) + 1'), 'last_updated_at' => now()]
            );

            // last_message_at
            ChatMetric::updateOrCreate(['key' => 'last_message_at'], ['value' => now()->getTimestamp(), 'last_updated_at' => now()]);
        } catch (\Exception $e) {
            // log metric update failure but don't break send
            \Log::error('Failed to update chat metrics: ' . $e->getMessage());
        }

        // If it's a 1:1 message, notify recipient
        if (!empty($msg->recipient_id)) {
            $recipient = \App\Models\User::find($msg->recipient_id);
            if ($recipient) {
                try {
                    $recipient->notify(new \App\Notifications\NewMessageNotification($msg));
                } catch (\Exception $e) {
                    // Don't fail the request if notifications are misconfigured in this environment.
                    \Log::error('Failed to send NewMessageNotification: ' . $e->getMessage());
                }
            }
        } else if (!empty($msg->group_id)) {
            // Optionally, notify group members via Notification (omitted to avoid spam)
        }

        // Broadcast event for Echo listeners (MessageSent handles group vs user channel)
        try {
            \Log::info('ChatController: about to dispatch MessageSent', ['message_id' => $msg->id]);
            // Log the current event dispatcher class to ensure Event::fake() is in effect during tests
            try {
                $dispatcherClass = get_class(app('events'));
            } catch (\Throwable $e) {
                $dispatcherClass = 'unknown';
            }
            \Log::info('ChatController: event dispatcher class before dispatch', ['class' => $dispatcherClass]);

            event(new MessageSent($msg));

            try {
                $dispatcherClassAfter = get_class(app('events'));
            } catch (\Throwable $e) {
                $dispatcherClassAfter = 'unknown';
            }
            \Log::info('ChatController: event dispatcher class after dispatch', ['class' => $dispatcherClassAfter]);
            \Log::info('ChatController: dispatched MessageSent', ['message_id' => $msg->id]);
        } catch (\Exception $e) {
            // Broadcasting misconfiguration should not cause request failure.
            \Log::error('Broadcast MessageSent failed: ' . $e->getMessage());
        }

        return response()->json(['message' => $msg], 201);
    }

    // Edit a message
    public function updateMessage(Request $request, $id)
    {
        $user = $request->user();
        $msg = Message::findOrFail($id);
        if ($msg->sender_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $request->validate(['body' => 'required|string']);
        $msg->content = $request->body;
        $msg->save();
        // Optionally broadcast update event
        return response()->json(['message' => $msg]);
    }

    // Delete a message
    public function deleteMessage(Request $request, $id)
    {
        $user = $request->user();
        $msg = Message::findOrFail($id);
        if ($msg->sender_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }
        $msg->delete();
        // Optionally broadcast delete event
        return response()->json(['ok' => true]);
    }

    // Typing indicator
    public function typing(Request $request)
    {
        $user = $request->user();
        $threadId = $request->input('thread_id');
        // Broadcast typing whisper to recipient (Echo private channel)
        if ($threadId) {
            \Broadcast::channel('App.Models.User.' . $threadId, function () use ($user, $threadId) {
                return true;
            });
            \Broadcast::socket($request->header('X-Socket-Id'))->whisper('typing', ['thread_id' => $threadId, 'user_id' => $user->id]);
        }
        return response()->json(['ok' => true]);
    }

    // Typing stopped indicator
    public function typingStopped(Request $request)
    {
        $user = $request->user();
        $threadId = $request->input('thread_id');
        if ($threadId) {
            \Broadcast::channel('App.Models.User.' . $threadId, function () use ($user, $threadId) {
                return true;
            });
            \Broadcast::socket($request->header('X-Socket-Id'))->whisper('typing-stopped', ['thread_id' => $threadId, 'user_id' => $user->id]);
        }
        return response()->json(['ok' => true]);
    }
}
