<x-app-layout>
    @section('subtitle', $hikvision->name)
    @section('title', 'Alcohol Detection Settings')

    <div class="max-w-2xl space-y-5">

        {{-- Breadcrumb + status --}}
        <div class="flex items-center gap-3 flex-wrap">
            <a href="{{ route('hikvision.index') }}" class="text-sm text-gray-500 hover:text-gray-700 flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
                Terminals
            </a>
            <span class="text-gray-300">/</span>
            <span class="text-sm text-gray-700 font-medium">{{ $hikvision->name }}</span>

            @if(! $terminalOnline)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-red-50 text-red-600 border border-red-200 rounded-full">
                    <span class="w-1.5 h-1.5 bg-red-500 rounded-full"></span>
                    Terminal offline — settings saved locally
                </span>
            @elseif(! $moduleDetected)
                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-amber-50 text-amber-700 border border-amber-200 rounded-full">
                    <span class="w-1.5 h-1.5 bg-amber-400 rounded-full"></span>
                    Terminal online · Alcohol module not detected
                </span>
                <span class="text-xs text-gray-400">Settings will be applied when module connects</span>
            @else
                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 border border-green-200 rounded-full">
                    <span class="w-1.5 h-1.5 bg-green-500 rounded-full"></span>
                    Terminal online · Alcohol module connected
                </span>
            @endif
        </div>

        {{-- Alcohol testing toggle --}}
        <div
            x-data="alcoholToggle({{ $params['enabled'] ? 'true' : 'false' }})"
            class="flex items-center justify-between bg-white rounded-lg border border-gray-200 px-5 py-4"
        >
            <div>
                <p class="text-sm font-medium text-gray-900">Alcohol testing</p>
                <p class="text-xs text-gray-400 mt-0.5">Require a breath test before granting access at this terminal</p>
                <p class="mt-1 text-xs text-red-500" x-show="error" x-cloak>Toggled locally, but the terminal didn't confirm the change.</p>
            </div>
            <button
                type="button"
                role="switch"
                :aria-checked="enabled.toString()"
                @click="toggle()"
                :disabled="toggling"
                class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors disabled:opacity-50"
                :class="enabled ? 'bg-indigo-600' : 'bg-gray-200'"
            >
                <span class="sr-only">Toggle alcohol testing</span>
                <span
                    class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                    :class="enabled ? 'translate-x-6' : 'translate-x-1'"
                ></span>
            </button>
        </div>

        <form method="POST" action="{{ route('hikvision.alcohol.update', $hikvision) }}" x-data="alcoholForm()" class="space-y-5">
            @csrf
            @method('PUT')

            {{-- Detection params card --}}
            <div class="bg-white rounded-lg border border-gray-200 divide-y divide-gray-100">

                <div class="px-5 py-4 grid grid-cols-3 gap-4">
                    <div>
                        <x-input-label for="drinkingThreshold" value="Drinking Status Threshold (mg/100ml)" />
                        <x-text-input
                            id="drinkingThreshold"
                            name="drinkingThreshold"
                            type="number"
                            min="0"
                            max="439"
                            step="1"
                            class="mt-1 block w-full"
                            :value="old('drinkingThreshold', $params['drinkingThreshold'])"
                        />
                        <p class="mt-1 text-xs text-gray-400">Default 20 mg/100ml.</p>
                        <x-input-error :messages="$errors->get('drinkingThreshold')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="drunkennessThreshold" value="Drunken Status Threshold (mg/100ml)" />
                        <x-text-input
                            id="drunkennessThreshold"
                            name="drunkennessThreshold"
                            type="number"
                            min="1"
                            max="440"
                            step="1"
                            class="mt-1 block w-full"
                            :value="old('drunkennessThreshold', $params['drunkennessThreshold'])"
                        />
                        <p class="mt-1 text-xs text-gray-400">Default 80 mg/100ml.</p>
                        <x-input-error :messages="$errors->get('drunkennessThreshold')" class="mt-1" />
                    </div>

                    <div>
                        <x-input-label for="timeout" value="Detection Timeout Period (seconds)" />
                        <x-text-input
                            id="timeout"
                            name="timeout"
                            type="number"
                            min="3"
                            max="60"
                            class="mt-1 block w-full"
                            :value="old('timeout', $params['timeout'])"
                        />
                        <p class="mt-1 text-xs text-gray-400">
                            How long the terminal waits for the breath test before timing out.
                        </p>
                        <x-input-error :messages="$errors->get('timeout')" class="mt-1" />
                    </div>
                </div>
            </div>

            {{-- Week plan card --}}
            <div class="bg-white rounded-lg border border-gray-200">
                <div class="px-5 py-4 border-b border-gray-100">
                    <p class="text-sm font-medium text-gray-900">Active schedule</p>
                    <p class="text-xs text-gray-400 mt-0.5">Days and hours when alcohol testing is required at this terminal</p>
                </div>

                <div class="divide-y divide-gray-50">
                    @php
                        $dayLabels = [
                            'monday'    => 'Monday',
                            'tuesday'   => 'Tuesday',
                            'wednesday' => 'Wednesday',
                            'thursday'  => 'Thursday',
                            'friday'    => 'Friday',
                            'saturday'  => 'Saturday',
                            'sunday'    => 'Sunday',
                        ];
                        $weekPlan = $params['weekPlan'] ?? [];
                    @endphp

                    @foreach($dayLabels as $dayKey => $dayLabel)
                        @php
                            $dayCfg = $weekPlan[$dayKey] ?? ['enabled' => false, 'periods' => []];
                            $dayEnabled = old("weekPlan.$dayKey.enabled") !== null
                                ? (bool) old("weekPlan.$dayKey.enabled")
                                : ($dayCfg['enabled'] ?? false);
                            $initialPeriods = old("weekPlan.$dayKey.periods")
                                ?? (($dayCfg['periods'] ?? []) ?: [['beginTime' => '08:00', 'endTime' => '18:00']]);
                        @endphp
                        <div
                            x-data="{ enabled: {{ $dayEnabled ? 'true' : 'false' }}, periods: {{ json_encode(array_values($initialPeriods)) }} }"
                            class="flex items-start gap-4 px-5 py-3"
                        >
                            <label class="flex items-center gap-2.5 w-32 cursor-pointer flex-shrink-0 pt-1.5">
                                <input
                                    type="checkbox"
                                    name="weekPlan[{{ $dayKey }}][enabled]"
                                    value="1"
                                    x-model="enabled"
                                    class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                />
                                <span class="text-sm select-none" :class="enabled ? 'text-gray-900 font-medium' : 'text-gray-400'">
                                    {{ $dayLabel }}
                                </span>
                            </label>

                            <div class="flex-1 space-y-2" :class="enabled ? '' : 'opacity-40 pointer-events-none'">
                                <template x-for="(period, index) in periods" :key="index">
                                    <div class="flex items-center gap-2">
                                        <input
                                            type="text"
                                            inputmode="numeric"
                                            placeholder="HH:MM"
                                            pattern="([01][0-9]|2[0-4]):[0-5][0-9]"
                                            maxlength="5"
                                            :name="`weekPlan[{{ $dayKey }}][periods][${index}][beginTime]`"
                                            x-model="period.beginTime"
                                            class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm py-1.5 px-2 w-20 font-mono"
                                        />
                                        <span class="text-xs text-gray-400">to</span>
                                        <input
                                            type="text"
                                            inputmode="numeric"
                                            placeholder="HH:MM"
                                            pattern="([01][0-9]|2[0-4]):[0-5][0-9]"
                                            maxlength="5"
                                            :name="`weekPlan[{{ $dayKey }}][periods][${index}][endTime]`"
                                            x-model="period.endTime"
                                            class="border-gray-300 focus:border-indigo-500 focus:ring-indigo-500 rounded-md shadow-sm text-sm py-1.5 px-2 w-20 font-mono"
                                        />
                                        <button
                                            type="button"
                                            @click="periods.length > 1 && periods.splice(index, 1)"
                                            x-show="periods.length > 1"
                                            class="text-gray-300 hover:text-red-500 transition-colors"
                                            title="Remove period"
                                        >
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                            </svg>
                                        </button>
                                    </div>
                                </template>
                                <button
                                    type="button"
                                    @click="periods.push({ beginTime: '08:00', endTime: '18:00' })"
                                    class="text-xs text-indigo-600 hover:text-indigo-800 font-medium"
                                >
                                    + Add period
                                </button>
                            </div>

                            <div class="flex-shrink-0 w-14 text-right pt-1.5">
                                <template x-if="enabled">
                                    <span class="text-xs text-emerald-600 font-medium">Active</span>
                                </template>
                                <template x-if="!enabled">
                                    <span class="text-xs text-gray-300">Off</span>
                                </template>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Quick-select --}}
                <div class="px-5 py-3 border-t border-gray-100 flex items-center gap-3">
                    <span class="text-xs text-gray-400">Quick select:</span>
                    <button type="button" @click="setWorkdays(true)"
                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Workdays</button>
                    <button type="button" @click="setAllDays(true)"
                        class="text-xs text-indigo-600 hover:text-indigo-800 font-medium">Every day</button>
                    <button type="button" @click="setAllDays(false)"
                        class="text-xs text-gray-400 hover:text-gray-600">Clear all</button>
                </div>
            </div>

            {{-- Submit --}}
            <div class="flex items-center gap-4">
                <x-primary-button>Save & Push to Terminal</x-primary-button>
                <a href="{{ route('hikvision.index') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                @if(! $moduleDetected && $terminalOnline)
                    <span class="text-xs text-amber-600">
                        Settings will be saved locally and pushed when the module is connected.
                    </span>
                @endif
            </div>
        </form>
    </div>

    <script>
        function alcoholToggle(initialEnabled) {
            return {
                enabled: initialEnabled,
                toggling: false,
                error: false,
                toggle() {
                    if (this.toggling) return;
                    this.toggling = true;
                    this.error = false;

                    fetch('{{ route('hikvision.alcohol.toggle', $hikvision) }}', {
                        method: 'PATCH',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        },
                    })
                        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                        .then(({ ok, data }) => {
                            this.enabled = data.enabled;
                            this.error = !ok || !data.pushed;
                        })
                        .catch(() => { this.error = true; })
                        .finally(() => { this.toggling = false; });
                },
            };
        }

        function alcoholForm() {
            return {
                setAllDays(state) {
                    document.querySelectorAll('[x-data]').forEach(el => {
                        const checkbox = el.querySelector('input[type="checkbox"][name*="weekPlan"]');
                        if (checkbox) {
                            const comp = Alpine.$data(el);
                            if (typeof comp.enabled !== 'undefined') {
                                comp.enabled = state;
                            }
                        }
                    });
                },
                setWorkdays(state) {
                    const workdays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
                    document.querySelectorAll('[x-data]').forEach(el => {
                        const checkbox = el.querySelector('input[type="checkbox"][name*="weekPlan"]');
                        if (checkbox) {
                            const day = (checkbox.name.match(/weekPlan\[(\w+)\]/) || [])[1];
                            const comp = Alpine.$data(el);
                            if (day && typeof comp.enabled !== 'undefined') {
                                comp.enabled = workdays.includes(day) ? state : false;
                            }
                        }
                    });
                },
            };
        }
    </script>
</x-app-layout>
