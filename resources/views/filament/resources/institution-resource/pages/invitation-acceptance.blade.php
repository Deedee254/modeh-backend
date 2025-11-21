@extends('filament::layouts.page')

@section('content')
    <div class="mx-auto max-w-md">
        @if ($this->isLoading)
            <div class="rounded-lg bg-white p-8 shadow">
                <div class="flex flex-col items-center justify-center space-y-4">
                    <div class="h-8 w-8 animate-spin rounded-full border-4 border-gray-300 border-t-blue-600"></div>
                    <p class="text-gray-600">Loading invitation details...</p>
                </div>
            </div>
        @elseif ($this->error)
            <div class="rounded-lg bg-white p-8 shadow">
                <div class="flex flex-col items-center space-y-4">
                    <svg class="h-12 w-12 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4v.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <h2 class="text-lg font-semibold text-gray-900">Invitation Error</h2>
                    <p class="text-center text-gray-600">{{ $this->error }}</p>
                    <a href="{{ route('filament.admin.dashboard') }}" class="mt-4 inline-flex items-center justify-center rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700">
                        Back to Dashboard
                    </a>
                </div>
            </div>
        @elseif ($this->invitationData)
            <div class="rounded-lg bg-white p-8 shadow">
                <div class="text-center">
                    @if ($this->invitationData['institution']['logo_url'] ?? null)
                        <img src="{{ $this->invitationData['institution']['logo_url'] }}" alt="Institution Logo" class="mx-auto mb-4 h-16 w-16 rounded-lg object-cover">
                    @else
                        <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-lg bg-gray-200">
                            <svg class="h-8 w-8 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                    @endif
                    <h2 class="text-2xl font-bold text-gray-900">{{ $this->invitationData['institution']['name'] }}</h2>
                    <p class="mt-2 text-gray-600">You have been invited to join this institution</p>
                </div>

                <div class="mt-6 space-y-4">
                    <div class="rounded-lg bg-gray-50 p-4">
                        <p class="text-sm font-medium text-gray-600">Subscription Tier</p>
                        <p class="mt-1 text-lg font-semibold capitalize text-gray-900">{{ $this->invitationData['subscription_tier'] }}</p>
                    </div>

                    @if ($this->invitationData['invited_email'] ?? null)
                        <div class="rounded-lg bg-gray-50 p-4">
                            <p class="text-sm font-medium text-gray-600">Invitation Sent To</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">{{ $this->invitationData['invited_email'] }}</p>
                        </div>
                    @endif

                    @if ($this->invitationData['expires_at'] ?? null)
                        <div class="rounded-lg bg-gray-50 p-4">
                            <p class="text-sm font-medium text-gray-600">Expires</p>
                            <p class="mt-1 text-lg font-semibold text-gray-900">
                                {{ \Carbon\Carbon::parse($this->invitationData['expires_at'])->format('M d, Y') }}
                            </p>
                        </div>
                    @endif
                </div>

                @if (auth()->check())
                    <div class="mt-8 flex gap-4">
                        <button wire:click="acceptInvitation" wire:loading.attr="disabled" class="flex-1 rounded-lg bg-blue-600 px-4 py-2 text-white hover:bg-blue-700 disabled:opacity-50">
                            <span wire:loading.remove>Accept Invitation</span>
                            <span wire:loading><svg class="inline-block h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg></span>
                        </button>
                        <button wire:click="declineInvitation" class="flex-1 rounded-lg border border-gray-300 px-4 py-2 text-gray-700 hover:bg-gray-50">
                            Decline
                        </button>
                    </div>
                @else
                    <div class="mt-8">
                        <p class="mb-4 text-center text-gray-600">Please log in to accept this invitation</p>
                        <a href="{{ route('login') . '?invitation=' . $this->token }}" class="block w-full rounded-lg bg-blue-600 px-4 py-2 text-center text-white hover:bg-blue-700">
                            Log In
                        </a>
                        <p class="mt-4 text-center text-sm text-gray-600">
                            Don't have an account? <a href="{{ route('register') }}" class="text-blue-600 hover:underline">Sign up</a>
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </div>
@endsection
