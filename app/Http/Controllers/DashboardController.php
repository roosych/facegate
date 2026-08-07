<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Services\HikvisionSyncService;
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

        return view('dashboard', compact('stats', 'recentEvents', 'recentSyncs'));
    }

    public function status(): JsonResponse
    {
        $rusGuardSync = Cache::get(SyncService::SYNC_STATUS_KEY, ['status' => 'idle']);

        $terminals = HikvisionTerminal::where('is_active', true)
            ->get()
            ->map(function (HikvisionTerminal $terminal): array {
                $live = Cache::get(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$terminal->id);
                $stats = $terminal->sync_stats ?? [];

                return [
                    'id' => $terminal->id,
                    'name' => $terminal->name,
                    'status' => $live['status'] ?? 'idle',
                    'done' => $live['done'] ?? null,
                    'total' => $live['total'] ?? null,
                    'synced_at' => $stats['synced_at'] ?? null,
                    'persons_failed' => count($stats['persons_failed'] ?? []),
                    'alcohol_enabled' => (bool) ($stats['alcohol_enabled'] ?? false),
                    'alcohol_failed' => $stats['alcohol_failed'] ?? 0,
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
