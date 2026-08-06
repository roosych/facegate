<?php

namespace Tests\Unit\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HikvisionServiceFaceUploadTest extends TestCase
{
    private string $photoPath = 'testing/face-upload-test.jpg';

    protected function setUp(): void
    {
        parent::setUp();

        $absolute = storage_path('app/'.$this->photoPath);

        if (! is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }

        $image = imagecreatetruecolor(120, 160);
        imagejpeg($image, $absolute, 90);
        imagedestroy($image);
    }

    protected function tearDown(): void
    {
        $absolute = storage_path('app/'.$this->photoPath);

        if (file_exists($absolute)) {
            unlink($absolute);
        }

        parent::tearDown();
    }

    private function employee(): Employee
    {
        return Employee::factory()->make([
            'emp_code' => 42,
            'photo_path' => $this->photoPath,
        ]);
    }

    public function test_uploads_a_new_face_through_fd_set_up(): void
    {
        Http::fake(['*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200)]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        (new HikvisionService($terminal))->uploadFace($this->employee());

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDSetUp'));

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDModify'));
    }

    public function test_falls_back_to_fd_modify_when_the_face_already_exists(): void
    {
        Http::fake([
            '*FDSetUp*' => Http::response([
                'statusCode' => 4,
                'statusString' => 'Invalid Operation',
                'subStatusCode' => 'alreadyExist',
            ], 400),
            '*FDModify*' => Http::response(['statusCode' => 1, 'statusString' => 'OK'], 200),
        ]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        (new HikvisionService($terminal))->uploadFace($this->employee());

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDModify')
            && str_contains($request->body(), 'FaceDataModify'));
    }

    public function test_falls_back_to_fd_modify_on_the_alternate_firmware_sub_status(): void
    {
        Http::fake([
            '*FDSetUp*' => Http::response([
                'statusCode' => 4,
                'subStatusCode' => 'deviceUserAlreadyExistFace',
            ], 400),
            '*FDModify*' => Http::response(['statusCode' => 1], 200),
        ]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        (new HikvisionService($terminal))->uploadFace($this->employee());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDModify'));
    }

    public function test_throws_when_the_fd_modify_fallback_also_fails(): void
    {
        Http::fake([
            '*FDSetUp*' => Http::response(['statusCode' => 4, 'subStatusCode' => 'alreadyExist'], 400),
            '*FDModify*' => Http::response(['statusCode' => 4, 'subStatusCode' => 'notSupport'], 400),
        ]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to update existing face for 42');

        (new HikvisionService($terminal))->uploadFace($this->employee());
    }

    public function test_retries_with_a_re_encoded_picture_when_the_device_deduplicates_on_content(): void
    {
        Http::fakeSequence()
            ->push(['statusCode' => 4, 'subStatusCode' => 'alreadyExistThisFace'], 400)
            ->push(['statusCode' => 1, 'statusString' => 'OK'], 200);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        (new HikvisionService($terminal))->uploadFace($this->employee());

        $bodies = [];
        Http::assertSentCount(2);
        Http::assertSent(function ($request) use (&$bodies) {
            $bodies[] = $request->body();

            return str_contains($request->url(), '/ISAPI/Intelligent/FDLib/FDSetUp');
        });

        // The retry must carry different bytes, otherwise the device rejects it again.
        $this->assertNotSame($bodies[0], $bodies[1]);
    }

    public function test_throws_when_even_the_re_encoded_picture_is_rejected(): void
    {
        Http::fake([
            '*FDSetUp*' => Http::response([
                'statusCode' => 4,
                'subStatusCode' => 'alreadyExistThisFace',
            ], 400),
        ]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rejected a re-encoded copy too');

        (new HikvisionService($terminal))->uploadFace($this->employee());
    }

    public function test_throws_a_descriptive_error_when_the_photo_fails_face_detection(): void
    {
        Http::fake([
            '*FDSetUp*' => Http::response([
                'statusCode' => 4,
                'subStatusCode' => 'SubpicAnalysisModelingError',
            ], 400),
        ]);

        $terminal = HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not meet face detection requirements');

        (new HikvisionService($terminal))->uploadFace($this->employee());
    }
}
