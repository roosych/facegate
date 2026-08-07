<?php

namespace Tests\Feature\Models;

use App\Models\AccessEvent;
use App\Models\AccessPoint;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessPointAccessEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_events_relation_uses_the_correct_foreign_key(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        $event = AccessEvent::factory()->create(['access_point_id' => $accessPoint->id]);

        $this->assertTrue($accessPoint->accessEvents->contains($event));
    }

    public function test_show_page_does_not_error(): void
    {
        $accessPoint = AccessPoint::factory()->create();
        AccessEvent::factory()->create(['access_point_id' => $accessPoint->id]);

        $response = $this->actingAs(User::factory()->create())->get(route('access-points.show', $accessPoint));

        $response->assertOk();
    }
}
