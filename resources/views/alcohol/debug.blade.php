<x-app-layout>
    @section('title', 'Alcohol Debug')
    @section('subtitle', 'Live device state for handing to Hikvision firmware support')

    <div class="bg-white rounded-lg border border-gray-200 px-5 py-4 mb-4">
        <p class="text-sm text-gray-700">
            <span class="font-medium">{{ $employee->full_name }}</span>
            <span class="text-gray-400">(emp_code {{ $employee->emp_code }})</span>
            @if($terminal)
                — terminal <span class="font-medium">{{ $terminal->name }}</span>
            @else
                — <span class="text-red-600">no alcohol-enabled terminal linked</span>
            @endif
        </p>
    </div>

    <div class="flex items-center gap-3 mb-4">
        <form method="POST" action="{{ route('alcohol.debug.snapshot', $employee) }}">
            @csrf
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 rounded-lg transition-colors">
                Get live info from terminal
            </button>
        </form>

        <form method="POST" action="{{ route('alcohol.debug.reset-skip', $employee) }}">
            @csrf
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-amber-600 hover:bg-amber-700 rounded-lg transition-colors">
                Reset skip alcohol
            </button>
        </form>

        <form method="POST" action="{{ route('alcohol.debug.randomize-name', $employee) }}">
            @csrf
            <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-lg transition-colors">
                Randomize name &amp; push to terminal
            </button>
        </form>
    </div>

    <p class="text-xs text-gray-500 mb-4">
        Workflow: click <span class="font-medium">Reset skip alcohol</span>, then badge/blow physically at the
        terminal, then click <span class="font-medium">Get live info from terminal</span> to see whether it
        recorded the skip.
    </p>

    @if($snapshot)
        <div class="bg-white rounded-lg shadow border border-gray-200 p-4 mb-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-2">App state (captured {{ $snapshot['captured_at'] ?? '' }})</h3>
            @if(isset($snapshot['error']))
                <p class="text-xs text-red-600">{{ $snapshot['error'] }}</p>
            @else
                <pre class="text-xs bg-gray-50 border border-gray-100 rounded p-3 overflow-x-auto">{{ json_encode($snapshot['db'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
            @endif
        </div>

        @if(isset($snapshot['raw']))
            @foreach($snapshot['raw'] as $endpoint => $body)
                <div class="bg-white rounded-lg shadow border border-gray-200 p-4 mb-4">
                    <h3 class="text-sm font-semibold text-gray-700 mb-2">Raw terminal response — {{ $endpoint }}</h3>
                    @if($body)
                        <pre class="text-xs bg-gray-50 border border-gray-100 rounded p-3 overflow-x-auto">{{ $body }}</pre>
                    @else
                        <p class="text-xs text-gray-400">No response.</p>
                    @endif
                </div>
            @endforeach
        @endif
    @else
        <div class="bg-white rounded-lg shadow border border-gray-200 p-4">
            <p class="text-xs text-gray-400">No snapshot yet.</p>
        </div>
    @endif
</x-app-layout>
