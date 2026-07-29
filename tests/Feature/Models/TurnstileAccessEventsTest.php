<?php

namespace Tests\Feature\Models;

use App\Models\AccessEvent;
use App\Models\Turnstile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurnstileAccessEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_access_events_relation_uses_the_correct_foreign_key(): void
    {
        $turnstile = Turnstile::factory()->create();
        $event = AccessEvent::factory()->create(['access_point_id' => $turnstile->id]);

        $this->assertTrue($turnstile->accessEvents->contains($event));
    }

    public function test_show_page_does_not_error(): void
    {
        $turnstile = Turnstile::factory()->create();
        AccessEvent::factory()->create(['access_point_id' => $turnstile->id]);

        $response = $this->actingAs(User::factory()->create())->get(route('access-points.show', $turnstile));

        $response->assertOk();
    }
}
