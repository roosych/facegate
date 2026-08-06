<?php

namespace Tests\Feature\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Turnstile;
use App\Services\HikvisionSyncService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class HikvisionSyncRemovalGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $rusGuardDb = Mockery::mock(RusGuardDatabaseService::class);
        $rusGuardDb->shouldReceive('getEmployeesRequiringAlcoholTest')->andReturn([]);
        $this->app->instance(RusGuardDatabaseService::class, $rusGuardDb);
    }

    /**
     * The terminal already holds two people who are not in the local roster — exactly what a
     * removal pass would delete.
     */
    private function fakeTerminalHoldingStrangers(): void
    {
        $persons = [
            ['employeeNo' => '901', 'name' => 'Stranger One', 'numOfCard' => 1, 'numOfFace' => 1],
            ['employeeNo' => '902', 'name' => 'Stranger Two', 'numOfCard' => 1, 'numOfFace' => 1],
        ];

        Http::fake([
            '*deviceInfo*' => Http::response(['DeviceInfo' => ['deviceName' => 'test']], 200),
            '*UserInfo/Search*' => Http::response([
                'UserInfoSearch' => ['totalMatches' => count($persons), 'UserInfo' => $persons],
            ], 200),
            '*CardInfo/Search*' => Http::response(['CardInfoSearch' => ['totalMatches' => 0, 'CardInfo' => []]], 200),
            '*FDLib/FDSearch*' => Http::response(['totalMatches' => 0, 'MatchList' => []], 200),
            '*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);
    }

    private function assertNobodyWasDeleted(): void
    {
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Delete'));
    }

    public function test_does_not_wipe_the_terminal_when_its_access_point_was_deactivated(): void
    {
        // What a terminal bound to a point whose driverId changed in RusGuard looks like: the
        // old turnstile is deactivated and its pivot is no longer maintained.
        $turnstile = Turnstile::factory()->create(['is_active' => false]);
        $employee = Employee::factory()->create(['emp_code' => 10, 'photo_path' => null]);
        $employee->turnstiles()->attach($turnstile->id);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1', 'access_point_id' => $turnstile->id]);

        $this->fakeTerminalHoldingStrangers();

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertNobodyWasDeleted();
        $this->assertSame(0, $results['removed']);
        $this->assertStringContainsString('deactivated', $results['removalSkipped']);
    }

    public function test_does_not_wipe_the_terminal_when_the_access_point_has_no_employees(): void
    {
        $turnstile = Turnstile::factory()->create(['is_active' => true]);
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1', 'access_point_id' => $turnstile->id]);

        $this->fakeTerminalHoldingStrangers();

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertNobodyWasDeleted();
        $this->assertSame(0, $results['removed']);
        $this->assertStringContainsString('no employees linked', $results['removalSkipped']);
    }

    public function test_does_not_wipe_the_terminal_when_it_is_not_linked_to_any_access_point(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1', 'access_point_id' => null]);

        $this->fakeTerminalHoldingStrangers();

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        $this->assertNobodyWasDeleted();
        $this->assertSame(0, $results['removed']);
        $this->assertStringContainsString('not linked', $results['removalSkipped']);
    }

    public function test_still_removes_strangers_when_the_roster_is_trustworthy(): void
    {
        $turnstile = Turnstile::factory()->create(['is_active' => true]);
        $employee = Employee::factory()->create(['emp_code' => 10, 'photo_path' => null]);
        $employee->turnstiles()->attach($turnstile->id);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1', 'access_point_id' => $turnstile->id]);

        $this->fakeTerminalHoldingStrangers();

        $results = app(HikvisionSyncService::class)->syncEmployeesForTerminal($terminal);

        // The guard must not become a blanket "never delete".
        $this->assertSame(2, $results['removed']);
        $this->assertNull($results['removalSkipped']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/Delete'));
    }
}
