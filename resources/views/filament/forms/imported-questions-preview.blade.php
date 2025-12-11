@if(!empty($questions) && is_array($questions))
    <div class="overflow-x-auto">
        <table class="w-full text-sm border-collapse">
            <thead>
                <tr class="bg-gray-100 border-b border-gray-300">
                    <th class="px-3 py-2 text-left font-semibold text-gray-700">#</th>
                    <th class="px-3 py-2 text-left font-semibold text-gray-700">Question</th>
                    <th class="px-3 py-2 text-center font-semibold text-gray-700">Options</th>
                    <th class="px-3 py-2 text-center font-semibold text-gray-700">Answer</th>
                    <th class="px-3 py-2 text-center font-semibold text-gray-700">Marks</th>
                    <th class="px-3 py-2 text-center font-semibold text-gray-700">Difficulty</th>
                </tr>
            </thead>
            <tbody>
                @foreach($questions as $index => $question)
                    <tr class="border-b border-gray-200 hover:bg-gray-50">
                        <td class="px-3 py-2 text-gray-600">{{ $index + 1 }}</td>
                        <td class="px-3 py-2 text-gray-800">
                            <div class="max-w-xs line-clamp-2">{{ $question['body'] ?? 'N/A' }}</div>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                                {{ count($question['options'] ?? []) }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            @php
                                $correctIndex = $question['correct'] ?? null;
                                $options = $question['options'] ?? [];
                                $answer = isset($options[$correctIndex]) ? $options[$correctIndex] : 'N/A';
                            @endphp
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium bg-green-100 text-green-800">
                                {{ $answer }}
                            </span>
                        </td>
                        <td class="px-3 py-2 text-center text-gray-800">
                            <span class="font-semibold">{{ $question['marks'] ?? 1 }}</span>
                        </td>
                        <td class="px-3 py-2 text-center">
                            @php
                                $difficulty = $question['difficulty'] ?? 2;
                                $difficultyText = match($difficulty) {
                                    1 => 'Easy',
                                    2 => 'Medium',
                                    3 => 'Hard',
                                    default => 'Medium'
                                };
                                $difficultyColor = match($difficulty) {
                                    1 => 'bg-green-100 text-green-800',
                                    2 => 'bg-yellow-100 text-yellow-800',
                                    3 => 'bg-red-100 text-red-800',
                                    default => 'bg-gray-100 text-gray-800'
                                };
                            @endphp
                            <span class="inline-flex items-center px-2 py-1 rounded text-xs font-medium {{ $difficultyColor }}">
                                {{ $difficultyText }}
                            </span>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        
        <div class="mt-3 p-3 bg-blue-50 border border-blue-200 rounded">
            <p class="text-sm text-blue-800">
                <strong>{{ count($questions) }} question(s)</strong> ready to be imported and attached to this tournament.
            </p>
        </div>
    </div>
@endif
