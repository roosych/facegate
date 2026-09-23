<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_card_stat_excludes_inactive_employees(): void
    {
        Employee::factory()->create(['is_active' => true]); // active, no card — should count
        Employee::factory()->create(['is_active' => false]); // inactive, no card — should NOT count

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('stats', fn ($stats) => $stats['no_card'] === 1);
    }

    public function test_passes_rusguard_db_health_to_the_view(): void
    {
        DB::table('rusguard_audit_cursor')->updateOrInsert(['id' => 1], ['polled_at' => now()->subMinute()]);

        $response = $this->actingAs(User::factory()->create())->get(route('dashboard'));

        $response->assertOk();
        $response->assertViewHas('rusGuardHealth', fn ($health) => $health['online'] === true);
        $response->assertSee('RusGuard БД');
    }
}
