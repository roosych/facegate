<?php

namespace Tests\Unit\Services;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionServiceEventListeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'hikvision.webhook_base_url' => 'https://example.ngrok-free.dev',
            'hikvision.webhook_token' => 'test-token',
        ]);
    }

    public function test_sends_xml_body_built_from_config(): void
    {
        Http::fake(['*' => Http::response('<ResponseStatus><statusCode>1</statusCode></ResponseStatus>', 200)]);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $result = $service->configureEventListening();

        $this->assertTrue($result);

        Http::assertSent(function ($request) use ($terminal) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/ISAPI/Event/notification/httpHosts/1')
                && $request->header('Content-Type')[0] === 'application/xml'
                && str_contains($request->body(), '<addressingFormatType>hostname</addressingFormatType>')
                && str_contains($request->body(), '<hostName>example.ngrok-free.dev</hostName>')
                && str_contains($request->body(), '<portNo>443</portNo>')
                && str_contains($request->body(), '<protocolType>HTTPS</protocolType>')
                && str_contains($request->body(), "<url>/api/hikvision/{$terminal->id}/events/test-token</url>");
        });
    }

    public function test_uses_ipaddress_addressing_when_base_url_host_is_an_ip(): void
    {
        Http::fake(['*' => Http::response('<ResponseStatus><statusCode>1</statusCode></ResponseStatus>', 200)]);
        config(['hikvision.webhook_base_url' => 'http://10.0.0.5:8080']);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        (new HikvisionService($terminal))->configureEventListening();

        Http::assertSent(fn ($request) => str_contains($request->body(), '<addressingFormatType>ipaddress</addressingFormatType>')
            && str_contains($request->body(), '<ipAddress>10.0.0.5</ipAddress>')
            && str_contains($request->body(), '<portNo>8080</portNo>')
            && str_contains($request->body(), '<protocolType>HTTP</protocolType>'));
    }

    public function test_returns_false_on_failure(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 5, 'statusString' => 'Invalid Format'], 400)]);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->assertFalse($service->configureEventListening());
    }

    public function test_returns_false_when_webhook_config_is_missing(): void
    {
        config(['hikvision.webhook_base_url' => null]);

        $terminal = HikvisionTerminal::factory()->create(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->assertFalse($service->configureEventListening());
    }
}
