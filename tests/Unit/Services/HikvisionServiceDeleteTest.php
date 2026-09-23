<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * ISAPI answers a delete with HTTP 200 even when the device refuses it — the real outcome is
 * the JSON envelope's statusCode. Discovered 2026-09-22: 933 logged-successful removals left
 * 400+ persons still on a terminal because only the HTTP status was checked.
 */
class HikvisionServiceDeleteTest extends TestCase
{
    public function test_delete_employee_succeeds_on_status_code_one(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1, 'subStatusCode' => 'ok'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->make(['emp_code' => 123]);
        $service = new HikvisionService($terminal);

        $service->deleteEmployee($employee);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_delete_employee_throws_when_device_rejects_with_http_200(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 6, 'subStatusCode' => 'invalidOperation', 'errorMsg' => 'invalid'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->make(['emp_code' => 123]);
        $service = new HikvisionService($terminal);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('statusCode=6');

        $service->deleteEmployee($employee);
    }

    public function test_delete_employee_treats_404_as_already_gone(): void
    {
        Http::fake(['*' => Http::response([], 404)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->make(['emp_code' => 123]);
        $service = new HikvisionService($terminal);

        $service->deleteEmployee($employee);

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_delete_by_emp_code_throws_when_device_rejects_with_http_200(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 6, 'subStatusCode' => 'invalidOperation'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->expectException(RuntimeException::class);

        $service->deleteByEmpCode('123');
    }

    public function test_delete_cards_throws_when_device_rejects_with_http_200(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 6, 'subStatusCode' => 'invalidOperation'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->expectException(RuntimeException::class);

        $service->deleteCards('123');
    }

    public function test_delete_face_throws_when_device_rejects_with_http_200(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 6, 'subStatusCode' => 'invalidOperation'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->expectException(RuntimeException::class);

        $service->deleteFace('123');
    }

    public function test_delete_employee_succeeds_when_response_has_no_status_code_field(): void
    {
        // Some endpoints/firmware answer a plain 200 with no JSON body at all — absence of the
        // field must not be treated as rejection, only an explicit non-1 value should be.
        Http::fake(['*' => Http::response('', 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $employee = Employee::factory()->make(['emp_code' => 123]);
        $service = new HikvisionService($terminal);

        $service->deleteEmployee($employee);

        $this->addToAssertionCount(1); // no exception thrown
    }
}
