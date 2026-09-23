<?php

namespace Tests\Feature\Services;

use App\Services\RusGuard\RusGuardHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RusGuardHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_offline_when_the_cursor_has_never_been_polled(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(['id' => 1], ['polled_at' => null]);

        $snapshot = RusGuardHealth::snapshot();

        $this->assertFalse($snapshot['online']);
        $this->assertNull($snapshot['polled_at']);
    }

    public function test_reports_online_for_a_poll_within_the_last_five_minutes(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(['id' => 1], ['polled_at' => now()->subMinutes(2)]);

        $snapshot = RusGuardHealth::snapshot();

        $this->assertTrue($snapshot['online']);
        $this->assertNotNull($snapshot['polled_at']);
    }

    public function test_reports_offline_once_the_poll_is_older_than_five_minutes(): void
    {
        // The poller only stamps polled_at after RusGuard actually answers (see
        // RusGuardPollAudit::handle()) — a stale timestamp means the last attempt failed before
        // reaching that point, i.e. RusGuard was unreachable.
        DB::table('rusguard_audit_cursor')->updateOrInsert(['id' => 1], ['polled_at' => now()->subMinutes(6)]);

        $snapshot = RusGuardHealth::snapshot();

        $this->assertFalse($snapshot['online']);
    }
}
