<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionEmployeeResyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_refuses_to_resync_an_inactive_employee(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->create(['emp_code' => 42, 'is_active' => false]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('hikvision.employees.resync', $terminal), ['emp_code' => '42']);

        $response->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_resyncs_an_active_employee(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1], 200)]);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        Employee::factory()->create(['emp_code' => 42, 'is_active' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('hikvision.employees.resync', $terminal), ['emp_code' => '42']);

        $response->assertOk();
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp?format=json'));
    }
}
