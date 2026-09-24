<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class AlcoholCleaningNotificationSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function fakeRusGuardDb(): void
    {
        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);
    }

    public function test_updates_the_cleaning_notification_emails_via_the_form(): void
    {
        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->post(route('alcohol.cleaning-notifications'), [
            'cleaning_notification_emails' => 'it@example.com, hardware@example.com',
        ]);

        $response->assertRedirect(route('alcohol.index'));
        $this->assertSame(['it@example.com', 'hardware@example.com'], Setting::alcoholCleaningNotificationEmails());
    }

    public function test_rejects_an_invalid_email(): void
    {
        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->post(route('alcohol.cleaning-notifications'), [
            'cleaning_notification_emails' => 'not-an-email',
        ]);

        $response->assertSessionHasErrors('cleaning_notification_emails');
    }

    public function test_is_independent_of_the_failed_test_notification_list(): void
    {
        Setting::set('alcohol_notification_emails', 'leadership@example.com');

        $this->fakeRusGuardDb();

        $this->actingAs(User::factory()->create())->post(route('alcohol.cleaning-notifications'), [
            'cleaning_notification_emails' => 'it@example.com',
        ]);

        $this->assertSame(['leadership@example.com'], Setting::alcoholNotificationEmails());
        $this->assertSame(['it@example.com'], Setting::alcoholCleaningNotificationEmails());
    }

    public function test_index_page_shows_the_current_cleaning_notification_emails(): void
    {
        Setting::set('alcohol_cleaning_notification_emails', 'it@example.com,hardware@example.com');

        $this->fakeRusGuardDb();

        $response = $this->actingAs(User::factory()->create())->get(route('alcohol.index'));

        $response->assertOk();
        $response->assertSee('it@example.com, hardware@example.com', false);
    }
}
