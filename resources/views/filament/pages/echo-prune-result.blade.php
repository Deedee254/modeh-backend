<div class="p-4 space-y-3">
    @php $r = $result ?? [] @endphp
    @if(isset($r['error']))
        <div class="text-red-600 font-semibold">Error</div>
        <div class="text-sm text-gray-700 whitespace-pre-wrap">{{ $r['error'] }}</div>
    @else
        <div class="flex items-center justify-between">
            <div class="text-lg font-semibold">Prune Results</div>
            <div class="text-sm text-gray-500">Exit: {{ $r['exit'] ?? 'n/a' }}</div>
        </div>
        <div class="text-sm text-gray-700">Output:</div>
        <pre class="text-xs bg-gray-100 p-3 rounded whitespace-pre-wrap">{{ $r['output'] ?? 'No output' }}</pre>
    @endif
</div>