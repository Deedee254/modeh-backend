@extends('filament::page')

@section('content')
<div id="admin-chat-root" class="h-[700px]">
  <!-- Minimal mounting point for the Vue admin chat app -->
  <div id="admin-chat-app" class="h-full"></div>
</div>

@push('scripts')
<script>
  // Expose admin id for Echo subscription
  window.__ADMIN_ID__ = {{ auth()->id() ?? 'null' }}
</script>
@if (file_exists(public_path('build/admin-chat.js')))
  <script src="{{ asset('build/admin-chat.js') }}" defer></script>
@else
  <script src="/resources/js/filament/admin-chat.js" type="module"></script>
@endif
@endpush

@endsection
