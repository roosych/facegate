<?php

namespace App\Http\Controllers;

use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use App\Models\SyncRun;
use App\Services\HikvisionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class HikvisionSyncController extends Controller
{
    public function index(): View
    {
        $terminals = HikvisionTerminal::where('is_active', true)
            ->with('turnstile:id,name')
            ->orderBy('name')
            ->get();

        return view('hikvision.sync', compact('terminals'));
    }

    public function syncTerminal(HikvisionTerminal $hikvision): JsonResponse
    {
        dispatch(new SyncHikvisionTerminalJob($hikvision, SyncRun::TRIGGER_MANUAL));

        Cache::put(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$hikvision->id, [
            'status' => 'queued',
            'terminal' => $hikvision->name,
            'done' => 0,
            'total' => 0,
            'synced' => 0,
            'removed' => 0,
            'errors' => 0,
        ], now()->addHour());

        return response()->json(['ok' => true]);
    }

    public function syncStatus(HikvisionTerminal $hikvision): JsonResponse
    {
        $status = Cache::get(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$hikvision->id);

        return response()->json($status ?? ['status' => 'idle']);
    }
}
