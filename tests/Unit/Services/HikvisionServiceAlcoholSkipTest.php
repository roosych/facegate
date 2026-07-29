<?php

namespace Tests\Unit\Services;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HikvisionServiceAlcoholSkipTest extends TestCase
{
    public function test_setting_skip_sends_skip_alcohol_value(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $result = $service->setAlcoholSkip('123', true);

        $this->assertTrue($result);

        Http::assertSent(function ($request) {
            return $request->method() === 'PUT'
                && str_contains($request->url(), '/ISAPI/AccessControl/UserInfo/SetUp?format=json')
                && $request['UserInfo']['employeeNo'] === '123'
                && $request['UserInfo']['userType'] === 'normal'
                && isset($request['UserInfo']['Valid'])
                && $request['UserInfo']['PersonInfoExtends'] === [['value' => 'skip_alcohol']];
        });
    }

    public function test_clearing_skip_sends_empty_extends(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $result = $service->setAlcoholSkip('123', false);

        $this->assertTrue($result);

        Http::assertSent(fn ($request) => $request['UserInfo']['PersonInfoExtends'] === [['value' => '']]);
    }

    public function test_returns_false_on_failure(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 0], 500)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
        $service = new HikvisionService($terminal);

        $this->assertFalse($service->setAlcoholSkip('123', true));
    }
}
