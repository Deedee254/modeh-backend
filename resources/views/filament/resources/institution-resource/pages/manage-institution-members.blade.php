@extends('filament::layouts.page')

@section('content')
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-3xl font-bold">{{ $this->getHeading() }}</h1>
            </div>
            <div class="flex gap-2">
                @foreach ($this->getHeaderActions() as $action)
                    {{ $action }}
                @endforeach
            </div>
        </div>

        @if ($this->isLoading)
            <div class="rounded-lg bg-white p-8 shadow">
                <div class="flex items-center justify-center">
                    <div class="text-gray-600">Loading members...</div>
                </div>
            </div>
        @else
            <div class="rounded-lg bg-white shadow">
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead class="border-b bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Name</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Email</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Role</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Subscription</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Status</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Last Active</th>
                                <th class="px-6 py-3 text-left text-sm font-semibold text-gray-900">Joined</th>
                                <th class="px-6 py-3 text-right text-sm font-semibold text-gray-900">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse ($this->members as $member)
                                <tr class="hover:bg-gray-50">
                                    <td class="px-6 py-3 text-sm text-gray-900">{{ $member['name'] ?? 'N/A' }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-600">{{ $member['email'] ?? 'N/A' }}</td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        <span class="inline-block rounded bg-gray-100 px-2 py-1 text-xs font-medium capitalize">
                                            {{ $member['role'] ?? 'N/A' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        <span class="inline-block rounded px-2 py-1 text-xs font-medium capitalize
                                            @if ($member['pivot']['subscription_tier'] === 'standard')
                                                bg-blue-100 text-blue-800
                                            @elseif ($member['pivot']['subscription_tier'] === 'premium')
                                                bg-green-100 text-green-800
                                            @else
                                                bg-purple-100 text-purple-800
                                            @endif
                                        ">
                                            {{ $member['pivot']['subscription_tier'] ?? 'N/A' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        <span class="inline-block rounded px-2 py-1 text-xs font-medium capitalize
                                            @if ($member['pivot']['invitation_status'] === 'accepted')
                                                bg-green-100 text-green-800
                                            @elseif ($member['pivot']['invitation_status'] === 'pending')
                                                bg-yellow-100 text-yellow-800
                                            @else
                                                bg-red-100 text-red-800
                                            @endif
                                        ">
                                            {{ $member['pivot']['invitation_status'] ?? 'N/A' }}
                                        </span>
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        @if ($member['pivot']['last_activity_at'])
                                            {{ \Carbon\Carbon::parse($member['pivot']['last_activity_at'])->diffForHumans() }}
                                        @else
                                            Never
                                        @endif
                                    </td>
                                    <td class="px-6 py-3 text-sm text-gray-600">
                                        {{ \Carbon\Carbon::parse($member['pivot']['created_at'] ?? now())->format('M d, Y') }}
                                    </td>
                                    <td class="px-6 py-3 text-right text-sm">
                                        <a href="{{ route('filament.admin.resources.institutions.member-analytics', [$this->record, $member['id']]) }}" 
                                           class="inline-block rounded bg-blue-100 px-3 py-1 text-xs font-medium text-blue-800 hover:bg-blue-200">
                                            Analytics
                                        </a>
                                        <button wire:click="removeMember({{ $member['id'] }})" 
                                                wire:confirm="Are you sure you want to remove this member?"
                                                class="ml-2 inline-block rounded bg-red-100 px-3 py-1 text-xs font-medium text-red-800 hover:bg-red-200">
                                            Remove
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="px-6 py-8 text-center text-gray-600">
                                        No members yet. Invite your first member to get started!
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    </div>
@endsection
