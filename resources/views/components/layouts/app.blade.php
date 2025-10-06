{{-- Minimal layout used by Livewire page components when Filament pages expect components.layouts.app --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'Laravel') }} - Admin</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('styles')
</head>
<body class="antialiased">
    {{-- If Filament is installed, wrap slot with filament layout container; otherwise render slot directly --}}
    @if(View::exists('filament::layout'))
        {{-- Use Filament's layout if available to preserve admin styling --}}
        @includeIf('filament::layout', ['slot' => $slot])
    @else
        <div class="min-h-screen bg-gray-100">
            <main class="container mx-auto py-6">
                {{ $slot }}
            </main>
        </div>
    @endif

    @stack('scripts')
</body>
</html>