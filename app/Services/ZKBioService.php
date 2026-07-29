<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZKBioService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('zkbio.url'), '/');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(120)
            ->connectTimeout(10);
    }

    public function getJwtToken(): string
    {
        $response = $this->http()
            ->post('/jwt-api-token-auth/', [
                'username' => config('zkbio.username'),
                'password' => config('zkbio.password'),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('ZKBio JWT auth failed: '.$response->body());
        }

        return $response->json('token');
    }

    /**
     * @return array{cookieJar: string, csrfToken: string}
     */
    public function getBrowserSession(): array
    {
        $cookieFile = tempnam(sys_get_temp_dir(), 'zkbio_cookies_');

        $loginUrl = $this->baseUrl.'/accounts/login/';

        // First GET to get CSRF token
        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        preg_match('/csrfmiddlewaretoken["\s]+value=["\']([\w]+)["\']/', (string) $html, $matches);
        $csrfToken = $matches[1] ?? '';

        if (empty($csrfToken)) {
            preg_match('/name="csrfmiddlewaretoken"\s+value="([^"]+)"/', (string) $html, $matches);
            $csrfToken = $matches[1] ?? '';
        }

        // POST login
        $postData = http_build_query([
            'username' => config('zkbio.username'),
            'password' => config('zkbio.password'),
            'csrfmiddlewaretoken' => $csrfToken,
        ]);

        $ch = curl_init($loginUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIEJAR => $cookieFile,
            CURLOPT_COOKIEFILE => $cookieFile,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Referer: '.$loginUrl,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        curl_exec($ch);
        curl_close($ch);

        return [
            'cookieJar' => $cookieFile,
            'csrfToken' => $csrfToken,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTerminals(): array
    {
        $token = $this->getJwtToken();

        $response = $this->http()
            ->withToken($token, 'JWT')
            ->get('/iclock/api/terminals/');

        if (! $response->successful()) {
            throw new RuntimeException('Failed to get terminals: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEmployees(): array
    {
        $token = $this->getJwtToken();

        $response = $this->http()
            ->withToken($token, 'JWT')
            ->get('/personnel/api/employees/');

        if (! $response->successful()) {
            throw new RuntimeException('Failed to get employees: '.$response->body());
        }

        return $response->json('data') ?? [];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createEmployee(array $data, ?string $jwtToken = null): ?int
    {
        $token = $jwtToken ?? $this->getJwtToken();

        $response = $this->http()
            ->withToken($token, 'JWT')
            ->post('/personnel/api/employees/', $data);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to create employee: '.$response->body());
        }

        return $response->json('id');
    }

    /**
     * @param array<string, mixed> $empData
     * @param array{cookieJar: string, csrfToken: string} $session
     */
    public function uploadBioPhoto(int $zkbioId, array $empData, string $photoPath, array $session): bool
    {
        $url = $this->baseUrl.'/personnel/employee/edit/';

        $fields = [
            'obj_id' => (string) $zkbioId,
            'emp_code' => (string) $empData['emp_code'],
            'first_name' => (string) $empData['first_name'],
            'last_name' => (string) ($empData['last_name'] ?? ''),
            'department' => (string) config('zkbio.department_id'),
            'area' => (string) config('zkbio.area_id'),
            'hire_date' => date('Y-m-d'),
            'effective_start' => date('Y-m-d'),
            'effective_end' => '2099-12-31',
            'payment_mode' => '1',
            'payment_type' => '1',
            'verify_mode' => '-1',
            'dev_privilege' => '0',
            'card_no' => (string) ($empData['card_no'] ?? ''),
            'app_status' => '1',
            'app_role' => '1',
            'enable_attendance' => 'on',
            'enable_schedule' => 'on',
            'enable_holiday' => 'on',
            'enable_overtime' => 'on',
            'csrfmiddlewaretoken' => $session['csrfToken'],
        ];

        $curlFields = [];
        foreach ($fields as $key => $value) {
            $curlFields[$key] = $value;
        }

        if (file_exists($photoPath)) {
            $curlFields['bio_photo'] = new \CURLFile($photoPath, 'image/jpeg', basename($photoPath));
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $curlFields,
            CURLOPT_COOKIEFILE => $session['cookieJar'],
            CURLOPT_COOKIEJAR => $session['cookieJar'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Referer: '.$url,
                'X-CSRFToken: '.$session['csrfToken'],
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * @param array{cookieJar: string, csrfToken: string} $session
     */
    public function approveBioPhoto(int $empCode, array $session): bool
    {
        $listUrl = $this->baseUrl.'/iclock/biophoto/table/?page=1&limit=100';

        $ch = curl_init($listUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEFILE => $session['cookieJar'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string) $body, true);
        $bioId = null;

        foreach ($data['data'] ?? [] as $item) {
            if ((int) ($item['get_emp_code'] ?? $item['emp_code'] ?? 0) === $empCode) {
                $bioId = $item['id'];
                break;
            }
        }

        if ($bioId === null) {
            return false;
        }

        $approveUrl = $this->baseUrl.'/iclock/biophoto/action/';
        $postData = http_build_query([
            'id' => $bioId,
            'state' => '1',
            'overwrite' => '1',
            'action_name' => '42696f50686f746f417070726f7665',
            'csrfmiddlewaretoken' => $session['csrfToken'],
        ]);

        $ch = curl_init($approveUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postData,
            CURLOPT_COOKIEFILE => $session['cookieJar'],
            CURLOPT_COOKIEJAR => $session['cookieJar'],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'X-CSRFToken: '.$session['csrfToken'],
                'Referer: '.$this->baseUrl.'/iclock/biophoto/',
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    public function deleteEmployee(int $zkbioId, string $jwtToken): void
    {
        $response = $this->http()
            ->withToken($jwtToken, 'JWT')
            ->delete("/personnel/api/employees/{$zkbioId}/");

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException('Failed to delete employee: '.$response->body());
        }
    }

    /**
     * Find a ZKBio employee by card number. Returns the first match or null.
     *
     * @return array<string, mixed>|null
     */
    public function findByCardNo(string $cardNo, string $jwtToken): ?array
    {
        $response = $this->http()
            ->withToken($jwtToken, 'JWT')
            ->get('/personnel/api/employees/', ['card_no' => $cardNo]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json('data.0');
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateEmployee(int $zkbioId, array $data, string $jwtToken): void
    {
        $response = $this->http()
            ->withToken($jwtToken, 'JWT')
            ->put("/personnel/api/employees/{$zkbioId}/", $data);

        if (! $response->successful()) {
            throw new RuntimeException('Failed to update employee: '.$response->body());
        }
    }

    public function syncEmployeeToDevice(int $zkbioId, string $jwtToken): bool
    {
        $response = $this->http()
            ->withToken($jwtToken)
            ->post('/personnel/api/employees/resync_to_device/', [
                'employees' => [$zkbioId],
            ]);

        return $response->successful();
    }
}
