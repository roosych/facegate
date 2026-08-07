<x-app-layout>
    @section('subtitle', 'Manage access points')
    @section('title', 'Access Points')

    <div
        x-data="{
            status: 'idle',
            current: '',
            done: 0,
            total: 0,
            empDone: 0,
            empTotal: 0,
            synced: 0,
            errors: 0,
            timer: null,
            get percent() {
                if (this.empTotal > 0) return Math.round(this.empDone / this.empTotal * 100);
                return this.total > 0 ? Math.round(this.done / this.total * 100) : 0;
            },
            get isActive() {
                return this.status === 'pending' || this.status === 'running';
            },
            async poll() {
                try {
                    const res = await fetch('{{ route('sync.status') }}');
                    const data = await res.json();
                    this.status   = data.status    ?? 'idle';
                    this.current  = data.current   ?? '';
                    this.done     = data.done      ?? 0;
                    this.total    = data.total     ?? 0;
                    this.empDone  = data.emp_done  ?? 0;
                    this.empTotal = data.emp_total ?? 0;
                    this.synced   = data.synced    ?? 0;
                    this.errors   = data.errors    ?? 0;
                } catch (e) {
                    // network blip — retry
                }

                if (this.isActive) {
                    this.timer = setTimeout(() => this.poll(), 1500);
                } else if (this.status === 'done') {
                    setTimeout(() => {
                        document.body.style.transition = 'opacity 0.4s ease';
                        document.body.style.opacity = '0';
                        setTimeout(() => window.location.reload(), 420);
                    }, 1800);
                }
            },
            init() {
                this.poll();
            }
        }"
        @sync-point.window="
            if (isActive) return;
            status = 'pending';
            done = 0; total = 0; empDone = 0; empTotal = 0; synced = 0; errors = 0; current = '';
            clearTimeout(timer);
            timer = setTimeout(() => poll(), 800);
        "
    >
        <div
            x-data="{
                checking: false,
                checked: false,
                error: null,
                local: 0,
                rusguard: 0,
                missing: [],
                extra: [],
                syncing: {},
                addingAll: false,
                get hasDiff() { return this.missing.length > 0 || this.extra.length > 0; },
                async check() {
                    this.checking = true; this.checked = false; this.error = null;
                    try {
                        const res = await fetch('{{ route('access-points.check-points') }}');
                        const data = await res.json();
                        if (data.error) { this.error = data.error; }
                        else { this.local = data.local; this.rusguard = data.rusguard; this.missing = data.missing; this.extra = data.extra; this.checked = true; }
                    } catch { this.error = 'Request failed'; }
                    this.checking = false;
                },
                async addPoint(driverId, name, deviceType, index) {
                    this.syncing[index] = true;
                    try {
                        const res = await fetch('{{ route('access-points.create-point') }}', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                            body: JSON.stringify({ driver_id: driverId, name: name, device_type: deviceType })
                        });
                        if (res.ok) { this.missing = this.missing.filter((_, i) => i !== index); this.local++; }
                    } catch {}
                    this.syncing[index] = false;
                },
                async addAll() {
                    if (this.addingAll) return;
                    this.addingAll = true;
                    const points = [...this.missing];
                    for (const point of points) {
                        try {
                            const res = await fetch('{{ route('access-points.create-point') }}', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                body: JSON.stringify({ driver_id: point.driverId, name: point.name, device_type: point.type })
                            });
                            if (res.ok) { this.missing = this.missing.filter(p => p.driverId !== point.driverId); this.local++; }
                        } catch {}
                    }
                    this.addingAll = false;
                    window.location.reload();
                }
            }"
        >
            {{-- Search --}}
            <form method="GET" action="{{ route('access-points.index') }}" class="flex gap-2 mb-3">
                <input
                    type="search"
                    name="search"
                    value="{{ $search }}"
                    placeholder="Search access points..."
                    class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 w-64 focus:outline-none focus:ring-2 focus:ring-indigo-400"
                >
                <button type="submit" class="text-sm bg-indigo-600 text-white px-3 py-1.5 rounded-lg hover:bg-indigo-700">Search</button>
                @if($search !== '')
                    <a href="{{ route('access-points.index') }}" class="inline-flex items-center text-sm text-gray-500 hover:text-gray-700 border border-gray-300 rounded-lg px-3 py-1.5 hover:border-gray-400">Clear</a>
                @endif
            </form>

            <div class="flex items-center justify-between bg-white rounded-lg border border-gray-200 px-5 py-4 mb-3">
                <div class="flex items-center gap-3">
                    <p class="text-sm text-gray-500">{{ $accessPoints->total() }} access points</p>

                    {{-- Check points button --}}
                    <button type="button" @click="check()" :disabled="checking"
                        class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors disabled:opacity-50 flex items-center gap-1.5">
                        <svg x-show="checking" style="width:12px;height:12px;animation:rg-spin 0.75s linear infinite;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                            <circle cx="12" cy="12" r="10" stroke="#c7d2fe" stroke-width="3"/>
                            <path d="M12 2a10 10 0 0 1 10 10" stroke="#4f46e5" stroke-width="3" stroke-linecap="round"/>
                        </svg>
                        <span x-show="!checking">Check Points</span>
                        <span x-show="checking">Checking...</span>
                    </button>

                    {{-- Summary badge --}}
                    <template x-if="checked && !hasDiff">
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 rounded-full">
                            In sync (<span x-text="rusguard"></span> points)
                        </span>
                    </template>
                    <template x-if="checked && hasDiff">
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium bg-amber-50 text-amber-700 rounded-full"
                            x-text="'Local ' + local + ' / RusGuard ' + rusguard"></span>
                    </template>
                    <template x-if="error">
                        <span class="text-xs text-red-500" x-text="error"></span>
                    </template>
                </div>

                <button
                    type="button"
                    @click="
                        if (isActive) return;
                        status = 'pending';
                        done = 0; total = 0; empDone = 0; empTotal = 0; synced = 0; errors = 0; current = '';
                        clearTimeout(timer);
                        timer = setTimeout(() => poll(), 800);
                        fetch('{{ route('sync.all') }}', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content }
                        }).catch(() => { status = 'failed'; clearTimeout(timer); });
                    "
                    :disabled="isActive"
                    :class="isActive ? 'opacity-50 cursor-not-allowed' : 'hover:bg-indigo-700'"
                    style="background-color:#4f46e5;color:#fff;padding:0.5rem 1.25rem;font-size:0.875rem;font-weight:600;border-radius:0.5rem;border:none;cursor:pointer;transition:background-color 0.15s"
                >
                    Sync All
                </button>
            </div>

            {{-- Diff results --}}
            <template x-if="checked && hasDiff">
                <div class="bg-white rounded-lg border border-amber-200 overflow-hidden mb-3">
                    <template x-if="missing.length > 0">
                        <div>
                            <div class="px-5 py-2 bg-amber-50 border-b border-amber-100 flex items-center justify-between">
                                <span class="text-xs font-semibold text-amber-700 uppercase tracking-wide">In RusGuard but not local (<span x-text="missing.length"></span>)</span>
                                <button type="button"
                                    @click="addAll()"
                                    :disabled="addingAll"
                                    class="px-3 py-1 text-xs font-medium text-white bg-indigo-600 hover:bg-indigo-700 rounded-md transition-colors disabled:opacity-50 flex items-center gap-1.5">
                                    <svg x-show="addingAll" style="width:10px;height:10px;animation:rg-spin 0.75s linear infinite;flex-shrink:0" fill="none" viewBox="0 0 24 24">
                                        <circle cx="12" cy="12" r="10" stroke="rgba(255,255,255,0.4)" stroke-width="3"/>
                                        <path d="M12 2a10 10 0 0 1 10 10" stroke="white" stroke-width="3" stroke-linecap="round"/>
                                    </svg>
                                    <span x-show="!addingAll">Save all to local</span>
                                    <span x-show="addingAll">Saving <span x-text="missing.length"></span> left...</span>
                                </button>
                            </div>
                            <template x-for="(point, i) in missing" :key="point.driverId">
                                <div class="flex items-center px-5 py-3 gap-3 border-b border-gray-100 last:border-0">
                                    <div class="flex-1 min-w-0">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium text-gray-800" x-text="point.name"></span>
                                            <template x-if="point.type">
                                                <span class="inline-flex px-1.5 py-0.5 text-xs bg-gray-100 text-gray-500 rounded" x-text="point.type"></span>
                                            </template>
                                            <template x-if="point.employeeCount > 0">
                                                <span class="inline-flex px-1.5 py-0.5 text-xs bg-indigo-50 text-indigo-600 rounded" x-text="point.employeeCount + ' emp.'"></span>
                                            </template>
                                        </div>
                                        <span class="text-xs text-gray-400 font-mono" x-text="point.driverId"></span>
                                    </div>
                                    <button type="button"
                                        @click="addPoint(point.driverId, point.name, point.type, i)"
                                        :disabled="syncing[i]"
                                        class="flex-shrink-0 px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-md transition-colors disabled:opacity-50">
                                        <span x-show="!syncing[i]">Add to local</span>
                                        <span x-show="syncing[i]">Adding...</span>
                                    </button>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="extra.length > 0">
                        <div>
                            <div class="px-5 py-2 bg-gray-50 border-b border-gray-100">
                                <span class="text-xs font-semibold text-gray-500 uppercase tracking-wide">In local but not in RusGuard (<span x-text="extra.length"></span>)</span>
                            </div>
                            <template x-for="point in extra" :key="point.id">
                                <div class="flex items-center px-5 py-3 gap-3 border-b border-gray-100 last:border-0">
                                    <div class="flex-1 min-w-0">
                                        <span class="text-sm font-medium text-gray-500" x-text="point.rusguard_access_point_name || point.name"></span>
                                        <span class="block text-xs text-gray-400 font-mono" x-text="point.rusguard_access_point_id"></span>
                                    </div>
                                    <span class="text-xs text-gray-400">Deleted in RusGuard</span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </template>
        </div>

        {{-- Progress block --}}
        <div x-show="status !== 'idle'" x-transition style="margin-bottom:1rem;background:#fff;border:1px solid #e5e7eb;border-radius:0.5rem;padding:1.25rem 1.5rem">

            {{-- Pending --}}
            <div x-show="status === 'pending'" style="display:flex;align-items:center;gap:0.75rem">
                <div style="width:18px;height:18px;border:2px solid #e0e7ff;border-top-color:#4f46e5;border-radius:50%;animation:rg-spin 0.75s linear infinite;flex-shrink:0"></div>
                <span style="font-size:0.875rem;color:#6b7280">Waiting for worker...</span>
            </div>

            {{-- Running --}}
            <div x-show="status === 'running'">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.625rem">
                    <div style="display:flex;align-items:center;gap:0.5rem">
                        <div style="width:16px;height:16px;border:2px solid #e0e7ff;border-top-color:#4f46e5;border-radius:50%;animation:rg-spin 0.75s linear infinite;flex-shrink:0"></div>
                        <span style="font-size:0.875rem;font-weight:500;color:#374151">Syncing...</span>
                    </div>
                    <span x-show="total > 1" style="font-size:0.875rem;color:#6b7280" x-text="done + ' / ' + total + ' access points'"></span>
                </div>

                <div style="background:#f3f4f6;border-radius:9999px;height:10px;overflow:hidden;margin-bottom:0.5rem">
                    <div :style="'background:#4f46e5;height:100%;border-radius:9999px;width:' + percent + '%;transition:width 0.6s ease'"></div>
                </div>

                <div style="display:flex;align-items:center;justify-content:space-between">
                    <span style="font-size:0.75rem;color:#9ca3af;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:75%" x-text="current ? '→ ' + current : ''"></span>
                    <span style="font-size:0.75rem;color:#9ca3af;flex-shrink:0;margin-left:0.5rem" x-text="percent + '%'"></span>
                </div>
            </div>

            {{-- Done --}}
            <div x-show="status === 'done'" style="display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:0.5rem">
                    <svg style="width:18px;height:18px;color:#16a34a;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                    </svg>
                    <span style="font-size:0.875rem;font-weight:500;color:#16a34a" x-text="'Done — ' + synced + ' employees synced' + (errors > 0 ? ', ' + errors + ' errors' : '')"></span>
                </div>
                <span style="font-size:0.75rem;color:#9ca3af">Updating page...</span>
            </div>

            {{-- Failed --}}
            <div x-show="status === 'failed'" style="display:flex;align-items:center;justify-content:space-between">
                <div style="display:flex;align-items:center;gap:0.5rem">
                    <svg style="width:18px;height:18px;color:#dc2626;flex-shrink:0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    <span style="font-size:0.875rem;font-weight:500;color:#dc2626">Sync failed. Try again.</span>
                </div>
                <button @click="status = 'idle'" style="font-size:0.75rem;color:#9ca3af;cursor:pointer;background:none;border:none;padding:0">Dismiss</button>
            </div>

        </div>

        <style>@keyframes rg-spin { to { transform: rotate(360deg); } }</style>
    </div>

    <div class="space-y-2">
        @forelse($accessPoints as $accessPoint)
            <div x-data="{
                expanded: false,
                modalOpen: false,
                rgModal: false,
                rgLoading: false,
                rgEmployees: [],
                rgSearch: '',
                get filteredRgEmployees() {
                    if (!this.rgSearch) return this.rgEmployees;
                    const q = this.rgSearch.toLowerCase();
                    return this.rgEmployees.filter(e =>
                        (e.fio && e.fio.toLowerCase().includes(q)) ||
                        (e.card_keys && e.card_keys.some(k => k.toLowerCase().includes(q)))
                    );
                },
                rgError: null,
                syncStatus: null,
                syncTimer: null,
                async openRgModal(url) {
                    this.rgModal = true;
                    this.rgLoading = true;
                    this.rgEmployees = [];
                    this.rgSearch = '';
                    this.rgError = null;
                    try {
                        const res = await fetch(url);
                        const data = await res.json();
                        if (data.error) { this.rgError = data.error; }
                        else { this.rgEmployees = data.employees; }
                    } catch (e) { this.rgError = 'Request failed'; }
                    this.rgLoading = false;
                },
                pollSync(pollUrl) {
                    fetch(pollUrl)
                        .then(r => r.json())
                        .then(d => {
                            this.syncStatus = d;
                            if (['running', 'queued', 'pending'].includes(d?.status)) {
                                this.syncTimer = setTimeout(() => this.pollSync(pollUrl), 2000);
                            }
                        })
                        .catch(() => { this.syncTimer = setTimeout(() => this.pollSync(pollUrl), 2000); });
                },
                startSync(postUrl, pollUrl) {
                    this.modalOpen = false;
                    this.syncStatus = { status: 'queued' };
                    clearTimeout(this.syncTimer);
                    fetch(postUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content } }).catch(() => {});
                    setTimeout(() => this.pollSync(pollUrl), 1000);
                }
            }" class="bg-white rounded-lg border border-gray-200 overflow-hidden">

                {{-- Header row --}}
                <div class="flex items-center px-5 py-4 gap-3">

                    {{-- Toggle area --}}
                    <div class="flex items-center gap-3 flex-1 min-w-0 cursor-pointer select-none" @click="expanded = !expanded">
                        <svg
                            class="w-4 h-4 flex-shrink-0 transition-transform duration-200"
                            :class="expanded ? 'rotate-180 text-indigo-500' : 'text-gray-400'"
                            fill="none" stroke="currentColor" viewBox="0 0 24 24"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                        </svg>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-gray-800 truncate">
                                    {{ $accessPoint->rusguard_access_point_name ?: $accessPoint->name }}
                                </span>
                                @if($accessPoint->device_type)
                                    <span class="inline-flex flex-shrink-0 px-1.5 py-0.5 text-xs bg-gray-100 text-gray-500 rounded">{{ $accessPoint->device_type }}</span>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400 font-mono">{{ $accessPoint->rusguard_access_point_id }}</span>
                        </div>
                        <span class="inline-flex flex-shrink-0 px-2 py-0.5 text-xs font-medium bg-indigo-50 text-indigo-600 rounded-full">
                            {{ $accessPoint->employees_count }} employees
                        </span>
                        @if($accessPoint->hikvisionTerminal)
                            <span class="inline-flex flex-shrink-0 items-center gap-1 px-2 py-0.5 text-xs font-medium bg-green-50 text-green-700 rounded-full">
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                {{ $accessPoint->hikvisionTerminal->name }} · {{ $accessPoint->hikvisionTerminal->ip }}
                            </span>
                        @endif
                        @unless($accessPoint->is_active)
                            <span class="inline-flex flex-shrink-0 px-2 py-0.5 text-xs font-medium bg-gray-100 text-gray-500 rounded-full">Inactive</span>
                        @endunless
                    </div>

                    {{-- Action buttons --}}
                    <div class="flex items-center gap-2 flex-shrink-0">

                        <button type="button"
                            @click="openRgModal('{{ route('access-points.rusguard-employees', $accessPoint) }}')"
                            class="px-3 py-1.5 text-xs font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-md transition-colors">
                            Fetch from RusGuard
                        </button>

                        @if($accessPoint->hikvisionTerminal)
                            <button type="button"
                                @click="startSync('{{ route('hikvision.sync.terminal', $accessPoint->hikvisionTerminal) }}', '{{ route('hikvision.sync.status', $accessPoint->hikvisionTerminal) }}')"
                                class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                                Push
                            </button>
                        @elseif($freeTerminals->isNotEmpty())
                            <button type="button" @click="modalOpen = true"
                                class="px-3 py-1.5 text-xs font-medium text-gray-600 bg-gray-100 hover:bg-gray-200 rounded-md transition-colors">
                                Link terminal
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Push progress --}}
                <div x-show="syncStatus !== null" x-transition class="px-5 pb-3 -mt-1">
                    <div x-show="['queued','pending','running'].includes(syncStatus?.status)" class="flex items-center gap-2 text-xs text-indigo-600">
                        <svg class="animate-spin h-3 w-3 flex-shrink-0" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                        </svg>
                        <span x-text="syncStatus?.status === 'queued' ? 'Queued...' : 'Pushing ' + (syncStatus?.emp_done ?? syncStatus?.done ?? 0) + '/' + (syncStatus?.emp_total ?? syncStatus?.total ?? '?') + ' · ' + (syncStatus?.synced ?? 0) + ' done'"></span>
                    </div>
                    <div x-show="syncStatus?.status === 'done'" class="flex items-center gap-1.5 text-xs text-green-600">
                        <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span x-text="'Done — ' + (syncStatus?.synced ?? 0) + ' synced' + (syncStatus?.errors > 0 ? ', ' + syncStatus.errors + ' errors' : '')"></span>
                    </div>
                    <div x-show="syncStatus?.status === 'failed'" class="flex items-center gap-1.5 text-xs text-red-600">
                        <svg class="h-3.5 w-3.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        Failed
                    </div>
                </div>

                {{-- Link terminal modal (shown when no terminal linked) --}}
                @if(!$accessPoint->hikvisionTerminal)
                <div
                    x-show="modalOpen"
                    x-cloak
                    x-transition.opacity
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    style="background:rgba(0,0,0,0.45)"
                    @click.self="modalOpen = false"
                >
                    <div x-show="modalOpen" x-transition @click.stop
                        class="bg-white rounded-xl shadow-2xl w-full max-w-xs flex flex-col"
                        style="margin:1rem;max-height:80vh;overflow:hidden"
                    >
                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 flex-shrink-0">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">Link terminal</h3>
                                <p class="text-xs text-gray-400 mt-0.5">{{ $accessPoint->rusguard_access_point_name ?: $accessPoint->name }}</p>
                            </div>
                            <button @click="modalOpen = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </div>
                        <div class="overflow-y-auto flex-1 min-h-0 px-5 py-4 space-y-2">
                            @foreach($freeTerminals as $terminal)
                                <button type="button"
                                    @click="
                                        fetch('{{ route('hikvision.link-access-point', $terminal) }}', {
                                            method: 'PATCH',
                                            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                                            body: JSON.stringify({ access_point_id: {{ $accessPoint->id }} })
                                        }).then(() => window.location.reload());
                                        modalOpen = false;
                                    "
                                    class="w-full flex items-center gap-3 px-4 py-3 text-sm text-gray-700 bg-gray-50 hover:bg-gray-100 rounded-lg transition-colors text-left">
                                    <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6.827 6.175A2.31 2.31 0 015.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 00-1.134-.175 2.31 2.31 0 01-1.64-1.055l-.822-1.316a2.192 2.192 0 00-1.736-1.039 48.774 48.774 0 00-5.232 0 2.192 2.192 0 00-1.736 1.039l-.821 1.316z M16.5 12.75a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z"/>
                                    </svg>
                                    <div>
                                        <div>{{ $terminal->name }}</div>
                                        @if($terminal->location)
                                            <div class="text-xs text-gray-400">{{ $terminal->location }}</div>
                                        @endif
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif

                {{-- RusGuard employees modal --}}
                <div
                    x-show="rgModal"
                    x-cloak
                    x-transition.opacity
                    class="fixed inset-0 z-50 flex items-center justify-center"
                    style="background:rgba(0,0,0,0.45)"
                    @click.self="rgModal = false"
                >
                    <div x-show="rgModal" x-transition @click.stop
                        class="bg-white rounded-xl shadow-2xl w-full max-w-2xl flex flex-col"
                        style="margin:1rem;max-height:80vh;overflow:hidden"
                    >
                        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 flex-shrink-0">
                            <div>
                                <h3 class="text-sm font-semibold text-gray-900">{{ $accessPoint->rusguard_access_point_name ?: $accessPoint->name }}</h3>
                                <p class="text-xs text-gray-400 mt-0.5" x-show="!rgLoading && rgEmployees.length > 0">
                                    <span x-text="rgEmployees.length + ' people'"></span>
                                    <span
                                        x-show="rgEmployees.filter(e => !e.card_keys || e.card_keys.length === 0).length > 0"
                                        class="ml-2 font-medium text-red-500"
                                        x-text="rgEmployees.filter(e => !e.card_keys || e.card_keys.length === 0).length + ' without card'"
                                    ></span>
                                </p>
                            </div>
                            <div class="flex items-center gap-3">
                                <input
                                    x-model="rgSearch"
                                    type="text"
                                    placeholder="Search..."
                                    class="text-sm border border-gray-200 rounded-lg px-3 py-1.5 w-40 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                                />
                                <button @click="rgModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="overflow-y-auto flex-1 min-h-0 px-5 py-4">
                            {{-- Loading --}}
                            <div x-show="rgLoading" class="flex items-center justify-center py-10 gap-2 text-sm text-gray-400">
                                <svg class="animate-spin h-4 w-4 text-indigo-500" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"/>
                                </svg>
                                Loading from RusGuard...
                            </div>

                            {{-- Error --}}
                            <div x-show="!rgLoading && rgError" class="text-sm text-red-500 py-4" x-text="rgError"></div>

                            {{-- Empty --}}
                            <div x-show="!rgLoading && !rgError && filteredRgEmployees.length === 0" class="text-sm text-gray-400 py-4 text-center">
                                No employees found
                            </div>

                            {{-- List --}}
                            <ul x-show="!rgLoading && filteredRgEmployees.length > 0" class="divide-y divide-gray-100">
                                <template x-for="(emp, i) in filteredRgEmployees" :key="emp.uuid">
                                    <li class="flex items-center gap-3 py-2.5">
                                        <span class="text-xs text-gray-300 w-5 text-right flex-shrink-0" x-text="i + 1"></span>
                                        <div class="flex-1 min-w-0">
                                            <span class="text-sm text-gray-800 block" x-text="emp.fio"></span>
                                            <template x-if="emp.card_keys && emp.card_keys.length > 0">
                                                <span class="text-xs font-mono text-indigo-400" x-text="emp.card_keys.join(', ')"></span>
                                            </template>
                                            <template x-if="!emp.card_keys || emp.card_keys.length === 0">
                                                <span class="text-xs font-medium text-amber-500">No card</span>
                                            </template>
                                        </div>
                                    </li>
                                </template>
                            </ul>
                        </div>

                        <div x-show="!rgLoading && rgEmployees.length > 0" class="px-5 py-4 border-t border-gray-100 flex-shrink-0">
                            <button type="button"
                                @click="rgModal = false; startSync('{{ route('sync.access-point', $accessPoint) }}', '{{ route('sync.access-point.status', $accessPoint) }}')"
                                class="w-full px-4 py-2 text-sm font-medium text-indigo-700 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors">
                                Save to local DB
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Employee list --}}
                <div x-show="expanded" x-transition class="border-t border-gray-100 px-5 py-4">
                    @if($accessPoint->employees->isNotEmpty())
                        <ul class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-4 gap-y-1.5">
                            @foreach($accessPoint->employees as $employee)
                                <li class="flex items-center gap-2 min-w-0">
                                    <span class="w-1.5 h-1.5 rounded-full bg-indigo-400 flex-shrink-0"></span>
                                    <a href="{{ route('employees.show', $employee) }}" class="text-sm text-gray-700 hover:text-indigo-600 truncate">
                                        {{ $employee->full_name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-sm text-gray-400">No employees synced yet. Press <strong>Sync</strong> to load from RusGuard.</p>
                    @endif
                </div>

            </div>
        @empty
            <div class="bg-white rounded-lg border border-gray-200 px-4 py-12 text-center">
                <p class="text-sm text-gray-500 mb-4">No data yet. Press <strong>Sync All</strong> to load access points and employees from RusGuard.</p>
                <form method="POST" action="{{ route('sync.all') }}">
                    @csrf
                    <button type="submit" class="px-5 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                        Sync All
                    </button>
                </form>
            </div>
        @endforelse
    </div>

    @if($accessPoints->hasPages())
        <div class="mt-4">{{ $accessPoints->links() }}</div>
    @endif
</x-app-layout>
