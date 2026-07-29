<?php

namespace App\Console\Commands;

use App\Jobs\SyncAllJob;
use App\Jobs\SyncHikvisionTerminalJob;
use App\Models\HikvisionTerminal;
use App\Services\RusGuard\RusGuardDatabaseService;
use App\Services\SyncService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

#[Signature('rusguard:poll-audit')]
#[Description('Poll RusGuard\'s AuditData log for access/alcohol-group changes and resync affected terminals')]
class RusGuardPollAudit extends Command
{
    public function handle(RusGuardDatabaseService $rusGuardDb): int
    {
        $cursor = DB::table('rusguard_audit_cursor')->where('id', 1)->value('last_audit_id');
        $latestId = $rusGuardDb->getLatestAuditId();

        // First run (or a fresh table): jump straight to "now" instead of replaying the
        // entire audit history through a full resync.
        if ($cursor === null) {
            DB::table('rusguard_audit_cursor')->where('id', 1)->update(['last_audit_id' => $latestId, 'updated_at' => now()]);
            $this->info("Bootstrapped audit cursor at {$latestId}.");

            return self::SUCCESS;
        }

        if ($latestId <= $cursor) {
            return self::SUCCESS;
        }

        $events = $rusGuardDb->getRelevantAuditEventsSince($cursor);

        if ($events !== []) {
            $this->info(count($events).' relevant RusGuard audit event(s) since #'.$cursor.'.');

            foreach ($events as $event) {
                $this->line("  #{$event['id']} [{$event['dateTime']}] {$event['message']}: {$event['details']}");
            }

            // A single SyncAllJob run already reads the org's current RusGuard state, so it
            // naturally picks up every audit event that arrived before it started — including
            // ones from audit polls that ran while it was in flight. Dispatching another chain
            // on top would just queue a redundant multi-minute resync behind it, so skip while
            // one is already running; this poll's cursor advance means the in-flight run (or
            // the next poll once it's done) still covers these events.
            $syncStatus = Cache::get(SyncService::SYNC_STATUS_KEY)['status'] ?? null;

            if ($syncStatus === 'running') {
                $this->info('A RusGuard resync is already in progress — skipping, cursor still advances.');
            } else {
                // SyncAllJob (org-wide RusGuard resync) has a 1-hour timeout — far too heavy to
                // run inline in a once-a-minute command. Queue it, and chain the Hikvision pushes
                // after it so they read the freshly-synced local pivot instead of stale data.
                // Chaining (rather than dispatching separately) guarantees the ordering even if
                // this deployment ever moves beyond a single queue worker.
                $terminalJobs = HikvisionTerminal::where('is_active', true)
                    ->get()
                    ->map(fn (HikvisionTerminal $terminal) => new SyncHikvisionTerminalJob($terminal))
                    ->all();

                Bus::chain([new SyncAllJob(), ...$terminalJobs])->dispatch();
            }
        }

        DB::table('rusguard_audit_cursor')->where('id', 1)->update(['last_audit_id' => $latestId, 'updated_at' => now()]);

        return self::SUCCESS;
    }
}
