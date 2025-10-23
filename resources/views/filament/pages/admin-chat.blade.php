<x-filament::page>
    <div id="admin-chat-root" class="h-[700px]"> 
        <!-- Minimal mounting point for the Vue admin chat app -->
        <div id="admin-chat-app" class="h-full"></div>
    </div>

    @push('scripts')
        <script>
            // Expose admin id for Echo subscription (null or id)
            window.__ADMIN_ID__ = {!! json_encode(auth()->id()) !!};
        </script>

        {{-- Include global CSS and the admin-chat entry so styles are available --}}
        @vite(['resources/css/app.css', 'resources/js/filament/admin-chat.js'])
    @endpush
</x-filament::page>