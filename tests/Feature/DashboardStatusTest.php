<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\HikvisionTerminal;
use App\Models\User;
use App\Services\HikvisionSyncService;
use App\Services\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_rusguard_db_health(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(['id' => 1], ['polled_at' => now()->subMinutes(20)]);

        $response = $this->actingAs(User::factory()->create())->getJson(route('dashboard.status'));

        $response->assertOk();
        $response->assertJsonPath('rusguard_db.online', false);
    }

    public function test_reports_rusguard_sync_and_terminal_status(): void
    {
        Cache::put(SyncService::SYNC_STATUS_KEY, [
            'status' => 'running',
            'current' => 'Point A',
            'emp_done' => 5,
            'emp_total' => 10,
        ]);

        $terminal = HikvisionTerminal::factory()->create([
            'is_active' => true,
            'name' => 'Post 1',
            'sync_stats' => ['synced_at' => '2026-07-29 10:00:00', 'persons_failed' => ['x'], 'alcohol_enabled' => true, 'alcohol_failed' => 2],
        ]);
        Cache::put(HikvisionSyncService::SYNC_STATUS_KEY.'_'.$terminal->id, ['status' => 'running', 'done' => 3, 'total' => 8]);

        $response = $this->actingAs(User::factory()->create())->getJson(route('dashboard.status'));

        $response->assertOk();
        $response->assertJsonPath('rusguard_sync.status', 'running');
        $response->assertJsonPath('rusguard_sync.emp_done', 5);
        $response->assertJsonPath('terminals.0.name', 'Post 1');
        $response->assertJsonPath('terminals.0.status', 'running');
        $response->assertJsonPath('terminals.0.done', 3);
        $response->assertJsonPath('terminals.0.persons_failed', 1);
        $response->assertJsonPath('terminals.0.alcohol_failed', 2);
    }

    public function test_reports_alcohol_cleaning_status_for_alcohol_enabled_terminals(): void
    {
        config(['alcohol.cleaning_threshold' => 1]);

        $terminal = HikvisionTerminal::factory()->alcoholEnabled()->create([
            'is_active' => true,
            'sync_stats' => ['alcohol_enabled' => true],
        ]);
        AccessEvent::factory()->for($terminal, 'hikvisionTerminal')->create([
            'raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']],
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson(route('dashboard.status'));

        $response->assertOk();
        $response->assertJsonPath('terminals.0.alcohol_test_count', 1);
        $response->assertJsonPath('terminals.0.alcohol_cleaning_threshold', 1);
        $response->assertJsonPath('terminals.0.needs_alcohol_cleaning', true);
    }

    public function test_excludes_inactive_terminals(): void
    {
        HikvisionTerminal::factory()->create(['is_active' => false, 'name' => 'Retired']);

        $response = $this->actingAs(User::factory()->create())->getJson(route('dashboard.status'));

        $response->assertOk();
        $response->assertJsonCount(0, 'terminals');
    }

    public function test_reports_queue_and_failed_job_counts(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\SyncAllJob']),
            'attempts' => 0,
            'created_at' => now()->getTimestamp(),
            'available_at' => now()->getTimestamp(),
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\FetchHikvisionEventsJob']),
            'exception' => 'boom',
            'failed_at' => now(),
        ]);

        $response = $this->actingAs(User::factory()->create())->getJson(route('dashboard.status'));

        $response->assertOk();
        $response->assertJsonPath('queue.pending', 1);
        $response->assertJsonPath('queue.by_type.App\\Jobs\\SyncAllJob', 1);
        $response->assertJsonPath('failed_last_24h', 1);
        $response->assertJsonPath('recent_failures.0.job', 'App\\Jobs\\FetchHikvisionEventsJob');
    }
}
