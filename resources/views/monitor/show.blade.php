<!DOCTYPE html>
<html lang="az">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ $title }} — Monitor</title>

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700&display=swap" rel="stylesheet">

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        <style>
            body { font-family: 'Montserrat', sans-serif; }
            /* A normal, successful pass flashes green. Alcohol-failed events flash red instead
               (see monitor-flash-alert below) and additionally keep a persistent red border for
               as long as that event stays the latest one — a single flash is easy to miss, a
               sustained state isn't. */
            @keyframes monitor-flash-success {
                0% { box-shadow: 0 0 0 0 rgba(21, 128, 61, 0.4); }
                100% { box-shadow: 0 0 0 24px rgba(21, 128, 61, 0); }
            }
            .monitor-flash-success { animation: monitor-flash-success 0.9s ease-out; }
            @keyframes monitor-flash-alert {
                0% { box-shadow: 0 0 0 0 rgba(237, 28, 36, 0.45); }
                100% { box-shadow: 0 0 0 24px rgba(237, 28, 36, 0); }
            }
            .monitor-flash-alert { animation: monitor-flash-alert 0.9s ease-out; }

            /* Sustained alcohol alert: a soft red glow fading in from the card's own edges,
               inward — no border/ring at all. box-shadow doesn't occupy layout space, so
               toggling it on/off never shifts anything. */
            .monitor-card-alert {
                box-shadow: inset 0 0 16px 3px rgba(237, 28, 36, 0.55);
            }

            /* Same glow over the photo, but as an overlay pseudo-element instead of a direct
               box-shadow: the <img> is opaque and fills the box edge-to-edge, so a box-shadow on
               the box itself would be painted underneath it and never actually be seen. */
            .monitor-photo-alert {
                position: relative;
            }
            .monitor-photo-alert::after {
                content: '';
                position: absolute;
                inset: 0;
                box-shadow: inset 0 0 16px 3px rgba(237, 28, 36, 0.55);
                pointer-events: none;
            }
        </style>
    </head>
    <body
        class="bg-[#f9f9f9] text-[#0c1014] antialiased"
        x-data="{
            data: {},
            now: Date.now(),
            azMonths: ['yanvar', 'fevral', 'mart', 'aprel', 'may', 'iyun', 'iyul', 'avqust', 'sentyabr', 'oktyabr', 'noyabr', 'dekabr'],
            azWeekdays: ['bazar', 'bazar ertəsi', 'çərşənbə axşamı', 'çərşənbə', 'cümə axşamı', 'cümə', 'şənbə'],
            lastSeenAt: {},
            async poll() {
                try {
                    const res = await fetch('{{ $statusUrl }}');
                    const body = await res.json();
                    const byId = {};
                    for (const ap of body.access_points) {
                        byId[ap.access_point_id] = ap;
                        const prevTime = this.data[ap.access_point_id]?.event?.event_time;
                        if (ap.event && ap.event.event_time !== prevTime) {
                            this.lastSeenAt[ap.access_point_id] = Date.now();
                        }
                    }
                    this.data = byId;
                } catch (e) {
                    // network blip — keep last known state, retry next tick
                }
                setTimeout(() => this.poll(), 1500);
            },
            isFresh(id) {
                return this.lastSeenAt[id] && (this.now - this.lastSeenAt[id]) < 1200;
            },
            alcoholFailed(id) {
                return this.data[id]?.event?.alcohol?.passed === false;
            },
            blockClasses(id) {
                const classes = [];
                if (this.isFresh(id)) {
                    classes.push(this.alcoholFailed(id) ? 'monitor-flash-alert' : 'monitor-flash-success');
                }
                if (this.alcoholFailed(id)) {
                    classes.push('monitor-card-alert');
                }
                return classes.join(' ');
            },
            // mg/100ml (the raw ISAPI value) -> ‰, same 1 mg/100ml = 0.01‰ formula as
            // AccessEvent::alcoholPromille() so the figure matches what's shown elsewhere.
            alcoholLabel(mgPer100ml) {
                if (mgPer100ml === null || mgPer100ml === undefined) return '';
                return (Math.round(mgPer100ml * 0.01 * 100) / 100).toFixed(2) + '‰';
            },
            clockTime() {
                const d = new Date(this.now);
                return String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
            },
            clockDate() {
                const d = new Date(this.now);
                return d.getDate() + ' ' + this.azMonths[d.getMonth()] + ' ' + d.getFullYear() + ', ' + this.azWeekdays[d.getDay()];
            },
            eventTimeLabel(iso) {
                const d = new Date(iso);
                const pad = (n) => String(n).padStart(2, '0');
                return pad(d.getDate()) + '.' + pad(d.getMonth() + 1) + '.' + d.getFullYear() + ' '
                    + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
            },
            isFullscreen: false,
            toggleFullscreen() {
                if (document.fullscreenElement) {
                    document.exitFullscreen();
                } else {
                    document.documentElement.requestFullscreen().catch(() => {});
                }
            },
            init() {
                this.poll();
                setInterval(() => { this.now = Date.now(); }, 1000);
                document.addEventListener('fullscreenchange', () => {
                    this.isFullscreen = document.fullscreenElement !== null;
                });
            }
        }"
    >
        <header class="fixed top-0 left-0 w-full z-50 bg-white">
            <div class="h-20 w-full px-10 md:px-20 flex items-center justify-between">
                <div class="flex items-center">
                    <img src="{{ asset('images/metak-logo.svg') }}" alt="METAK" class="h-10 w-auto object-contain">
                </div>
                <div class="flex items-center gap-6">
                    <div class="flex flex-col text-right">
                        <span class="text-2xl font-bold text-[#0c1014] tabular-nums leading-none" x-text="clockTime()"></span>
                        <span class="text-[11px] font-bold text-[#44474a] uppercase tracking-wider mt-1" x-text="clockDate()"></span>
                    </div>
                    <button
                        type="button"
                        @click="toggleFullscreen()"
                        title="Tam ekran rejimi"
                        class="flex items-center justify-center w-10 h-10 rounded-full text-[#44474a] hover:bg-[#eeeeee] transition-colors flex-shrink-0"
                    >
                        <svg x-show="!isFullscreen" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15" />
                        </svg>
                        <svg x-show="isFullscreen" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25" />
                        </svg>
                    </button>
                </div>
            </div>
        </header>

        <main class="w-full pt-20 min-h-screen bg-[#f9f9f9] flex flex-col justify-center">
            <div class="w-full max-w-7xl mx-auto px-12 py-20">
                <div class="grid grid-cols-1 md:grid-cols-2 items-stretch gap-8">
                    @forelse($accessPoints as $accessPoint)
                        {{-- An odd count leaves the last card alone on its row — center it at the
                             same width a card would have next to a neighbour, instead of letting
                             the grid stretch it to (a single turnstile) or left-align it in (a
                             trailing third, fifth, ...) the first column. --}}
                        <div
                            class="monitor-card bg-white rounded-2xl shadow-sm overflow-hidden flex flex-col relative {{ $loop->last && $accessPoints->count() % 2 === 1 ? 'md:col-span-2 md:mx-auto md:w-full md:max-w-[calc(50%-1rem)]' : '' }}"
                            :class="blockClasses({{ $accessPoint->id }})"
                        >
                            {{-- Small label above the header row — the reference this layout is based on
                                 shows one checkpoint per screen, so its title lives at the page level. Our
                                 screen can combine several different turnstiles, so each block still needs
                                 to say which one it is. --}}
                            <div class="px-5 pt-4 pb-1 text-[11px] font-bold text-[#8a8f96] uppercase tracking-wider truncate">
                                {{ $accessPoint->name }}
                            </div>

                            <div class="flex items-center justify-between gap-3 px-5 pb-3">
                                <span class="text-2xl font-bold text-[#0c1014]" x-text="data[{{ $accessPoint->id }}]?.event?.direction_label ?? '—'"></span>
                                <span class="text-base font-semibold text-[#44474a] tabular-nums whitespace-nowrap" x-text="data[{{ $accessPoint->id }}]?.event ? eventTimeLabel(data[{{ $accessPoint->id }}].event.event_time) : ''"></span>
                            </div>

                            {{-- 480×650 — the actual photo size, so the placeholder text (waiting for a
                                 pass / pass happened but no photo on file) sits in an identically
                                 sized box and nothing jumps around once a real photo arrives. --}}
                            <div
                                class="w-full aspect-[480/650] overflow-hidden bg-gray-100 flex items-center justify-center flex-shrink-0 relative"
                                :class="alcoholFailed({{ $accessPoint->id }}) ? 'monitor-photo-alert' : ''"
                            >
                                <template x-if="data[{{ $accessPoint->id }}]?.event?.photo_url">
                                    <img
                                        :src="data[{{ $accessPoint->id }}].event.photo_url"
                                        :alt="data[{{ $accessPoint->id }}].event.employee_name ?? ''"
                                        class="w-full h-full object-cover"
                                    >
                                </template>
                                <template x-if="data[{{ $accessPoint->id }}]?.event && !data[{{ $accessPoint->id }}]?.event?.photo_url">
                                    <span class="text-base font-semibold text-gray-400 text-center px-6">Foto tapılmadı</span>
                                </template>
                                <template x-if="!data[{{ $accessPoint->id }}]?.event">
                                    <span class="text-base font-semibold text-gray-400 text-center px-6">Keçid gözlənilir…</span>
                                </template>

                                {{-- Absolutely positioned — takes no layout space of its own, so a card
                                     with an alcohol alert is exactly as tall as one without. --}}
                                <template x-if="alcoholFailed({{ $accessPoint->id }})">
                                    <div
                                        class="absolute top-0 left-0 right-0 bg-[#ed1c24] text-white text-sm uppercase tracking-widest px-5 py-2.5 font-bold text-center"
                                        x-text="'⚠ Alkoqol aşkarlandı' + (data[{{ $accessPoint->id }}].event.alcohol.concentration != null ? ' — ' + alcoholLabel(data[{{ $accessPoint->id }}].event.alcohol.concentration) : '')"
                                    ></div>
                                </template>
                            </div>

                            {{-- Always in the DOM (so the card's height doesn't change), just made
                                 invisible while waiting — nothing is drawn, but the space stays
                                 reserved so a waiting card is exactly as tall as one with an event. --}}
                            <div class="px-5 py-4 flex flex-col gap-1" :class="!data[{{ $accessPoint->id }}]?.event ? 'invisible' : ''">
                                {{-- leading-8 + min-h-[4rem] always reserve exactly two lines (text-2xl's
                                     line-height is 2rem), and line-clamp-2 caps anything longer with an
                                     ellipsis — a one-word name and a two-line one take up the same space. --}}
                                <h2 class="text-2xl font-bold text-[#212529] tracking-tight leading-8 min-h-[4rem] line-clamp-2" x-text="data[{{ $accessPoint->id }}]?.event?.employee_name ?? 'Naməlum işçi'"></h2>
                                <div class="flex flex-col gap-0.5 mt-1">
                                    <p class="text-sm text-[#6b7178]"><span class="font-semibold text-[#44474a]">Şöbə:</span> <span x-text="data[{{ $accessPoint->id }}]?.event?.department || '—'"></span></p>
                                    <p class="text-sm text-[#6b7178]"><span class="font-semibold text-[#44474a]">Vəzifə:</span> <span x-text="data[{{ $accessPoint->id }}]?.event?.position || '—'"></span></p>
                                </div>
                            </div>
                        </div>
                    @empty
                        <div class="flex items-center justify-center text-3xl text-[#44474a] py-16">
                            Heç bir giriş nöqtəsi seçilməyib.
                        </div>
                    @endforelse
                </div>
            </div>
        </main>
    </body>
</html>
