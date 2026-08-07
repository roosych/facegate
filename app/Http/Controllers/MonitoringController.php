<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use App\Services\HikvisionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController extends Controller
{
    /**
     * Terminals heartbeat every 30 seconds (SubscribeEvent/heartbeat in
     * HikvisionService::configureEventListening), so five minutes is ten missed beats — far
     * past a network blip, and still short enough to catch a real outage.
     *
     * This started at an hour and missed a 22-minute tunnel outage on 2026-08-07 without ever
     * turning red. Don't go much lower: the indicator has to stay quiet across a routine
     * container restart, or it gets ignored.
     */
    private const PUSH_SILENCE_MINUTES = 5;

    public function index(Request $request): View
    {
        $kind = $request->string('kind')->toString();

        $runs = SyncRun::with(['hikvisionTerminal:id,name', 'turnstile:id,name'])
            ->when($kind !== '', fn ($query) => $query->where('kind', $kind))
            ->latest('started_at')
            ->paginate(50)
            ->withQueryString();

        return view('monitoring.index', [
            'runs' => $runs,
            'kind' => $kind,
            'summary' => $this->lastDaySummary(),
            'queue' => $this->queueSnapshot(),
            'health' => $this->integrationHealth(),
            'problems' => $this->problems(),
        ]);
    }

    /**
     * Polled by the page so the queue block reflects work that arrives while it is open.
     * Only the parts that change minute to minute — the run history needs a reload.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'queue' => $this->queueSnapshot(),
            'health' => $this->integrationHealth(),
        ]);
    }

    public function retryFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return redirect()->route('monitoring.index')->with('success', 'Job queued for retry.');
    }

    public function forgetFailedJob(string $uuid): RedirectResponse
    {
        Artisan::call('queue:forget', ['id' => $uuid]);

        return redirect()->route('monitoring.index')->with('success', 'Failed job removed.');
    }

    /**
     * Photos the terminal refused are remembered per terminal and never retried until the
     * photo changes, so anything that fixes them elsewhere (a duplicate-detection setting
     * turned off on the device, a photo replaced in RusGuard) needs this list dropped before
     * the next sync will try again.
     */
    public function clearFaceProblems(HikvisionTerminal $terminal): RedirectResponse
    {
        $stats = $terminal->sync_stats ?? [];
        $cleared = count($stats['face_problems'] ?? []);

        unset($stats['face_problems']);
        $terminal->update(['sync_stats' => $stats]);

        return redirect()->route('monitoring.index')
            ->with('success', "Cleared {$cleared} remembered photo problem(s) on \"{$terminal->name}\" — the next sync will retry them.");
    }

    /**
     * Per-kind shape of the last 24 hours — enough to see at a glance that a sync started
     * taking twice as long, or that failures are clustered in one kind.
     *
     * @return array<int, array{kind: string, runs: int, failed: int, avg_ms: int|null, max_ms: int|null}>
     */
    private function lastDaySummary(): array
    {
        return DB::table('sync_runs')
            ->where('started_at', '>=', now()->subDay())
            ->groupBy('kind')
            ->orderBy('kind')
            ->get([
                'kind',
                DB::raw('count(*) as runs'),
                DB::raw("sum(case when status = '".SyncRun::STATUS_FAILED."' then 1 else 0 end) as failed"),
                DB::raw('avg(duration_ms) as avg_ms'),
                DB::raw('max(duration_ms) as max_ms'),
            ])
            ->map(fn (object $row): array => [
                'kind' => $row->kind,
                'runs' => (int) $row->runs,
                'failed' => (int) $row->failed,
                'avg_ms' => $row->avg_ms === null ? null : (int) round((float) $row->avg_ms),
                'max_ms' => $row->max_ms === null ? null : (int) $row->max_ms,
            ])
            ->all();
    }

    /**
     * @return array{pending: array<int, array{job: string, count: int, waiting_since: string|null}>, reserved: int, failed: array<int, array{uuid: string, job: string, failed_at: string, error: string}>}
     */
    private function queueSnapshot(): array
    {
        $jobs = DB::table('jobs')->get(['payload', 'reserved_at', 'available_at']);

        $pending = $jobs
            ->groupBy(fn (object $row): string => $this->jobName($row->payload))
            ->map(fn ($group, string $name): array => [
                'job' => $name,
                'count' => $group->count(),
                // A job whose available_at has long passed is one the worker has not got to
                // yet — that gap is the thing worth seeing, not the queue depth alone.
                'waiting_since' => Carbon::createFromTimestamp($group->min('available_at'))->diffForHumans(),
            ])
            ->sortByDesc('count')
            ->values()
            ->all();

        $failed = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get(['uuid', 'payload', 'failed_at', 'exception'])
            ->map(fn (object $row): array => [
                'uuid' => $row->uuid,
                'job' => $this->jobName($row->payload),
                'failed_at' => Carbon::parse($row->failed_at)->format('d.m.Y H:i:s'),
                'error' => str($row->exception)->before("\n")->limit(160)->toString(),
            ])
            ->all();

        return [
            'pending' => $pending,
            'reserved' => $jobs->whereNotNull('reserved_at')->count(),
            'failed' => $failed,
        ];
    }

    /**
     * The signals that go quiet without anything failing: a terminal that stopped pushing
     * (polling hides it), and the audit poller that stopped running (the hourly resync hides
     * it). Both are DB-derived — no device round-trips on a page load.
     *
     * @return array{terminals: array<int, array<string, mixed>>, rusguard: array<string, mixed>}
     */
    private function integrationHealth(): array
    {
        $terminals = HikvisionTerminal::where('is_active', true)->orderBy('name')->get();

        $lastEvents = AccessEvent::query()
            ->whereNotNull('hikvision_terminal_id')
            ->groupBy('hikvision_terminal_id')
            ->select('hikvision_terminal_id', DB::raw('max(created_at) as last_at'))
            ->pluck('last_at', 'hikvision_terminal_id');

        $lastRuns = SyncRun::query()
            ->where('kind', SyncRun::KIND_HIKVISION)
            ->whereNotNull('hikvision_terminal_id')
            ->groupBy('hikvision_terminal_id')
            ->select('hikvision_terminal_id', DB::raw('max(started_at) as last_at'))
            ->pluck('last_at', 'hikvision_terminal_id');

        $polledAt = DB::table('rusguard_audit_cursor')->value('polled_at');

        $lastRusGuardRun = SyncRun::where('kind', SyncRun::KIND_RUSGUARD)
            ->where('status', SyncRun::STATUS_SUCCESS)
            ->latest('started_at')
            ->first();

        return [
            'terminals' => $terminals->map(fn (HikvisionTerminal $terminal): array => [
                'id' => $terminal->id,
                'name' => $terminal->name,
                'last_push_at' => $this->ago($terminal->last_push_at),
                'last_event_at' => $this->ago($lastEvents[$terminal->id] ?? null),
                'last_sync_at' => $this->ago($lastRuns[$terminal->id] ?? null),
                // Silence here means the path from the device to us is broken somewhere — its
                // own config lapsed, it cannot resolve/reach us, or the tunnel in front of us
                // is down. Which end is at fault is not decidable from here; that it stopped is.
                'push_stale' => $terminal->last_push_at === null
                    || $terminal->last_push_at->lt(now()->subMinutes(self::PUSH_SILENCE_MINUTES)),
            ])->all(),
            'rusguard' => [
                'audit_polled' => $this->ago($polledAt),
                // It runs every minute; five is slack for a long RusGuard round-trip.
                'audit_stale' => $polledAt === null || Carbon::parse($polledAt)->lt(now()->subMinutes(5)),
                'last_sync' => $this->ago($lastRusGuardRun?->started_at),
                'last_sync_duration' => $lastRusGuardRun?->durationLabel(),
            ],
        ];
    }

    /**
     * People the sync deliberately gave up on. Each of these is silent by design — the sync
     * reports success and moves on — so this is the only place they surface.
     *
     * @return array{terminals: array<int, array<string, mixed>>, without_card: int}
     */
    private function problems(): array
    {
        $terminals = HikvisionTerminal::where('is_active', true)->orderBy('name')->get();

        $allCodes = $terminals
            ->flatMap(fn (HikvisionTerminal $terminal): array => array_keys($terminal->sync_stats['face_problems'] ?? []))
            ->unique();

        // full_name is an accessor over three columns, so it has to be built in PHP.
        $names = Employee::whereIn('emp_code', $allCodes)
            ->get(['emp_code', 'first_name', 'last_name', 'middle_name'])
            ->mapWithKeys(fn (Employee $employee): array => [$employee->emp_code => $employee->full_name]);

        return [
            'terminals' => $terminals->map(function (HikvisionTerminal $terminal) use ($names): array {
                $faceProblems = $terminal->sync_stats['face_problems'] ?? [];

                $split = collect($faceProblems)->partition(
                    fn (string $signature): bool => $signature === HikvisionSyncService::NO_LOCAL_PHOTO
                );

                return [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    // No photo in the local DB is a RusGuard data gap; anything else is the
                    // terminal itself refusing an image we do have. Different fixes, so they
                    // are worth separating rather than counting together.
                    'no_photo' => $split[0]->keys()
                        ->map(fn ($code): array => ['emp_code' => $code, 'name' => $names[$code] ?? '—'])
                        ->all(),
                    'refused' => $split[1]->keys()
                        ->map(fn ($code): array => ['emp_code' => $code, 'name' => $names[$code] ?? '—'])
                        ->all(),
                    'alcohol_failed' => (int) ($terminal->sync_stats['alcohol_failed'] ?? 0),
                ];
            })->all(),
            'without_card' => Employee::where('is_active', true)
                ->whereDoesntHave('keys', fn ($query) => $query->where('type', 'card'))
                ->count(),
        ];
    }

    private function jobName(string $payload): string
    {
        return class_basename(json_decode($payload, true)['displayName'] ?? 'Unknown');
    }

    private function ago(Carbon|string|null $moment): ?string
    {
        return $moment === null ? null : Carbon::parse($moment)->diffForHumans();
    }
}
