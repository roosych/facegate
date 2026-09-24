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

    public function test_mark_alcohol_cleaned_stamps_the_terminal_and_resets_the_notification_flag(): void
    {
        $terminal = HikvisionTerminal::factory()->create(['alcohol_cleaning_notified_at' => now()->subHour()]);

        $response = $this->actingAs(User::factory()->create())
            ->patchJson(route('hikvision.alcohol.cleaned', $terminal));

        $response->assertOk()->assertJson(['success' => true]);
        $terminal->refresh();
        $this->assertNotNull($terminal->alcohol_last_cleaned_at);
        $this->assertNull($terminal->alcohol_cleaning_notified_at);
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
