<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Registration is closed on purpose: this application holds the employee directory of a
 * company's access control system, and self-service signup would hand it to anyone who can
 * reach the login page. Accounts are created by hand — see "Пользователи" in the README.
 *
 * These tests guard the absence. Scaffolding tends to grow registration back when someone
 * re-runs a starter kit generator, and the failure is quiet: a new route appears and nobody
 * notices until an unexpected account shows up.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registration_screen_is_gone(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_nobody_can_register_themselves(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertNotFound();
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_no_route_is_named_register(): void
    {
        $this->assertFalse(
            app('router')->has('register'),
            'A route named "register" exists again — registration was reintroduced.'
        );
    }
}
