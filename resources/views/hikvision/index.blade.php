<x-app-layout>
    @section('subtitle', 'Manage Hikvision terminals')
    @section('title', 'Terminals')

    <div class="flex items-center justify-between bg-white rounded-lg border border-gray-200 px-5 py-4 mb-5">
        <p class="text-sm text-gray-500">{{ $terminals->total() }} terminal(s)</p>
        <div class="flex items-center gap-2">
            <a href="{{ route('hikvision.sync.index') }}" class="px-4 py-2 text-sm font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 border border-gray-200 rounded-lg transition-colors">
                Sync
            </a>
            <a href="{{ route('hikvision.create') }}" style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;text-decoration:none;display:inline-block">
                Add Terminal
            </a>
        </div>
    </div>

    <div class="space-y-2">
        @forelse($terminals as $terminal)
            <div
                x-data="{
                    checking: false, online: null, personCount: null,
                    showEmployees: false, loadingEmployees: false, employees: [], employeeSearch: '', deleting: false,
                    resyncingEmp: null, resyncResults: {},
                    errorsModal: { open: false, title: '', list: [], type: '' },
                    errorsResyncing: null, errorsResults: {},
                    errorsCounters: {
                        person: {{ $terminal->sync_stats['persons_not_added'] ?? 0 }},
                        face:   {{ $terminal->sync_stats['faces_not_added'] ?? 0 }},
                        card:   {{ $terminal->sync_stats['cards_not_added'] ?? 0 }},
                    },
                    get filteredEmployees() {
                        if (!this.employeeSearch) return this.employees;
                        const q = this.employeeSearch.toLowerCase();
                        return this.employees.filter(e =>
                            e.full_name.toLowerCase().includes(q) ||
                            String(e.emp_code).includes(q) ||
                            (e.card_no && String(e.card_no).includes(q))
                        );
                    },
                    openEmployees() {
                        this.showEmployees = true;
                        this.employees = [];
                        this.resyncResults = {};
                        this.loadingEmployees = true;
                        fetch('{{ route('hikvision.employees', $terminal) }}')
                            .then(r => r.json())
                            .then(d => { this.employees = d; })
                            .catch(() => { this.employees = []; })
                            .finally(() => { this.loadingEmployees = false; });
                    },
                    openErrors(type, title, list) {
                        if (!list || list.length === 0) return;
                        this.errorsModal = { open: true, title, list: [...list], type };
                        this.errorsResults = {};
                    },
                    resyncErrorEmployee(empCode) {
                        if (this.errorsResyncing === empCode) return;
                        this.errorsResyncing = empCode;
                        fetch('{{ route('hikvision.employees.resync', $terminal) }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                            },
                            body: JSON.stringify({ emp_code: String(empCode) })
                        })
                        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                        .then(({ ok, data }) => {
                            if (ok) {
                                this.errorsResults[empCode] = { success: true, card_no: data.card_no, has_face: data.has_face };
                                const type = this.errorsModal.type;
                                if (this.errorsCounters[type] > 0) this.errorsCounters[type]--;
                            } else {
                                this.errorsResults[empCode] = { success: false, error: data.error || 'error' };
                            }
                        })
                        .catch(() => { this.errorsResults[empCode] = { success: false, error: 'error' }; })
                        .finally(() => { this.errorsResyncing = null; });
                    },
                    resyncEmployee(empCode) {
                        if (this.resyncingEmp === empCode) return;
                        this.resyncingEmp = empCode;
                        delete this.resyncResults[empCode];
                        fetch('{{ route('hikvision.employees.resync', $terminal) }}', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content
                            },
                            body: JSON.stringify({ emp_code: empCode })
                        })
                        .then(r => r.json().then(d => ({ ok: r.ok, data: d })))
                        .then(({ ok, data }) => {
                            this.resyncResults[empCode] = ok ? 'success' : (data.error || 'error');
                            if (ok) {
                                const idx = this.employees.findIndex(e => e.emp_code === empCode);
                                if (idx !== -1) {
                                    this.employees[idx] = { ...this.employees[idx], card_no: data.card_no, has_face: data.has_face };
                                }
                            }
                        })
                        .catch(() => { this.resyncResults[empCode] = 'error'; })
                        .finally(() => { this.resyncingEmp = null; });
                    },
                }"
                class="bg-white rounded-lg border border-gray-200 overflow-hidden"
            >
                <div class="flex items-center px-5 py-4 gap-3">
                    {{-- Main info --}}
                    <div class="flex items-center gap-3 flex-1 min-w-0">
                        <div class="min-w-0">
                            <span class="text-sm font-medium text-gray-800 truncate block">{{ $terminal->name }}</span>
                            @if($terminal->location)
                                <span class="text-xs text-gray-400 truncate block">{{ $terminal->location }}</span>
                            @endif
                        </div>

                        <span class="hidden sm:inline text-xs text-gray-400 font-mono flex-shrink-0" title="Terminal ID — used in the event webhook URL (/api/hikvision/{id}/events/...)">
                            #{{ $terminal->id }}
                        </span>

                        <span class="hidden sm:inline text-xs text-gray-500 font-mono flex-shrink-0 bg-gray-100 px-2 py-0.5 rounded">
                            {{ $terminal->protocol }}://{{ $terminal->ip }}:{{ $terminal->port }}
                        </span>

                        @if($terminal->accessPoint)
                            <span class="hidden md:inline text-xs text-gray-500 flex-shrink-0">
                                {{ $terminal->accessPoint->name }}
                            </span>
                        @endif

                        {{-- Live connection badge --}}
                        <span
                            x-show="online !== null"
                            x-cloak
                            :class="online ? 'bg-green-50 text-green-700 border-green-200' : 'bg-red-50 text-red-700 border-red-200'"
                            class="inline-flex flex-shrink-0 px-2 py-0.5 text-xs font-medium rounded-full border"
                            x-text="online ? 'Online · ' + personCount + ' persons' : 'Offline'"
                        ></span>

                    </div>

                    {{-- Active status dot --}}
                    <span class="flex-shrink-0 w-2 h-2 rounded-full {{ $terminal->is_active ? 'bg-green-400' : 'bg-gray-300' }}"></span>

                    {{-- Actions --}}
                    <div class="flex items-center gap-1.5 flex-shrink-0">
                        {{-- Employees --}}
                        <button
                            type="button"
                            @click="openEmployees()"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-violet-700 bg-violet-50 hover:bg-violet-100 border border-violet-200 rounded-md transition-colors cursor-pointer"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
                            </svg>
                            Employees
                        </button>

                        {{-- Check connection --}}
                        <button
                            type="button"
                            @click="
                                checking = true; online = null;
                                fetch('{{ route('hikvision.check-connection', $terminal) }}')
                                    .then(r => r.json())
                                    .then(d => { online = d.online; personCount = d.person_count; })
                                    .catch(() => { online = false; personCount = null; })
                                    .finally(() => { checking = false; })
                            "
                            :disabled="checking"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-50 hover:bg-gray-100 border border-gray-200 rounded-md transition-colors cursor-pointer disabled:opacity-50"
                        >
                            <svg x-show="!checking" class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                            </svg>
                            <svg x-show="checking" x-cloak class="animate-spin h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                            </svg>
                            Check
                        </button>


                        {{-- Alcohol testing --}}
                        <a
                            href="{{ route('hikvision.alcohol', $terminal) }}"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 hover:bg-amber-100 border border-amber-200 rounded-md transition-colors"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.5 6h9.75M10.5 6a1.5 1.5 0 11-3 0m3 0a1.5 1.5 0 10-3 0M3.75 6H7.5m3 12h9.75m-9.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-3.75 0H7.5m9-6h3.75m-3.75 0a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m-9.75 0h9.75"/>
                            </svg>
                            Alcohol
                        </a>

                        {{-- Edit --}}
                        <a
                            href="{{ route('hikvision.edit', $terminal) }}"
                            class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-md transition-colors"
                        >
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                            Edit
                        </a>

                        {{-- Delete --}}
                        <form method="POST" action="{{ route('hikvision.destroy', $terminal) }}" onsubmit="return confirm('Delete this terminal?')">
                            @csrf
                            @method('DELETE')
                            <button
                                type="submit"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-medium text-red-600 bg-red-50 hover:bg-red-100 border border-red-200 rounded-md transition-colors cursor-pointer"
                            >
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                                Delete
                            </button>
                        </form>
                    </div>
                </div>

                {{-- Sync stats --}}
                @if($terminal->sync_stats)
                    @php $s = $terminal->sync_stats; @endphp
                    <div class="px-5 pt-2 mb-2 flex items-center gap-6 border-t border-gray-50">
                        <span class="text-xs text-gray-400 font-medium uppercase tracking-wide flex-shrink-0">Last sync</span>
                        <div class="flex items-center gap-4 flex-wrap">

                            {{-- Person --}}
                            <div class="flex items-center gap-1.5 text-xs">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                                </svg>
                                <span class="text-gray-500">Person</span>
                                <span class="font-semibold text-green-600">{{ $s['persons_added'] }}</span>
                                <span class="text-gray-300">/</span>
                                @if($s['persons_not_added'] > 0)
                                    <button type="button"
                                        x-show="errorsCounters.person > 0"
                                        @click="openErrors('person', 'Persons not added', {{ json_encode($s['persons_failed'] ?? []) }})"
                                        class="font-semibold text-red-500 hover:text-red-700 hover:underline cursor-pointer"
                                        x-text="errorsCounters.person">
                                    </button>
                                    <span x-show="errorsCounters.person === 0" x-cloak class="font-semibold text-gray-400">0</span>
                                @else
                                    <span class="font-semibold text-gray-400">0</span>
                                @endif
                            </div>

                            {{-- Face --}}
                            <div class="flex items-center gap-1.5 text-xs">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                </svg>
                                <span class="text-gray-500">Face</span>
                                <span class="font-semibold text-green-600">{{ $s['faces_added'] }}</span>
                                <span class="text-gray-300">/</span>
                                @if($s['faces_not_added'] > 0)
                                    <button type="button"
                                        x-show="errorsCounters.face > 0"
                                        @click="openErrors('face', 'Faces not uploaded', {{ json_encode($s['faces_failed'] ?? []) }})"
                                        class="font-semibold text-red-500 hover:text-red-700 hover:underline cursor-pointer"
                                        x-text="errorsCounters.face">
                                    </button>
                                    <span x-show="errorsCounters.face === 0" x-cloak class="font-semibold text-gray-400">0</span>
                                @else
                                    <span class="font-semibold text-gray-400">0</span>
                                @endif
                                @if(($s['guests_skipped'] ?? 0) > 0)
                                    <span class="text-gray-300 mx-0.5">·</span>
                                    <span class="text-gray-400" title="Guest/reception badge records — no photo expected">{{ $s['guests_skipped'] }} guest(s) skipped</span>
                                @endif
                            </div>

                            {{-- Card --}}
                            <div class="flex items-center gap-1.5 text-xs">
                                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                </svg>
                                <span class="text-gray-500">Card</span>
                                <span class="font-semibold text-green-600">{{ $s['cards_added'] }}</span>
                                <span class="text-gray-300">/</span>
                                @if($s['cards_not_added'] > 0)
                                    <button type="button"
                                        x-show="errorsCounters.card > 0"
                                        @click="openErrors('card', 'Cards not synced', {{ json_encode($s['cards_failed'] ?? []) }})"
                                        class="font-semibold text-red-500 hover:text-red-700 hover:underline cursor-pointer"
                                        x-text="errorsCounters.card">
                                    </button>
                                    <span x-show="errorsCounters.card === 0" x-cloak class="font-semibold text-gray-400">0</span>
                                @else
                                    <span class="font-semibold text-gray-400">0</span>
                                @endif
                            </div>

                        </div>
                        <span class="ml-auto text-xs text-gray-300 flex-shrink-0">{{ \Carbon\Carbon::parse($s['synced_at'])->diffForHumans() }}</span>
                    </div>

                    {{-- Errors modal --}}
                    <div
                        x-show="errorsModal.open"
                        x-cloak
                        x-transition.opacity
                        class="fixed inset-0 z-50 flex items-center justify-center"
                        style="background:rgba(0,0,0,0.45)"
                        @click.self="errorsModal.open = false"
                    >
                        <div
                            x-show="errorsModal.open"
                            x-transition
                            @click.stop
                            class="bg-white rounded-xl shadow-2xl w-full max-w-2xl flex flex-col"
                            style="margin:1rem;max-height:80vh;overflow:hidden"
                        >
                            {{-- Header --}}
                            <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                                <div>
                                    <h3 class="text-sm font-semibold text-gray-900" x-text="errorsModal.title"></h3>
                                    <p class="text-xs text-gray-400 mt-0.5">{{ $terminal->name }}</p>
                                </div>
                                <button @click="errorsModal.open = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>

                            {{-- Body --}}
                            <div class="overflow-y-auto flex-1 min-h-0 px-5 py-3">
                                <div x-show="errorsModal.list.length === 0" class="py-10 text-center text-sm text-gray-400">
                                    No employees
                                </div>
                                <div class="divide-y divide-gray-50">
                                    <template x-for="emp in errorsModal.list" :key="emp.emp_code">
                                        <div class="flex items-center gap-3 py-2.5">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm text-gray-800 truncate" x-text="emp.name"></p>
                                                <p class="text-xs text-gray-400" x-text="'#' + emp.emp_code"></p>
                                            </div>
                                            <div class="flex-shrink-0">
                                                <template x-if="errorsResults[emp.emp_code] && errorsResults[emp.emp_code].success">
                                                    <div class="flex items-center gap-2">
                                                        <template x-if="errorsResults[emp.emp_code].has_face">
                                                            <span title="Face uploaded">
                                                                <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                                </svg>
                                                            </span>
                                                        </template>
                                                        <template x-if="errorsResults[emp.emp_code].card_no">
                                                            <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-mono bg-gray-100 text-gray-600 rounded">
                                                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                                                </svg>
                                                                <span x-text="errorsResults[emp.emp_code].card_no"></span>
                                                            </span>
                                                        </template>
                                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs text-green-600 bg-green-50 border border-green-200 rounded-md">
                                                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                            </svg>
                                                            Synced
                                                        </span>
                                                    </div>
                                                </template>
                                                <template x-if="errorsResults[emp.emp_code] && !errorsResults[emp.emp_code].success">
                                                    <span class="inline-flex items-center gap-1 px-2 py-1 text-xs text-red-600 bg-red-50 border border-red-200 rounded-md" :title="errorsResults[emp.emp_code].error">
                                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                        </svg>
                                                        Error
                                                    </span>
                                                </template>
                                                <template x-if="!errorsResults[emp.emp_code]">
                                                    <button
                                                        type="button"
                                                        @click="resyncErrorEmployee(emp.emp_code)"
                                                        :disabled="errorsResyncing === emp.emp_code"
                                                        class="inline-flex items-center gap-1 px-2 py-1 text-xs font-medium text-indigo-600 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded-md transition-colors disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
                                                    >
                                                        <svg x-show="errorsResyncing !== emp.emp_code" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                        </svg>
                                                        <svg x-show="errorsResyncing === emp.emp_code" x-cloak class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                                        </svg>
                                                        Sync
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Employees modal --}}
                <div
                    x-show="showEmployees"
                    x-cloak
                    x-transition.opacity
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    style="background:rgba(0,0,0,0.45)"
                    @click.self="showEmployees = false"
                >
                    <div
                        x-show="showEmployees"
                        x-transition
                        @click.stop
                        class="bg-white rounded-xl shadow-2xl w-full max-w-2xl flex flex-col"
                        style="margin:1rem;max-height:80vh;overflow:hidden"
                    >
                        {{-- Modal header --}}
                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $terminal->name }}</h3>
                                <p class="text-xs text-gray-400 mt-0.5" x-text="employees.length + ' employees linked'"></p>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    x-model="employeeSearch"
                                    type="text"
                                    placeholder="Search..."
                                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 w-40 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                                />
                                <button @click="showEmployees = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        {{-- Modal body --}}
                        <div class="overflow-y-auto flex-1 min-h-0 px-5 py-3">

                            {{-- Loading --}}
                            <div x-show="loadingEmployees" class="flex items-center justify-center py-12 text-sm text-gray-400">
                                <svg class="animate-spin h-5 w-5 mr-2 text-indigo-400" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                Loading...
                            </div>

                            {{-- Empty --}}
                            <div x-show="!loadingEmployees && filteredEmployees.length === 0" class="py-12 text-center text-sm text-gray-400">
                                No employees found
                            </div>

                            {{-- Employee list --}}
                            <div x-show="!loadingEmployees && filteredEmployees.length > 0" class="divide-y divide-gray-50">
                                <template x-for="emp in filteredEmployees" :key="emp.emp_code">
                                    <div class="flex items-center gap-3 py-2.5">
                                        <div class="flex-1 min-w-0">
                                            <p class="text-sm text-gray-800 truncate" x-text="emp.full_name"></p>
                                            <p class="text-xs text-gray-400" x-text="'#' + emp.emp_code"></p>
                                        </div>
                                        <div class="flex items-center gap-2 flex-shrink-0">
                                            <template x-if="emp.has_face">
                                                <span title="Face photo on terminal">
                                                    <svg class="w-4 h-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/>
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/>
                                                    </svg>
                                                </span>
                                            </template>
                                            <template x-if="emp.card_no">
                                                <span class="inline-flex items-center gap-1 px-2 py-0.5 text-xs font-mono bg-gray-100 text-gray-600 rounded">
                                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                                                    </svg>
                                                    <span x-text="emp.card_no"></span>
                                                </span>
                                            </template>
                                            <template x-if="!emp.card_no">
                                                <span class="text-xs text-gray-300">No card</span>
                                            </template>

                                            {{-- Resync button --}}
                                            <template x-if="resyncResults[emp.emp_code] === 'success'">
                                                <span class="inline-flex items-center px-2 py-0.5 text-xs text-green-600 bg-green-50 border border-green-200 rounded">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                                    </svg>
                                                    Synced
                                                </span>
                                            </template>
                                            <template x-if="resyncResults[emp.emp_code] && resyncResults[emp.emp_code] !== 'success'">
                                                <span class="inline-flex items-center px-2 py-0.5 text-xs text-red-600 bg-red-50 border border-red-200 rounded" :title="resyncResults[emp.emp_code]">
                                                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                                    </svg>
                                                    Error
                                                </span>
                                            </template>
                                            <template x-if="!resyncResults[emp.emp_code]">
                                                <button
                                                    type="button"
                                                    @click="resyncEmployee(emp.emp_code)"
                                                    :disabled="resyncingEmp === emp.emp_code"
                                                    :title="resyncingEmp === emp.emp_code ? 'Syncing...' : 'Re-sync from local DB'"
                                                    class="inline-flex items-center px-2 py-0.5 text-xs text-indigo-600 bg-indigo-50 hover:bg-indigo-100 border border-indigo-200 rounded transition-colors disabled:opacity-50 cursor-pointer disabled:cursor-not-allowed"
                                                >
                                                    <svg x-show="resyncingEmp !== emp.emp_code" class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                                    </svg>
                                                    <svg x-show="resyncingEmp === emp.emp_code" x-cloak class="animate-spin w-3 h-3" fill="none" viewBox="0 0 24 24">
                                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                                    </svg>
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Modal footer --}}
                        <div x-show="!loadingEmployees && employees.length > 0" class="px-5 py-4 border-t border-gray-100 flex-shrink-0">
                            <button type="button"
                                x-show="!deleting"
                                @click="if (confirm('Delete ALL ' + employees.length + ' employees from this terminal?')) {
                                    deleting = true;
                                    fetch('{{ route('hikvision.employees.delete-all', $terminal) }}', {
                                        method: 'DELETE',
                                        headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                                    })
                                    .then(r => r.json())
                                    .then(() => { employees = []; deleting = false; })
                                    .catch(() => { deleting = false; });
                                }"
                                class="w-full px-4 py-2 text-sm font-medium text-red-600 bg-red-50 hover:bg-red-100 rounded-lg transition-colors">
                                Delete All from Terminal
                            </button>
                            <div x-show="deleting" class="flex items-center justify-center gap-2 text-sm text-red-500 py-2">
                                <svg class="animate-spin h-4 w-4 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                                Deleting...
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        @empty
            <div class="bg-white rounded-lg border border-gray-200 px-4 py-12 text-center">
                <p class="text-sm text-gray-500 mb-4">No Hikvision terminals configured yet.</p>
                <a href="{{ route('hikvision.create') }}" style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;text-decoration:none;display:inline-block">
                    Add Terminal
                </a>
            </div>
        @endforelse
    </div>

    @if($terminals->hasPages())
        <div class="mt-4">{{ $terminals->links() }}</div>
    @endif
</x-app-layout>
