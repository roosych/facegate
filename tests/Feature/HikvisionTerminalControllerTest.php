<?php

namespace Tests\Feature;

use App\Models\HikvisionTerminal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HikvisionTerminalControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_direction_accepts_in_and_out(): void
    {
        $terminal = HikvisionTerminal::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('hikvision.update', $terminal), $this->validPayload(['direction' => 'out']));

        $response->assertRedirect(route('hikvision.index'));
        $this->assertSame('out', $terminal->fresh()->direction);
    }

    public function test_direction_rejects_an_unknown_value(): void
    {
        $terminal = HikvisionTerminal::factory()->create();

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('hikvision.update', $terminal), $this->validPayload(['direction' => 'sideways']));

        $response->assertSessionHasErrors('direction');
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Entrance Terminal',
            'ip' => '192.168.1.50',
            'port' => 80,
            'username' => 'admin',
            'protocol' => 'http',
            'is_active' => true,
        ], $overrides);
    }
}
