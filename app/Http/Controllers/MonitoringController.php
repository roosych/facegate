<?php

namespace App\Http\Controllers;

use App\Models\SyncRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class MonitoringController extends Controller
{
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
        ]);
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
}
