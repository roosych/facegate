<?php

namespace App\Http\Controllers;

use App\Jobs\SyncAccessPointJob;
use App\Jobs\SyncAllJob;
use App\Models\AccessPoint;
use App\Models\SyncRun;
use App\Services\SyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SyncController extends Controller
{
    public function __construct(private SyncService $syncService) {}

    public function index(): View
    {
        $accessPoints = AccessPoint::query()
            ->where('is_active', true)
            ->get();

        return view('sync.index', compact('accessPoints'));
    }

    public function syncAccessPoint(AccessPoint $accessPoint): JsonResponse|RedirectResponse
    {
        Cache::put(SyncService::SYNC_STATUS_KEY.'_'.$accessPoint->id, [
            'status' => 'pending',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'emp_done' => 0,
            'emp_total' => 0,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        SyncAccessPointJob::dispatch($accessPoint, SyncRun::TRIGGER_MANUAL);

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', "Sync started for \"{$accessPoint->name}\".");
    }

    public function syncAll(): JsonResponse|RedirectResponse
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, [
            'status' => 'pending',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        SyncAllJob::dispatch(SyncRun::TRIGGER_MANUAL);

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back();
    }

    public function syncStatus(): JsonResponse
    {
        $status = Cache::get(SyncService::SYNC_STATUS_KEY, ['status' => 'idle']);

        // If stuck in pending/running but no job in queue — job died silently
        if (in_array($status['status'] ?? '', ['pending', 'running'])) {
            $hasJob = DB::table('jobs')->where('queue', 'default')->exists();

            if (! $hasJob) {
                Cache::forget(SyncService::SYNC_STATUS_KEY);
                $status = ['status' => 'idle'];
            }
        }

        // Clear "done" after first read so reloaded page gets "idle" and doesn't loop
        if (($status['status'] ?? '') === 'done') {
            Cache::forget(SyncService::SYNC_STATUS_KEY);
        }

        return response()->json($status);
    }

    public function syncAccessPointStatus(AccessPoint $accessPoint): JsonResponse
    {
        $key = SyncService::SYNC_STATUS_KEY.'_'.$accessPoint->id;
        $status = Cache::get($key, ['status' => 'idle']);

        if (($status['status'] ?? '') === 'done') {
            Cache::forget($key);
        }

        return response()->json($status);
    }
}
