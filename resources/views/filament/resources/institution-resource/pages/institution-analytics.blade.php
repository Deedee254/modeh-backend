@extends('filament::layouts.page')

@section('content')
    <div class="space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-3xl font-bold">{{ $this->getHeading() }}</h1>
            <p class="text-gray-600 mt-2">Comprehensive analytics dashboard for your institution</p>
        </div>

        <!-- Key Metrics Grid -->
        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <!-- Total Members -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Total Members</p>
                        <p class="text-3xl font-bold mt-2">{{ $this->analyticsData['members']['total'] ?? 0 }}</p>
                    </div>
                    <div class="text-blue-600 opacity-20">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10.5 1.5H3.75A2.25 2.25 0 001.5 3.75v12.5A2.25 2.25 0 003.75 18.5h12.5a2.25 2.25 0 002.25-2.25V9.5M10.5 1.5v4M10.5 1.5l6.5 6.5m-4.25-2.75a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Quizees -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Quizees</p>
                        <p class="text-3xl font-bold mt-2 text-blue-600">{{ $this->analyticsData['members']['quizees'] ?? 0 }}</p>
                    </div>
                    <div class="text-blue-600 opacity-20">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v4h8v-4zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Quiz Masters -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Quiz Masters</p>
                        <p class="text-3xl font-bold mt-2 text-purple-600">{{ $this->analyticsData['members']['quiz_masters'] ?? 0 }}</p>
                    </div>
                    <div class="text-purple-600 opacity-20">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M10.5 1.5H3.75A2.25 2.25 0 001.5 3.75v12.5A2.25 2.25 0 003.75 18.5h12.5a2.25 2.25 0 002.25-2.25V9.5M10.5 1.5v4M10.5 1.5l6.5 6.5M7 10l3 3m0 0l3-3m-3 3v-6"></path>
                        </svg>
                    </div>
                </div>
            </div>

            <!-- Active This Week -->
            <div class="rounded-lg bg-white p-6 shadow hover:shadow-lg transition">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-600">Active This Week</p>
                        <p class="text-3xl font-bold mt-2 text-green-600">{{ $this->analyticsData['members']['active_this_week'] ?? 0 }}</p>
                    </div>
                    <div class="text-green-600 opacity-20">
                        <svg class="w-12 h-12" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M12 2C6.477 2 2 6.484 2 12s4.477 10 10 10c5.514 0 10-4.486 10-10S17.514 2 12 2zm3.707 8.207l-5 5a1 1 0 01-1.414 0l-2-2a1 1 0 011.414-1.414L10 12.586l4.293-4.293a1 1 0 011.414 1.414z" clip-rule="evenodd"></path>
                        </svg>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secondary Metrics -->
        <div class="grid gap-4 md:grid-cols-2">
            <!-- Quiz Statistics -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="text-lg font-semibold mb-4">Quiz Statistics</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center pb-4 border-b">
                        <span class="text-gray-600">Total Attempts</span>
                        <span class="text-2xl font-bold text-blue-600">{{ $this->analyticsData['quizzes']['total_attempts'] ?? 0 }}</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Average Score</span>
                        <span class="text-2xl font-bold text-green-600">{{ $this->analyticsData['quizzes']['avg_score'] ?? 0 }}%</span>
                    </div>
                </div>
            </div>

            <!-- Seat Utilization -->
            <div class="rounded-lg bg-white p-6 shadow">
                <h3 class="text-lg font-semibold mb-4">Seat Utilization</h3>
                <div class="space-y-4">
                    <div class="flex justify-between items-center">
                        <span class="text-gray-600">Assigned</span>
                        <span class="font-bold">{{ $this->analyticsData['subscription']['seats_assigned'] ?? 0 }} / {{ $this->analyticsData['subscription']['seats_total'] ?? 0 }}</span>
                    </div>
                    <div class="w-full bg-gray-200 rounded-full h-3">
                        <div 
                            class="bg-gradient-to-r from-blue-500 to-blue-600 h-3 rounded-full transition-all" 
                            style="width: {{ min($this->analyticsData['subscription']['utilization_rate'] ?? 0, 100) }}%"
                        ></div>
                    </div>
                    <div class="flex justify-between items-center text-sm">
                        <span class="text-gray-600">Utilization</span>
                        <span class="font-semibold">{{ $this->analyticsData['subscription']['utilization_rate'] ?? 0 }}%</span>
                    </div>
                    <p class="text-sm text-gray-500 pt-2">{{ $this->analyticsData['subscription']['seats_available'] ?? 0 }} seats available</p>
                </div>
            </div>
        </div>

        <!-- Activity Overview -->
        <div class="rounded-lg bg-white shadow">
            <div class="border-b px-6 py-4">
                <h3 class="text-lg font-semibold">Activity Overview</h3>
            </div>
            <div class="p-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-600 font-medium mb-1">ACTIVE TODAY</p>
                        <p class="text-3xl font-bold text-blue-600">{{ $this->analyticsData['members']['active_today'] ?? 0 }}</p>
                    </div>
                    <div class="bg-green-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-600 font-medium mb-1">ACTIVE WEEK</p>
                        <p class="text-3xl font-bold text-green-600">{{ $this->analyticsData['members']['active_this_week'] ?? 0 }}</p>
                    </div>
                    <div class="bg-purple-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-600 font-medium mb-1">TOTAL ATTEMPTS</p>
                        <p class="text-3xl font-bold text-purple-600">{{ $this->analyticsData['quizzes']['total_attempts'] ?? 0 }}</p>
                    </div>
                    <div class="bg-orange-50 p-4 rounded-lg">
                        <p class="text-xs text-gray-600 font-medium mb-1">AVG SCORE</p>
                        <p class="text-3xl font-bold text-orange-600">{{ $this->analyticsData['quizzes']['avg_score'] ?? 0 }}%</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
            <p class="text-sm text-blue-900">
                <strong>💡 Tip:</strong> Navigate to individual members to view their detailed analytics, performance trends, and activity history.
            </p>
        </div>
    </div>
@endsection

                            <div class="px-6 py-8 text-center text-gray-600">
                                No performance data available
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif
        @endif
    </div>
@endsection
