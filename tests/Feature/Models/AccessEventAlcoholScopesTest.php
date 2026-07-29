<?php

namespace Tests\Feature\Models;

use App\Models\AccessEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessEventAlcoholScopesTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_alcohol_test_scope_finds_only_events_with_the_key(): void
    {
        $withTest = AccessEvent::factory()->create(['raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']]]);
        AccessEvent::factory()->create(['raw_data' => ['name' => 'plain card swipe']]);

        $ids = AccessEvent::hasAlcoholTest()->pluck('id');

        $this->assertSame([$withTest->id], $ids->all());
    }

    public function test_alcohol_passed_scope_matches_normal_result_only(): void
    {
        $passed = AccessEvent::factory()->create(['raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']]]);
        AccessEvent::factory()->create(['raw_data' => ['alcoholDetectionInfo' => ['result' => 'abnormal']]]);
        AccessEvent::factory()->create(['raw_data' => ['name' => 'plain card swipe']]);

        // AccessEvent also declares an instance method named alcoholPassed() (returns bool),
        // so the scope must be reached via a query builder instance (::query() or a relation),
        // not called statically on the class — that resolves to the instance method instead.
        $ids = AccessEvent::query()->alcoholPassed()->pluck('id');

        $this->assertSame([$passed->id], $ids->all());
    }

    public function test_alcohol_failed_scope_matches_non_normal_result_only(): void
    {
        AccessEvent::factory()->create(['raw_data' => ['alcoholDetectionInfo' => ['result' => 'normal']]]);
        $failed = AccessEvent::factory()->create(['raw_data' => ['alcoholDetectionInfo' => ['result' => 'abnormal']]]);
        AccessEvent::factory()->create(['raw_data' => ['name' => 'plain card swipe']]);

        $ids = AccessEvent::alcoholFailed()->pluck('id');

        $this->assertSame([$failed->id], $ids->all());
    }
}
