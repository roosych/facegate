<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HikvisionSyncGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_methods_throw_when_sync_is_disabled(): void
    {
        config(['hikvision.sync_enabled' => false]);
        Http::fake();

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42]);

        $this->expectException(RuntimeException::class);

        (new HikvisionService($terminal))->addEmployee($employee);
    }

    public function test_disabled_sync_sends_no_request(): void
    {
        config(['hikvision.sync_enabled' => false]);
        Http::fake();

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42]);

        try {
            (new HikvisionService($terminal))->addCard((string) $employee->emp_code, '0000000042');
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_reads_are_never_gated(): void
    {
        config(['hikvision.sync_enabled' => false]);
        Http::fake(['*' => Http::response(['DeviceInfo' => []], 200)]);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);

        $this->assertTrue((new HikvisionService($terminal))->isOnline());
    }

    public function test_sync_all_command_is_a_noop_when_disabled(): void
    {
        config(['hikvision.sync_enabled' => false]);
        Http::fake();
        HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);

        $this->artisan('hikvision:sync-all')
            ->expectsOutputToContain('disabled in this environment')
            ->assertSuccessful();

        Http::assertNothingSent();
    }
}
