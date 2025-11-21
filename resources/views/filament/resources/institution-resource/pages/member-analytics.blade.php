@extends('filament::layouts.page')

@section('content')
    <div class="space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-3xl font-bold">{{ $this->getHeading() }}</h1>
            <p class="text-gray-600 mt-2">Detailed analytics for {{ $this->analyticsData['name'] ?? 'Member' }}</p>
        </div>

        <!-- Member Profile Card -->
        <div class="rounded-lg bg-white p-6 shadow">
            <div class="flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-bold">{{ $this->analyticsData['name'] ?? 'Unknown' }}</h2>
                    <p class="text-gray-600 mt-1">{{ $this->analyticsData['email'] ?? 'N/A' }}</p>
                    <div class="mt-3 flex gap-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium {{ 
                            $this->analyticsData['role'] === 'quiz-master' ? 'bg-purple-100 text-purple-800' : 'bg-blue-100 text-blue-800'
                        }}">
                            {{ ucfirst(str_replace('-', ' ', $this->analyticsData['role'] ?? 'member')) }}
                        </span>
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                            Active
                        </span>
                    </div>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-600">Member Since</p>
                    <p class="text-lg font-semibold">{{ $this->analyticsData['joined_at'] ?? 'N/A' }}</p>
                    <p class="text-xs text-gray-500 mt-1">{{ $this->analyticsData['joined_at_relative'] ?? '' }}</p>
                </div>
            </div>
        </div>

        <!-- Key Metrics -->
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <!-- Total Attempts -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <p class="text-sm font-medium text-gray-600">Total Attempts</p>
                <p class="text-3xl font-bold mt-2 text-blue-600">{{ $this->analyticsData['quizzes']['total_attempts'] ?? 0 }}</p>
            </div>

            <!-- Average Score -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <p class="text-sm font-medium text-gray-600">Average Score</p>
                <p class="text-3xl font-bold mt-2 text-green-600">{{ $this->analyticsData['quizzes']['avg_score'] ?? 0 }}%</p>
            </div>

            <!-- Highest Score -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <p class="text-sm font-medium text-gray-600">Highest Score</p>
                <p class="text-3xl font-bold mt-2 text-purple-600">{{ $this->analyticsData['quizzes']['highest_score'] ?? 0 }}%</p>
            </div>

            <!-- Lowest Score -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <p class="text-sm font-medium text-gray-600">Lowest Score</p>
                <p class="text-3xl font-bold mt-2 text-orange-600">{{ $this->analyticsData['quizzes']['lowest_score'] ?? 0 }}%</p>
            </div>
        </div>

        <!-- Score Distribution -->
        <div class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-lg font-semibold mb-4">Score Distribution</h3>
            <div class="space-y-3">
                @php
                    $scoreRanges = $this->analyticsData['score_distribution'] ?? [];
                    $maxCount = max(array_values($scoreRanges) ?: [1]);
                @endphp

                @forelse ($scoreRanges as $range => $count)
                    <div class="flex items-center gap-4">
                        <span class="text-sm font-medium text-gray-700 w-16">{{ $range }}%</span>
                        <div class="flex-1 bg-gray-200 rounded-full h-8 overflow-hidden">
                            <div 
                                class="bg-gradient-to-r from-blue-500 to-blue-600 h-full flex items-center justify-end pr-2 transition-all" 
                                style="width: {{ $maxCount > 0 ? ($count / $maxCount) * 100 : 0 }}%"
                            >
                                @if (($count / $maxCount) * 100 > 20)
                                    <span class="text-xs font-semibold text-white">{{ $count }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="text-sm text-gray-600 w-12 text-right">{{ $count }}</span>
                    </div>
                @empty
                    <p class="text-gray-600 text-center py-8">No quiz attempts yet</p>
                @endforelse
            </div>
        </div>

        <!-- Activity Trend -->
        <div class="rounded-lg bg-white p-6 shadow">
            <h3 class="text-lg font-semibold mb-4">7-Day Activity Trend</h3>
            <div class="space-y-3">
                @php
                    $activityTrend = $this->analyticsData['activity_trend'] ?? [];
                    $maxActivity = max(array_values($activityTrend) ?: [1]);
                @endphp

                @forelse ($activityTrend as $date => $count)
                    <div class="flex items-center gap-4">
                        <span class="text-sm text-gray-700 w-20">{{ \Carbon\Carbon::parse($date)->format('M d') }}</span>
                        <div class="flex-1 bg-gray-200 rounded-full h-6 overflow-hidden">
                            <div 
                                class="bg-gradient-to-r from-green-500 to-green-600 h-full flex items-center justify-end pr-2 transition-all" 
                                style="width: {{ $maxActivity > 0 ? ($count / $maxActivity) * 100 : 0 }}%"
                            >
                                @if (($count / $maxActivity) * 100 > 20 && $count > 0)
                                    <span class="text-xs font-semibold text-white">{{ $count }}</span>
                                @endif
                            </div>
                        </div>
                        <span class="text-sm text-gray-600 w-8 text-right">{{ $count }}</span>
                    </div>
                @empty
                    <p class="text-gray-600 text-center py-8">No activity recorded</p>
                @endforelse
            </div>
        </div>

        <!-- Last Activity -->
        @if ($this->analyticsData['last_activity'])
            <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                <p class="text-sm text-blue-900">
                    <strong>📊 Last Activity:</strong> {{ $this->analyticsData['last_activity'] }} ({{ $this->analyticsData['last_activity_relative'] ?? '' }})
                </p>
            </div>
        @endif
    </div>
@endsection
