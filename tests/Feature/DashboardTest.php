<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
