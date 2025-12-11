<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\Message;

class ChatSendersTable extends Component
{
    public $perPage = 15;

    public function render()
    {
        $query = Message::query()
            ->select('sender_id', DB::raw('MAX(created_at) as last_message_at'), DB::raw('COUNT(*) as messages_count'), DB::raw('SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) as unread_count'))
            ->groupBy('sender_id')
            ->orderByDesc('last_message_at');

        $paginator = $query->paginate($this->perPage);

        // load sender models for the current page
        $senderIds = $paginator->pluck('sender_id')->filter()->unique()->toArray();
        $senders = User::whereIn('id', $senderIds)->get()->keyBy('id');

        return view('livewire.admin.chat-senders-table', [
            'paginator' => $paginator,
            'senders' => $senders,
        ]);
    }
}
