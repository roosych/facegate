<?php

namespace Tests\Feature;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_renders_for_an_employee_without_events(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('employees.show', $employee))
            ->assertOk();
    }

    public function test_show_renders_for_an_employee_with_access_events(): void
    {
        $employee = Employee::factory()->create();
        AccessEvent::factory()->count(3)->create(['employee_id' => $employee->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Recent Events');
    }
}
