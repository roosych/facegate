<?php

namespace App\Models;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Throwable;

/**
 * One row per sync run, written by SyncRun::track().
 *
 * The per-terminal `sync_stats` column and the `sync_all_status` cache entry both hold only
 * the latest run, so a sync that got slower, or the moment a counter started drifting, was
 * invisible the second the next run overwrote it — the only trace of how long a run took
 * lived in the worker container's stdout and died with the container.
 */
#[Fillable([
    'kind', 'triggered_by', 'status', 'hikvision_terminal_id', 'access_point_id',
    'started_at', 'finished_at', 'duration_ms', 'stats', 'message',
])]
class SyncRun extends Model
{
    use HasFactory, Prunable;

    public const KIND_RUSGUARD = 'rusguard';

    public const KIND_HIKVISION = 'hikvision';

    public const KIND_ACCESS_POINT = 'access_point';

    /** Queued by the scheduler in routes/console.php. */
    public const TRIGGER_SCHEDULE = 'schedule';

    /** Queued by rusguard:poll-audit reacting to a RusGuard audit-log message. */
    public const TRIGGER_AUDIT = 'audit';

    /** Started from the web UI. */
    public const TRIGGER_MANUAL = 'manual';

    /** Started by an Artisan command run by hand. */
    public const TRIGGER_CONSOLE = 'console';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    /**
     * Record one sync run around $callback.
     *
     * The row is written before the work starts, so a run in flight is visible (and a run
     * killed mid-flight stays visible as `running` rather than vanishing), then stamped with
     * its duration and counters when the callback returns — or with the error, if it throws.
     * The exception is always re-thrown: the queue's own failure handling still owns it.
     *
     * @param  array{hikvision_terminal_id?: int, access_point_id?: int}  $subject
     * @param  Closure(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    public static function track(string $kind, string $triggeredBy, array $subject, Closure $callback): array
    {
        $run = static::create([
            'kind' => $kind,
            'triggered_by' => $triggeredBy,
            'status' => self::STATUS_RUNNING,
            'started_at' => now(),
            ...$subject,
        ]);

        $startedAt = hrtime(true);

        try {
            $stats = $callback();
        } catch (Throwable $e) {
            $run->stamp(self::STATUS_FAILED, $startedAt, [], $e->getMessage());

            throw $e;
        }

        $run->stamp(self::STATUS_SUCCESS, $startedAt, $stats);

        return $stats;
    }

    /**
     * Same shape the queue worker prints ("1m 35s"), so a run in the history reads the
     * same as it did in the worker's output.
     */
    public function durationLabel(): ?string
    {
        return self::formatDuration($this->duration_ms);
    }

    public static function formatDuration(?int $milliseconds): ?string
    {
        if ($milliseconds === null) {
            return null;
        }

        if ($milliseconds < 1000) {
            return $milliseconds.'ms';
        }

        $seconds = (int) round($milliseconds / 1000);

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }

    /** What this run acted on — a terminal, an access point, or the whole RusGuard org. */
    public function subjectName(): string
    {
        return $this->hikvisionTerminal?->name
            ?? $this->accessPoint?->name
            ?? 'RusGuard';
    }

    public function hikvisionTerminal(): BelongsTo
    {
        return $this->belongsTo(HikvisionTerminal::class);
    }

    public function accessPoint(): BelongsTo
    {
        return $this->belongsTo(AccessPoint::class, 'access_point_id');
    }

    /**
     * A run is a handful of counters, so a long history costs almost nothing — keep enough
     * of it to compare against the same weekday a couple of months back.
     */
    public function prunable(): Builder
    {
        return static::where('started_at', '<', now()->subDays(90));
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function stamp(string $status, float $startedAt, array $stats, ?string $message = null): void
    {
        $this->update([
            'status' => $status,
            'finished_at' => now(),
            'duration_ms' => (int) round((hrtime(true) - $startedAt) / 1_000_000),
            // Counters only. The services return nested detail alongside them (per-employee
            // lists, for instance) that belongs in sync_logs, not in a run summary.
            'stats' => array_filter($stats, fn (mixed $value): bool => ! is_array($value)),
            'message' => $message,
        ]);
    }

    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
