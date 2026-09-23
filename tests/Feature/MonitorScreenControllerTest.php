<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\MonitorScreen;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MonitorScreenControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_requires_login(): void
    {
        $response = $this->get(route('monitor-screens.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_index_lists_screens_with_their_access_point_count(): void
    {
        $user = User::factory()->create();
        $screen = MonitorScreen::factory()->create(['name' => 'Вход в офис']);
        $screen->setAccessPoints(AccessPoint::factory()->count(2)->create()->pluck('id')->all());

        $response = $this->actingAs($user)->get(route('monitor-screens.index'));

        $response->assertOk();
        $response->assertSee('Вход в офис');
        $response->assertSee('2 точек');
    }

    public function test_store_creates_a_screen_with_ordered_access_points(): void
    {
        $user = User::factory()->create();
        $first = AccessPoint::factory()->create();
        $second = AccessPoint::factory()->create();

        $response = $this->actingAs($user)->post(route('monitor-screens.store'), [
            'name' => 'Вход в офис',
            'access_point_ids' => [$second->id, $first->id],
        ]);

        $response->assertRedirect(route('monitor-screens.index'));

        $screen = MonitorScreen::firstWhere('name', 'Вход в офис');
        $this->assertNotNull($screen);
        $this->assertSame([$second->id, $first->id], $screen->accessPoints->pluck('id')->all());
    }

    public function test_store_requires_at_least_one_access_point(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('monitor-screens.store'), [
            'name' => 'Empty screen',
            'access_point_ids' => [],
        ]);

        $response->assertSessionHasErrors('access_point_ids');
        $this->assertDatabaseMissing('monitor_screens', ['name' => 'Empty screen']);
    }

    public function test_update_replaces_the_screens_access_points(): void
    {
        $user = User::factory()->create();
        $screen = MonitorScreen::factory()->create();
        $original = AccessPoint::factory()->create();
        $screen->setAccessPoints([$original->id]);
        $replacement = AccessPoint::factory()->create();

        $response = $this->actingAs($user)->patch(route('monitor-screens.update', $screen), [
            'name' => $screen->name,
            'access_point_ids' => [$replacement->id],
        ]);

        $response->assertRedirect(route('monitor-screens.index'));
        $this->assertSame([$replacement->id], $screen->fresh()->accessPoints->pluck('id')->all());
    }

    public function test_edit_still_lists_an_already_attached_point_that_has_since_gone_inactive(): void
    {
        // Regression: the picker used to only list active AccessPoints, so an already-attached
        // point that later went inactive was invisible to the edit form's JS. That JS filters
        // its `selected` array down to points it actually knows about before building the
        // hidden inputs — so saving the form for *any* reason (even just renaming the screen)
        // silently dropped the point from the screen. It must appear in $accessPoints so the
        // form's JS keeps it selected.
        $user = User::factory()->create();
        $screen = MonitorScreen::factory()->create();
        $retiredPoint = AccessPoint::factory()->create(['name' => 'Retired Turnstile', 'is_active' => false]);
        $screen->setAccessPoints([$retiredPoint->id]);

        $response = $this->actingAs($user)->get(route('monitor-screens.edit', $screen));

        $response->assertOk();
        $response->assertSee('Retired Turnstile');
    }

    public function test_update_keeps_an_inactive_point_when_resubmitted_unchanged(): void
    {
        $user = User::factory()->create();
        $screen = MonitorScreen::factory()->create();
        $retiredPoint = AccessPoint::factory()->create(['is_active' => false]);
        $screen->setAccessPoints([$retiredPoint->id]);

        $response = $this->actingAs($user)->patch(route('monitor-screens.update', $screen), [
            'name' => 'Renamed screen',
            'access_point_ids' => [$retiredPoint->id],
        ]);

        $response->assertRedirect(route('monitor-screens.index'));
        $this->assertSame([$retiredPoint->id], $screen->fresh()->accessPoints->pluck('id')->all());
    }

    public function test_destroy_removes_the_screen(): void
    {
        $user = User::factory()->create();
        $screen = MonitorScreen::factory()->create();

        $response = $this->actingAs($user)->delete(route('monitor-screens.destroy', $screen));

        $response->assertRedirect(route('monitor-screens.index'));
        $this->assertDatabaseMissing('monitor_screens', ['id' => $screen->id]);
    }
}
