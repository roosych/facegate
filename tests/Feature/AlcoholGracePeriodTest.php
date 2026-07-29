<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AlcoholGracePeriodTest extends TestCase
{
    use RefreshDatabase;

    public function test_updates_the_grace_period_via_the_form(): void
    {
        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('alcohol.grace-period'), ['grace_minutes' => 45]);

        $response->assertRedirect(route('alcohol.index'));
        $this->assertSame(45, Setting::alcoholSkipGraceMinutes());
    }

    public function test_rejects_a_non_positive_grace_period(): void
    {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('alcohol.grace-period'), ['grace_minutes' => 0]);

        $response->assertSessionHasErrors('grace_minutes');
    }

    public function test_index_page_shows_the_current_grace_period(): void
    {
        Setting::set('alcohol_skip_grace_minutes', '45');

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);

        $response = $this->actingAs(User::factory()->create())->get(route('alcohol.index'));

        $response->assertOk();
        $response->assertSee('value="45"', false);
    }
}
