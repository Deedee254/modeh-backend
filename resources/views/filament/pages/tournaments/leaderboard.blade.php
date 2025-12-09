@php
/**
 * @var \App\Models\Tournament $tournament
 * @var \Illuminate\Database\Eloquent\Collection $participants
 */
@endphp

<x-app-layout>
    <div class="space-y-4">
        <header>
            <h1 class="text-2xl font-semibold">Leaderboard — {{ $tournament->name }}</h1>
            <p class="text-sm text-gray-600">Status: {{ ucfirst($tournament->status) }} — Participants: {{ $participants->count() }}</p>
        </header>

        <div class="bg-white shadow rounded-md overflow-hidden">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Rank</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Participant</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Score</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Completed At</th>
                        <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Status</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-100">
                    @forelse($participants as $index => $p)
                        <tr>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $p->pivot->rank ?? ($index + 1) }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                <div class="text-sm font-medium text-gray-900">{{ $p->name ?? $p->email }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $p->id }}</div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ $p->pivot->score ?? 0 }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ optional($p->pivot->completed_at)->toDayDateTimeString() ?? '—' }}</td>
                            <td class="px-4 py-3 whitespace-nowrap">{{ ucfirst($p->pivot->status ?? '—') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td class="px-4 py-6 text-center text-sm text-gray-500" colspan="5">No participants yet.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>
