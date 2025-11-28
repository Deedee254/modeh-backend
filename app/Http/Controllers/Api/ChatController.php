<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Message;
use App\Models\User;
use App\Events\MessageSent;
use App\Events\MessageRead;
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
            // Use setAttribute to avoid static-analysis warnings about dynamic/protected properties
            $g->setAttribute('last_message', $last?->content ?? null);
            $g->setAttribute('last_at', $last?->created_at ?? null);
            $g->setAttribute('unread_count', Message::where('group_id', $g->id)->where('sender_id', '!=', $user->id)->where('is_read', false)->count());
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

        // Verify the recipient exists
        $recipient = \App\Models\User::find($to);
        if (!$recipient) {
            return response()->json(['error' => 'Recipient not found'], 404);
        }

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
        
        // Broadcast to the sender that messages were read
        broadcast(new MessageRead($other, $user->id))->toOthers();
        
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
        $request->validate([
            'content' => 'required|string',
            'recipient_id' => 'nullable|integer',
            'group_id' => 'nullable|integer'
        ]);

        $fromId = $request->user()->id;
        $attachmentsMeta = $this->processAttachments($request);
        $isSupportMessage = intval($request->input('recipient_id', 0)) === -1;

        if ($isSupportMessage) {
            $messages = $this->handleSupportMessage($fromId, $request, $attachmentsMeta);
            return response()->json(['message' => $messages[0] ?? null], 201);
        }

        $msg = $this->createMessage($fromId, $request, $attachmentsMeta);
        $this->notifyRecipients($msg);
        $this->broadcastMessage($msg);
        $this->updateMetrics(1);

        return response()->json(['message' => $msg], 201);
    }

    private function processAttachments(Request $request): ?array
    {
        if (!$request->hasFile('attachments')) {
            return null;
        }

        $attachmentsMeta = [];
        foreach ($request->file('attachments') as $file) {
            try {
                $path = $file->store('chat_attachments', ['disk' => 'public']);
                $attachmentsMeta[] = [
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'url' => asset('storage/' . $path),
                    'size' => $file->getSize(),
                    'mime' => $file->getClientMimeType(),
                ];
            } catch (\Exception $e) {
                \Log::warning('Failed to store chat attachment: ' . $e->getMessage());
            }
        }

        return $attachmentsMeta ?: null;
    }

    private function handleSupportMessage(int $fromId, Request $request, ?array $attachmentsMeta): array
    {
        $admins = User::where('role', 'admin')->get();
        $messages = [];

        foreach ($admins as $admin) {
            $msg = Message::create([
                'sender_id' => $fromId,
                'recipient_id' => $admin->id,
                'content' => $request->input('content'),
                'type' => 'support',
                'is_read' => false,
                'attachments' => $attachmentsMeta,
            ]);
            $messages[] = $msg;

            try {
                $admin->notify(new \App\Notifications\NewMessageNotification($msg));
            } catch (\Exception $e) {
                \Log::error('Failed to send support notification to admin ' . $admin->id . ': ' . $e->getMessage());
            }
        }

        foreach ($messages as $msg) {
            try {
                event(new MessageSent($msg));
            } catch (\Exception $e) {
                \Log::error('Failed to broadcast message: ' . $e->getMessage());
            }
        }

        $this->updateMetrics(count($messages));

        return $messages;
    }

    private function createMessage(int $fromId, Request $request, ?array $attachmentsMeta): Message
    {
        if ($request->filled('group_id')) {
            return Message::create([
                'sender_id' => $fromId,
                'group_id' => $request->input('group_id'),
                'content' => $request->input('content'),
                'type' => 'group',
                'is_read' => false,
                'attachments' => $attachmentsMeta,
            ]);
        }

        return Message::create([
            'sender_id' => $fromId,
            'recipient_id' => $request->input('recipient_id'),
            'content' => $request->input('content'),
            'type' => 'direct',
            'is_read' => false,
            'attachments' => $attachmentsMeta,
        ]);
    }

    private function notifyRecipients(Message $msg): void
    {
        if ($msg->recipient_id) {
            $recipient = User::find($msg->recipient_id);
            if ($recipient) {
                try {
                    $recipient->notify(new \App\Notifications\NewMessageNotification($msg));
                } catch (\Exception $e) {
                    \Log::error('Failed to send NewMessageNotification: ' . $e->getMessage());
                }
            }
        }
    }

    private function broadcastMessage(Message $msg): void
    {
        try {
            event(new MessageSent($msg));
        } catch (\Exception $e) {
            \Log::error('Broadcast MessageSent failed: ' . $e->getMessage());
        }
    }

    private function updateMetrics(int $count = 1): void
    {
        try {
            \DB::table('chat_metrics')->where('key', 'messages_total')->increment('value', $count, ['last_updated_at' => now()]);
            
            $bucket = now()->format('YmdHi');
            \App\Models\ChatMetricBucket::updateOrCreate(
                ['metric_key' => 'messages_per_minute', 'bucket' => $bucket],
                ['value' => \DB::raw('COALESCE(value,0) + ' . $count), 'last_updated_at' => now()]
            );

            ChatMetric::updateOrCreate(['key' => 'last_message_at'], ['value' => now()->getTimestamp(), 'last_updated_at' => now()]);
        } catch (\Exception $e) {
            \Log::error('Failed to update chat metrics: ' . $e->getMessage());
        }
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
            try {
                // Use facade root and guard against unexpected types so static analysis is happier
                $b = \Broadcast::getFacadeRoot();
                if (is_object($b) && method_exists($b, 'socket')) {
                    $b->socket($request)->whisper('typing', ['thread_id' => $threadId, 'user_id' => $user->id]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Broadcast whisper failed: ' . $e->getMessage());
            }
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
            try {
                $b = \Broadcast::getFacadeRoot();
                if (is_object($b) && method_exists($b, 'socket')) {
                    $b->socket($request)->whisper('typing-stopped', ['thread_id' => $threadId, 'user_id' => $user->id]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Broadcast whisper failed: ' . $e->getMessage());
            }
        }
        return response()->json(['ok' => true]);
    }
}
