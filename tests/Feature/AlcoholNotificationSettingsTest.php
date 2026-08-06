<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AlcoholNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRusGuardDb(): void
    {
        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);
    }

    public function test_updates_the_notification_settings_via_the_form(): void
    {
        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->post(route('alcohol.notifications'), [
            'notification_threshold' => 30,
            'notification_emails' => 'security@example.com, hr@example.com',
        ]);

        $response->assertRedirect(route('alcohol.index'));
        $this->assertSame(30.0, Setting::alcoholNotificationThreshold());
        $this->assertSame(['security@example.com', 'hr@example.com'], Setting::alcoholNotificationEmails());
    }

    public function test_rejects_an_invalid_email(): void
    {
        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->post(route('alcohol.notifications'), [
            'notification_threshold' => 30,
            'notification_emails' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('notification_emails');
    }

    public function test_rejects_a_negative_threshold(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('alcohol.notifications'), [
            'notification_threshold' => -1,
        ]);

        $response->assertSessionHasErrors('notification_threshold');
    }

    public function test_index_page_shows_the_current_notification_settings(): void
    {
        Setting::set('alcohol_notification_threshold', '30');
        Setting::set('alcohol_notification_emails', 'security@example.com,hr@example.com');

        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->get(route('alcohol.index'));

        $response->assertOk();
        $response->assertSee('value="30"', false);
        $response->assertSee('security@example.com, hr@example.com', false);
    }
}
