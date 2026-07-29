<?php

namespace Tests\Unit\Models;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_returns_default_when_missing(): void
    {
        $this->assertSame('fallback', Setting::get('missing_key', 'fallback'));
    }

    public function test_set_then_get_returns_the_stored_value(): void
    {
        Setting::set('foo', 'bar');

        $this->assertSame('bar', Setting::get('foo'));
    }

    public function test_set_overwrites_an_existing_value(): void
    {
        Setting::set('foo', 'bar');
        Setting::set('foo', 'baz');

        $this->assertSame('baz', Setting::get('foo'));
        $this->assertSame(1, Setting::where('key', 'foo')->count());
    }

    public function test_alcohol_skip_grace_minutes_falls_back_to_config_default(): void
    {
        config(['alcohol.skip_grace_minutes_default' => 180]);

        $this->assertSame(180, Setting::alcoholSkipGraceMinutes());
    }

    public function test_alcohol_skip_grace_minutes_uses_stored_override(): void
    {
        Setting::set('alcohol_skip_grace_minutes', '45');

        $this->assertSame(45, Setting::alcoholSkipGraceMinutes());
    }
}
