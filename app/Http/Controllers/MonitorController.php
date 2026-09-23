<?php

namespace App\Http\Controllers;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\MonitorScreen;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Public, no-login kiosk screen mounted on a monitor facing one or more turnstiles. Reachable
 * only via a signed URL (no predictable /monitor/{id}), since there's no session to gate it
 * with. See HikvisionEventWebhookController::cacheForMonitor() for where the data this reads
 * comes from.
 *
 * One block is rendered per AccessPoint (turnstile), not per HikvisionTerminal: a turnstile
 * commonly has an "in" and an "out" terminal, but the screen shows whichever one most recently
 * saw a pass, labelled with that terminal's own direction — not a fixed in/out pair of blocks.
 *
 * Two ways to reach a screen: a named, persistent MonitorScreen (see showScreen()/statusScreen()
 * — the supported path, managed at /monitor-screens) whose URL keeps working after its turnstile
 * list changes, or a raw comma list of access point ids (show()/status()) for a quick one-off
 * look without creating a screen.
 */
class MonitorController extends Controller
{
    private const DIRECTION_LABELS = [
        'in' => 'Giriş',
        'out' => 'Çıxış',
    ];

    public function show(string $accessPoints): View
    {
        $points = $this->resolveAccessPoints($accessPoints);
        $statusUrl = URL::signedRoute('monitor.status', ['accessPoints' => $accessPoints]);

        return view('monitor.show', ['accessPoints' => $points, 'statusUrl' => $statusUrl]);
    }

    public function status(string $accessPoints): JsonResponse
    {
        return $this->statusResponse($this->resolveAccessPoints($accessPoints));
    }

    public function showScreen(MonitorScreen $monitorScreen): View
    {
        $statusUrl = URL::signedRoute('monitor.status-screen', ['monitorScreen' => $monitorScreen]);

        return view('monitor.show', ['accessPoints' => $monitorScreen->accessPoints, 'statusUrl' => $statusUrl]);
    }

    public function statusScreen(MonitorScreen $monitorScreen): JsonResponse
    {
        return $this->statusResponse($monitorScreen->accessPoints);
    }

    public function photo(AccessPoint $accessPoint, Employee $employee): BinaryFileResponse
    {
        $path = $employee->photoAbsolutePath();

        if ($path === null) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    /**
     * @param  Collection<int, AccessPoint>  $points
     */
    private function statusResponse(Collection $points): JsonResponse
    {
        return response()->json([
            'access_points' => $points->map(fn (AccessPoint $ap) => $this->accessPointPayload($ap))->all(),
        ]);
    }

    /**
     * @return Collection<int, AccessPoint>
     */
    private function resolveAccessPoints(string $accessPoints): Collection
    {
        $ids = collect(explode(',', $accessPoints))
            ->map(fn (string $id) => (int) $id)
            ->unique();

        $found = AccessPoint::whereIn('id', $ids)->get()->keyBy('id');

        $ordered = $ids->map(fn (int $id) => $found->get($id))->filter()->values();

        if ($ordered->isEmpty()) {
            abort(404);
        }

        return $ordered;
    }

    /**
     * @return array<string, mixed>
     */
    private function accessPointPayload(AccessPoint $accessPoint): array
    {
        $terminals = $accessPoint->hikvisionTerminals()->where('is_active', true)->get();

        $winner = null;
        $winnerEvent = null;
        $winnerTime = null;

        foreach ($terminals as $terminal) {
            $event = Cache::get($terminal->monitorCacheKey());

            if ($event === null) {
                continue;
            }

            $eventTime = Carbon::parse($event['event_time']);

            if ($winnerTime === null || $eventTime->greaterThan($winnerTime)) {
                $winner = $terminal;
                $winnerEvent = $event;
                $winnerTime = $eventTime;
            }
        }

        return [
            'access_point_id' => $accessPoint->id,
            'access_point_name' => $accessPoint->name,
            'event' => $winnerEvent === null ? null : [
                'employee_name' => $winnerEvent['employee_name'],
                'position' => $winnerEvent['position'] ?? null,
                'department' => $winnerEvent['department'] ?? null,
                'photo_url' => $winnerEvent['employee_id'] !== null
                    ? URL::signedRoute('monitor.photo', ['accessPoint' => $accessPoint, 'employee' => $winnerEvent['employee_id']])
                    : null,
                'event_time' => $winnerEvent['event_time'],
                'direction' => $winner?->direction,
                'direction_label' => self::DIRECTION_LABELS[$winner?->direction] ?? null,
                'alcohol' => $winnerEvent['alcohol_tested'] ? [
                    'passed' => $winnerEvent['alcohol_passed'],
                    'concentration' => $winnerEvent['alcohol_concentration'],
                ] : null,
            ],
        ];
    }
}
