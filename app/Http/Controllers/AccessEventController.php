<?php

namespace App\Http\Controllers;

use App\Jobs\FetchHikvisionEventsJob;
use App\Models\AccessEvent;
use App\Models\HikvisionTerminal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class AccessEventController extends Controller
{
    public function index(Request $request): View
    {
        $terminals = HikvisionTerminal::orderBy('name')->get();

        $query = AccessEvent::with(['employee', 'hikvisionTerminal'])
            ->alcoholAboveZero()
            ->latest('event_time');

        if ($terminalId = $request->integer('terminal')) {
            $query->where('hikvision_terminal_id', $terminalId);
        }

        if ($dateFrom = $request->input('date_from')) {
            $query->whereDate('event_time', '>=', $dateFrom);
        }

        if ($dateTo = $request->input('date_to')) {
            $query->whereDate('event_time', '<=', $dateTo);
        }

        if ($search = $request->input('employee')) {
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('first_name', 'ilike', "%{$search}%")
                    ->orWhere('last_name', 'ilike', "%{$search}%")
                    ->orWhere('middle_name', 'ilike', "%{$search}%")
                    ->orWhereRaw('CAST(emp_code AS TEXT) ilike ?', ["%{$search}%"]);
            });
        }

        $events = $query->paginate(50)->withQueryString();

        return view('events.index', compact('events', 'terminals'));
    }

    /**
     * Dispatch a background job to fetch events from a Hikvision terminal.
     */
    public function fetchFromTerminal(HikvisionTerminal $terminal, Request $request): JsonResponse
    {
        $startTime = $request->input('start')
            ? Carbon::parse($request->input('start'))
            : now()->subDay();

        $endTime = $request->input('end')
            ? Carbon::parse($request->input('end'))
            : now();

        Cache::put(FetchHikvisionEventsJob::STATUS_KEY.'_'.$terminal->id, [
            'status'   => 'queued',
            'terminal' => $terminal->name,
            'imported' => 0,
            'total'    => 0,
        ], now()->addHour());

        FetchHikvisionEventsJob::dispatch($terminal, $startTime, $endTime);

        return response()->json(['queued' => true]);
    }

    public function fetchStatus(HikvisionTerminal $terminal): JsonResponse
    {
        $status = Cache::get(FetchHikvisionEventsJob::STATUS_KEY.'_'.$terminal->id);

        return response()->json($status ?? ['status' => 'idle']);
    }

}
