<?php

namespace App\Services\RusGuard;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Whether RusGuard is currently reachable, derived from the audit poller's cursor rather than a
 * live round-trip on every page load. See RusGuardPollAudit::handle(): `polled_at` is only
 * stamped after the audit-log read actually succeeds, so it doubles as "RusGuard answered us" —
 * if the RusGuard DB connection is down, getLatestAuditId() throws before that update runs, and
 * the timestamp goes stale.
 */
class RusGuardHealth
{
    /**
     * The poller runs once a minute; five minutes of silence is well past a routine slow
     * round-trip and still catches a real outage quickly — matches the same threshold used for
     * the audit-staleness signal on the Monitoring page.
     */
    private const STALE_AFTER_MINUTES = 5;

    /**
     * @return array{online: bool, polled_at: ?string}
     */
    public static function snapshot(): array
    {
        $polledAt = DB::table('rusguard_audit_cursor')->value('polled_at');

        return [
            'online' => $polledAt !== null && Carbon::parse($polledAt)->gte(now()->subMinutes(self::STALE_AFTER_MINUTES)),
            'polled_at' => $polledAt === null ? null : Carbon::parse($polledAt)->diffForHumans(),
        ];
    }
}
