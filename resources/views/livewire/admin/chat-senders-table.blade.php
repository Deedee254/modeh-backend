<div>
    <div class="overflow-x-auto bg-white rounded shadow-sm">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Sender</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Messages</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Unread</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Last message</th>
                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                @foreach ($paginator as $row)
                    @php
                        $sender = $senders->get($row->sender_id);
                        $latestMessage = \App\Models\Message::where('sender_id', $row->sender_id)->orderByDesc('created_at')->first();
                        $viewUrl = null;
                        if ($latestMessage) {
                            try {
                                $viewUrl = \App\Filament\Resources\ChatResource::getUrl('view', ['record' => $latestMessage->id]);
                            } catch (\Throwable $e) {
                                $viewUrl = null;
                            }
                        }
                    @endphp
                    <tr>
                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $sender?->name ?? ('User #' . $row->sender_id) }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">{{ $sender?->email ?? '' }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $row->messages_count }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ $row->unread_count }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500">{{ optional($row->last_message_at)->diffForHumans() }}</td>
                        <td class="px-4 py-2 whitespace-nowrap text-right text-sm font-medium">
                            @if($viewUrl)
                                <a href="{{ $viewUrl }}" class="text-indigo-600 hover:text-indigo-900">View</a>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $paginator->links() }}
    </div>
</div>
