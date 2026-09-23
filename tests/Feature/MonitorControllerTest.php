<?php

namespace Tests\Feature;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\MonitorScreen;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class MonitorControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_requires_a_signed_url(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        $employee = Employee::factory()->create();

        $response = $this->get("/monitor/{$accessPoint->id}/employees/{$employee->id}/photo");

        $response->assertForbidden();
    }

    public function test_photo_404s_when_the_employee_has_no_photo_on_disk(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        $employee = Employee::factory()->create(['photo_path' => null]);

        $response = $this->get(URL::signedRoute('monitor.photo', ['accessPoint' => $accessPoint, 'employee' => $employee]));

        $response->assertNotFound();
    }

    public function test_show_screen_rejects_an_unsigned_url(): void
    {
        $screen = MonitorScreen::factory()->create();

        $response = $this->get("/monitor/screen/{$screen->id}");

        $response->assertForbidden();
    }

    public function test_show_screen_renders_its_access_points_in_pivot_order(): void
    {
        $screen = MonitorScreen::factory()->create();
        $first = AccessPoint::factory()->create(['name' => 'Turnstile A']);
        $second = AccessPoint::factory()->create(['name' => 'Turnstile B']);
        $screen->setAccessPoints([$second->id, $first->id]);

        $response = $this->get(URL::signedRoute('monitor.show-screen', ['monitorScreen' => $screen]));

        $response->assertOk();
        $response->assertSeeInOrder(['Turnstile B', 'Turnstile A']);
    }

    public function test_status_screen_merges_a_turnstiles_terminals_into_one_block(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        $screen = MonitorScreen::factory()->create();
        $screen->setAccessPoints([$accessPoint->id]);

        $inTerminal = HikvisionTerminal::factory()->create([
            'access_point_id' => $accessPoint->id,
            'direction' => 'in',
        ]);
        $outTerminal = HikvisionTerminal::factory()->create([
            'access_point_id' => $accessPoint->id,
            'direction' => 'out',
        ]);

        $olderEmployee = Employee::factory()->create(['last_name' => 'Older']);
        $newerEmployee = Employee::factory()->create(['last_name' => 'Newer']);

        Cache::put($inTerminal->monitorCacheKey(), [
            'employee_id' => $olderEmployee->id,
            'employee_name' => $olderEmployee->full_name,
            'emp_code' => $olderEmployee->emp_code,
            'event_time' => now()->subMinutes(5)->toIso8601String(),
            'alcohol_tested' => false,
            'alcohol_passed' => null,
            'alcohol_concentration' => null,
        ], now()->addDay());

        Cache::put($outTerminal->monitorCacheKey(), [
            'employee_id' => $newerEmployee->id,
            'employee_name' => $newerEmployee->full_name,
            'emp_code' => $newerEmployee->emp_code,
            'position' => 'Mütəxəssis',
            'department' => 'İT şöbəsi',
            'event_time' => now()->toIso8601String(),
            'alcohol_tested' => false,
            'alcohol_passed' => null,
            'alcohol_concentration' => null,
        ], now()->addDay());

        $response = $this->get(URL::signedRoute('monitor.status-screen', ['monitorScreen' => $screen]));

        $response->assertOk();
        $response->assertJsonCount(1, 'access_points');
        $response->assertJsonPath('access_points.0.access_point_id', $accessPoint->id);
        // The "out" terminal's event is the more recent one, so it — not "in" — wins the block.
        $response->assertJsonPath('access_points.0.event.employee_name', $newerEmployee->full_name);
        $response->assertJsonPath('access_points.0.event.direction', 'out');
        $response->assertJsonPath('access_points.0.event.direction_label', 'Çıxış');
        $response->assertJsonPath('access_points.0.event.position', 'Mütəxəssis');
        $response->assertJsonPath('access_points.0.event.department', 'İT şöbəsi');
    }

    public function test_status_screen_reports_no_event_when_nothing_is_cached_yet(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        HikvisionTerminal::factory()->create(['access_point_id' => $accessPoint->id]);
        $screen = MonitorScreen::factory()->create();
        $screen->setAccessPoints([$accessPoint->id]);

        $response = $this->get(URL::signedRoute('monitor.status-screen', ['monitorScreen' => $screen]));

        $response->assertOk();
        $response->assertJsonPath('access_points.0.event', null);
    }

    public function test_status_screen_reflects_the_screens_current_access_points(): void
    {
        $screen = MonitorScreen::factory()->create();
        $accessPoint = AccessPoint::factory()->create();
        $screen->setAccessPoints([$accessPoint->id]);

        $response = $this->get(URL::signedRoute('monitor.status-screen', ['monitorScreen' => $screen]));

        $response->assertOk();
        $response->assertJsonCount(1, 'access_points');
        $response->assertJsonPath('access_points.0.access_point_id', $accessPoint->id);
    }

    public function test_status_screen_url_keeps_working_after_the_screens_points_change(): void
    {
        $screen = MonitorScreen::factory()->create();
        $original = AccessPoint::factory()->create();
        $screen->setAccessPoints([$original->id]);

        $url = URL::signedRoute('monitor.status-screen', ['monitorScreen' => $screen]);

        $replacement = AccessPoint::factory()->create();
        $screen->setAccessPoints([$replacement->id]);

        $response = $this->get($url);

        $response->assertOk();
        $response->assertJsonPath('access_points.0.access_point_id', $replacement->id);
    }
}
