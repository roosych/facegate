<?php

namespace App\Http\Controllers;

use App\Jobs\PushTurnstileToDevicesJob;
use App\Jobs\SyncAllJob;
use App\Jobs\SyncTurnstileJob;
use App\Models\Turnstile;
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
        $turnstiles = Turnstile::with(['enterDevice', 'exitDevice'])
            ->where('is_active', true)
            ->get();

        return view('sync.index', compact('turnstiles'));
    }

    public function syncTurnstile(Turnstile $turnstile): JsonResponse|RedirectResponse
    {
        Cache::put(SyncService::SYNC_STATUS_KEY.'_'.$turnstile->id, [
            'status' => 'pending',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'emp_done' => 0,
            'emp_total' => 0,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        SyncTurnstileJob::dispatch($turnstile);

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', "Sync started for \"{$turnstile->name}\".");
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

        SyncAllJob::dispatch();

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back();
    }

    public function pushTurnstile(Turnstile $turnstile): JsonResponse|RedirectResponse
    {
        PushTurnstileToDevicesJob::dispatch($turnstile);

        if (request()->ajax() || request()->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->back()->with('success', "Push to devices started for \"{$turnstile->name}\".");
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

    public function syncTurnstileStatus(Turnstile $turnstile): JsonResponse
    {
        $key = SyncService::SYNC_STATUS_KEY.'_'.$turnstile->id;
        $status = Cache::get($key, ['status' => 'idle']);

        if (($status['status'] ?? '') === 'done') {
            Cache::forget($key);
        }

        return response()->json($status);
    }
}
