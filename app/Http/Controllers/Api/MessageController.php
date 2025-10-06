<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Events\MessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth:sanctum');
    }

    public function contacts()
    {
        $user = Auth::user();
        
        // Get users who have exchanged messages with the current user
        $contacts = DB::table('messages')
            ->where(function($query) use ($user) {
                $query->where('sender_id', $user->id)
                      ->orWhere('recipient_id', $user->id);
            })
            ->select('sender_id', 'recipient_id')
            ->get()
            ->map(function($msg) use ($user) {
                return $msg->sender_id === $user->id ? $msg->recipient_id : $msg->sender_id;
            })
            ->unique();
        
        // Get contact details and their last message
        $contactDetails = User::whereIn('id', $contacts)
            ->get()
            ->map(function($contact) use ($user) {
                $lastMessage = Message::where(function($query) use ($user, $contact) {
                    $query->where(function($q) use ($user, $contact) {
                        $q->where('sender_id', $user->id)
                          ->where('recipient_id', $contact->id);
                    })->orWhere(function($q) use ($user, $contact) {
                        $q->where('sender_id', $contact->id)
                          ->where('recipient_id', $user->id);
                    });
                })
                ->latest()
                ->first();
                
                $unreadCount = Message::where('sender_id', $contact->id)
                    ->where('recipient_id', $user->id)
                    ->where('is_read', false)
                    ->count();
                
                return [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'email' => $contact->email,
                    'avatar' => $contact->avatar,
                    'role' => $contact->role,
                    'lastMessage' => $lastMessage,
                    'unreadCount' => $unreadCount
                ];
            });
            
        return response()->json(['contacts' => $contactDetails]);
    }

    public function messages($contactId)
    {
        $user = Auth::user();
        
            // Get message type from request
            $type = request('type', 'direct');
        
            $messages = Message::where('type', $type)
                ->where(function($query) use ($user, $contactId) {
            $query->where(function($q) use ($user, $contactId) {
                $q->where('sender_id', $user->id)
                  ->where('recipient_id', $contactId);
            })->orWhere(function($q) use ($user, $contactId) {
                $q->where('sender_id', $contactId)
                  ->where('recipient_id', $user->id);
            });
        })
        ->orderBy('created_at')
        ->get();
        
        return response()->json(['messages' => $messages]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'recipient_id' => 'required|exists:users,id',
            'content' => 'required|string|max:1000',
                'type' => 'required|in:direct,support,group'
        ]);
        
        $user = Auth::user();
        
        $message = Message::create([
            'sender_id' => $user->id,
            'recipient_id' => $request->recipient_id,
            'content' => $request->content,
                'type' => $request->type,
            'is_read' => false,
        ]);
        
        broadcast(new MessageSent($message))->toOthers();
        
        return response()->json(['message' => $message], 201);
    }

    public function markAsRead($messageId)
    {
        $user = Auth::user();
        
        $message = Message::where('id', $messageId)
            ->where('recipient_id', $user->id)
            ->first();
            
        if ($message) {
            $message->update(['is_read' => true]);
        }
        
        return response()->json(['success' => true]);
    }

    public function markMultipleAsRead(Request $request)
    {
        $request->validate([
            'message_ids' => 'required|array',
            'message_ids.*' => 'integer|exists:messages,id'
        ]);
        
        $user = Auth::user();
        
        Message::whereIn('id', $request->message_ids)
            ->where('recipient_id', $user->id)
            ->update(['is_read' => true]);
            
        return response()->json(['success' => true]);
    }

    public function searchUsers(Request $request)
    {
        $query = $request->get('q');
        $user = Auth::user();
        
        $users = User::where('id', '!=', $user->id)
            ->where(function($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            ->limit(10)
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar,
                    'role' => $user->role,
                ];
            });
            
        return response()->json(['users' => $users]);
    }

    public function supportChat()
    {
        $user = Auth::user();
        
        // Find the first admin user for support
            $admin = User::whereHas('roles', function($query) {
                    $query->where('name', 'support');
                })
                ->orderBy('last_active_at', 'desc')
                ->first();
        
        if (!$admin) {
            return response()->json(['error' => 'Support unavailable'], 404);
        }
        
        return response()->json([
            'contact' => [
                'id' => $admin->id,
                'name' => 'Admin Support',
                'email' => $admin->email,
                'avatar' => $admin->avatar,
                    'role' => 'support',
            ]
        ]);
    }
}