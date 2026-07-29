<?php

namespace Tests\Unit\Services\RusGuard;

use App\Services\RusGuard\EmployeeService;
use ReflectionProperty;
use SoapClient;
use SoapFault;
use Tests\TestCase;

/**
 * Controllable SoapClient stub — intercepts __call and returns configured responses.
 */
class SoapClientStub extends SoapClient
{
    /** @var array<string, mixed> */
    private array $responses = [];

    /** @var array<string, \Throwable> */
    private array $exceptions = [];

    /** @var list<array{method: string, args: mixed}> */
    private array $calls = [];

    public function __construct() {}

    public function willReturn(string $method, mixed $response): void
    {
        $this->responses[$method] = $response;
    }

    public function willThrow(string $method, \Throwable $exception): void
    {
        $this->exceptions[$method] = $exception;
    }

    /** @return list<array{method: string, args: mixed}> */
    public function callsFor(string $method): array
    {
        return array_values(array_filter($this->calls, fn ($c) => $c['method'] === $method));
    }

    public function __call(string $name, array $args): mixed
    {
        $this->calls[] = ['method' => $name, 'args' => $args[0] ?? []];

        if (isset($this->exceptions[$name])) {
            throw $this->exceptions[$name];
        }

        return $this->responses[$name] ?? new \stdClass();
    }

    // Required by logError() when a SoapFault is thrown
    public function __getLastRequest(): ?string { return ''; }

    public function __getLastRequestHeaders(): ?string { return ''; }

    public function __getLastResponse(): ?string { return ''; }

    public function __getLastResponseHeaders(): ?string { return ''; }
}

class EmployeeServicePhotoTest extends TestCase
{
    private EmployeeService $service;

    private SoapClientStub $stub;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->getMockBuilder(EmployeeService::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->stub = new SoapClientStub();

        $prop = new ReflectionProperty(EmployeeService::class, 'client');
        $prop->setValue($this->service, $this->stub);
    }

    public function test_returns_decoded_binary_photo(): void
    {
        $uuid = '1d81975b-405a-48b2-adb0-2cd7fd549a0c';
        $binary = 'fake-jpeg-data';

        $result = new \stdClass();
        $result->GetAcsEmployeePhotoResult = base64_encode($binary);
        $this->stub->willReturn('GetAcsEmployeePhoto', $result);

        $photo = $this->service->getEmployeePhoto($uuid);

        $this->assertSame($binary, $photo);

        $calls = $this->stub->callsFor('GetAcsEmployeePhoto');
        $this->assertCount(1, $calls);
        $this->assertSame($uuid, $calls[0]['args']['employeeId']);
        $this->assertSame(1, $calls[0]['args']['photoNumber']);
    }

    public function test_returns_null_when_result_is_null(): void
    {
        $result = new \stdClass();
        $result->GetAcsEmployeePhotoResult = null;
        $this->stub->willReturn('GetAcsEmployeePhoto', $result);

        $this->assertNull($this->service->getEmployeePhoto('some-uuid'));
    }

    public function test_returns_null_when_result_key_is_missing(): void
    {
        $this->stub->willReturn('GetAcsEmployeePhoto', new \stdClass());

        $this->assertNull($this->service->getEmployeePhoto('some-uuid'));
    }

    public function test_passes_custom_photo_number(): void
    {
        $uuid = '1d81975b-405a-48b2-adb0-2cd7fd549a0c';
        $binary = 'photo-2-data';

        $result = new \stdClass();
        $result->GetAcsEmployeePhotoResult = base64_encode($binary);
        $this->stub->willReturn('GetAcsEmployeePhoto', $result);

        $photo = $this->service->getEmployeePhoto($uuid, 2);

        $this->assertSame($binary, $photo);

        $calls = $this->stub->callsFor('GetAcsEmployeePhoto');
        $this->assertSame(2, $calls[0]['args']['photoNumber']);
    }

    public function test_rethrows_soap_fault(): void
    {
        $this->stub->willThrow('GetAcsEmployeePhoto', new SoapFault('Server', 'Connection error'));

        $this->expectException(SoapFault::class);
        $this->expectExceptionMessage('Connection error');

        $this->service->getEmployeePhoto('some-uuid');
    }
}
