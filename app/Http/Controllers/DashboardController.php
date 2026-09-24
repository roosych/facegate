<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Services\HikvisionSyncService;
use App\Services\RusGuard\RusGuardHealth;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = [
            'employees' => Employee::count(),
            'active_employees' => Employee::where('is_active', true)->count(),
            'accessPoints' => AccessPoint::count(),
            'terminals' => HikvisionTerminal::where('is_active', true)->count(),
            'alcohol_terminals' => HikvisionTerminal::where('is_active', true)->get()
                ->filter(fn (HikvisionTerminal $terminal) => $terminal->resolvedAlcoholParams()['enabled'])
                ->count(),
            'events_today' => AccessEvent::whereDate('event_time', today())->count(),
            'events_week' => AccessEvent::whereBetween('event_time', [now()->startOfWeek(), now()])->count(),
            'last_sync' => SyncLog::where('status', 'success')->latest()->value('created_at'),
            'failed_syncs' => SyncLog::where('status', 'error')->whereDate('created_at', today())->count(),
            'no_card' => Employee::where('is_active', true)->whereDoesntHave('keys', fn ($q) => $q->where('type', 'card'))->count(),
        ];

        $recentEvents = AccessEvent::with(['employee', 'accessPoint'])
            ->latest('event_time')
            ->limit(8)
            ->get();

        $recentSyncs = SyncLog::with(['employee', 'hikvisionTerminal'])
            ->latest()
            ->limit(8)
            ->get();

        $rusGuardHealth = RusGuardHealth::snapshot();

        return view('dashboard', compact('stats', 'recentEvents', 'recentSyncs', 'rusGuardHealth'));
    }

    public function status(): JsonResponse
    {
        $rusGuardSync = Cache::get(SyncService::SYNC_STATUS_KEY, ['status' => 'idle']);

        $terminals = HikvisionTerminal::where('is_active', true)
            ->get()
            ->map(function (HikvisionTerminal $terminal): array {
                $live = Cache::get(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$terminal->id);
                $stats = $terminal->sync_stats ?? [];

                // Live config, not the `sync_stats` snapshot — that's only refreshed by a full
                // terminal sync, so it stays stale (and wrongly gates the cleaning counter below)
                // for hours after a toggle flips this on the device without going through a sync.
                $alcoholEnabled = $terminal->resolvedAlcoholParams()['enabled'];

                return [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'status' => $live['status'] ?? 'idle',
                    'done' => $live['done'] ?? null,
                    'total' => $live['total'] ?? null,
                    'synced_at' => $stats['synced_at'] ?? null,
                    'persons_failed' => count($stats['persons_failed'] ?? []),
                    'alcohol_enabled' => $alcoholEnabled,
                    'alcohol_failed' => $stats['alcohol_failed'] ?? 0,
                    'alcohol_test_count' => $alcoholEnabled ? $terminal->alcoholTestCountSinceCleaning() : 0,
                    'alcohol_cleaning_threshold' => config('alcohol.cleaning_threshold'),
                    'needs_alcohol_cleaning' => $alcoholEnabled && $terminal->needsAlcoholCleaning(),
                ];
            });

        $jobsByType = DB::table('jobs')->pluck('payload')
            ->map(fn (string $payload): string => json_decode($payload, true)['displayName'] ?? 'Unknown')
            ->countBy();

        $recentFailures = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->limit(5)
            ->get(['uuid', 'payload', 'failed_at'])
            ->map(fn ($row) => [
                'job' => json_decode($row->payload, true)['displayName'] ?? 'Unknown',
                'failed_at' => $row->failed_at,
            ]);

        return response()->json([
            'rusguard_sync' => $rusGuardSync,
            'rusguard_db' => RusGuardHealth::snapshot(),
            'terminals' => $terminals,
            'queue' => [
                'pending' => $jobsByType->sum(),
                'by_type' => $jobsByType,
            ],
            'recent_failures' => $recentFailures,
            'failed_last_24h' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count(),
        ]);
    }
}
