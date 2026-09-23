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

    public function test_index_hides_inactive_employees_by_default(): void
    {
        $active = Employee::factory()->create(['is_active' => true, 'last_name' => 'Активнов']);
        $inactive = Employee::factory()->create(['is_active' => false, 'last_name' => 'Уволенов']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('employees.index'));

        $response->assertOk()
            ->assertSee('Активнов')
            ->assertDontSee('Уволенов');
    }

    public function test_index_shows_inactive_employees_when_toggled_on(): void
    {
        Employee::factory()->create(['is_active' => false, 'last_name' => 'Уволенов']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('employees.index', ['show_inactive' => 1]));

        $response->assertOk()->assertSee('Уволенов');
    }

    public function test_index_shows_position_and_department(): void
    {
        Employee::factory()->create([
            'last_name' => 'Мамедова',
            'position' => 'Smotritel',
            'department' => 'Nezaret Departamenti',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('employees.index'));

        $response->assertOk()
            ->assertSee('Smotritel')
            ->assertSee('Nezaret Departamenti')
            ->assertDontSee('Emp Code');
    }

    public function test_show_renders_for_an_employee_without_events(): void
    {
        $employee = Employee::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('employees.show', $employee))
            ->assertOk();
    }

    public function test_show_displays_position_and_department(): void
    {
        $employee = Employee::factory()->create([
            'position' => 'Главный энергетик',
            'department' => 'Energetika şöbəsi',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Главный энергетик')
            ->assertSee('Energetika şöbəsi');
    }

    public function test_show_renders_for_an_employee_with_access_events(): void
    {
        $employee = Employee::factory()->create();
        AccessEvent::factory()->count(3)->create(['employee_id' => $employee->id]);

        $this->actingAs(User::factory()->create())
            ->get(route('employees.show', $employee))
            ->assertOk()
            ->assertSee('Последние события');
    }
}
