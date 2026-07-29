<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use Illuminate\Support\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Hikvision ISAPI client.
 * Communicates directly with the terminal's HTTP Digest Auth REST API.
 */
class HikvisionService
{
    private string $baseUrl;

    public function __construct(private readonly HikvisionTerminal $terminal)
    {
        $this->baseUrl = rtrim($terminal->protocol.'://'.$terminal->ip.':'.$terminal->port, '/');
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withDigestAuth($this->terminal->username, $this->terminal->password)
            ->timeout(30)
            ->connectTimeout(10)
            ->withHeaders(['Accept' => 'application/json'])
            ->withoutVerifying();
    }

    /**
     * Check whether the terminal is reachable.
     */
    public function isOnline(): bool
    {
        try {
            $response = $this->http()->get('/ISAPI/System/deviceInfo?format=json');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Count persons stored on the terminal.
     */
    public function personCount(): int
    {
        try {
            $response = $this->http()->get('/ISAPI/AccessControl/UserInfo/Count?format=json');

            return (int) ($response->json('UserInfoCount.userNumber') ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Fetch one page of persons from the terminal.
     *
     * @return array{persons: array<int, array<string, mixed>>, total: int}
     */
    public function searchPersons(int $offset = 0, int $maxResults = 50, string $searchId = '1'): array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/ISAPI/AccessControl/UserInfo/Search?format=json', [
                    'UserInfoSearchCond' => [
                        'searchID' => $searchId,
                        'searchResultPosition' => $offset,
                        'maxResults' => $maxResults,
                    ],
                ]);

            $data = $response->json() ?? [];
            $search = $data['UserInfoSearch'] ?? [];

            return [
                'persons' => $search['UserInfo'] ?? [],
                'total' => (int) ($search['totalMatches'] ?? 0),
            ];
        } catch (Throwable $e) {
            Log::error('Hikvision searchPersons failed', [
                'terminal' => $this->terminal->name,
                'error' => $e->getMessage(),
            ]);

            return ['persons' => [], 'total' => 0];
        }
    }

    /**
     * Fetch ALL persons from the terminal by paginating automatically.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allPersons(): array
    {
        $all = [];
        $offset = 0;
        $pageSize = 50;
        $searchId = (string) mt_rand(100000, 999999);

        do {
            $result = $this->searchPersons($offset, $pageSize, $searchId);
            $page = $result['persons'];
            $all = array_merge($all, $page);
            $offset += count($page);
        } while (count($page) > 0 && ($offset < $result['total'] || count($page) >= $pageSize));

        return $all;
    }

    /**
     * Add or update a person on the terminal.
     */
    public function addEmployee(Employee $employee): void
    {
        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/UserInfo/SetUp?format=json', [
                'UserInfo' => [
                    'employeeNo' => (string) $employee->emp_code,
                    'name' => mb_substr($employee->full_name, 0, 32),
                    'userType' => 'normal',
                    'Valid' => [
                        'enable' => true,
                        'beginTime' => now()->startOfDay()->format('Y-m-d\TH:i:s'),
                        'endTime' => now()->addYears(10)->format('Y-m-d\TH:i:s'),
                    ],
                    'doorRight' => '1',
                    'RightPlan' => [
                        ['doorNo' => 1, 'planTemplateNo' => '1'],
                    ],
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to add employee '.$employee->emp_code.' to '.$this->terminal->name.': '.$response->body()
            );
        }
    }

    /**
     * Remove a person from the terminal.
     */
    public function deleteEmployee(Employee $employee): void
    {
        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/UserInfo/Delete?format=json', [
                'UserInfoDelCond' => [
                    'EmployeeNoList' => [
                        ['employeeNo' => (string) $employee->emp_code],
                    ],
                ],
            ]);

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(
                'Failed to delete employee '.$employee->emp_code.': '.$response->body()
            );
        }
    }

    /**
     * Delete a person from terminal by raw employee number string (when no Employee model exists).
     */
    public function deleteByEmpCode(string $empCode): void
    {
        $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/UserInfo/Delete?format=json', [
                'UserInfoDelCond' => [
                    'EmployeeNoList' => [
                        ['employeeNo' => $empCode],
                    ],
                ],
            ]);
    }

    /**
     * Fetch all cards stored on the terminal, keyed by employeeNo.
     *
     * @return array<string, array<int, string>>  employeeNo => list of cardNos
     */
    public function allCards(): array
    {
        $all = [];
        $offset = 0;
        $pageSize = 50;
        $searchId = (string) mt_rand(100000, 999999);
        $page = [];
        $total = 0;

        do {
            try {
                $response = $this->http()
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('/ISAPI/AccessControl/CardInfo/Search?format=json', [
                        'CardInfoSearchCond' => [
                            'searchID' => $searchId,
                            'searchResultPosition' => $offset,
                            'maxResults' => $pageSize,
                        ],
                    ]);

                $data = $response->json() ?? [];
                $search = $data['CardInfoSearch'] ?? [];
                $page = $search['CardInfo'] ?? [];

                // Some terminals return a single object instead of an array when there is only one result
                if (isset($page['employeeNo'])) {
                    $page = [$page];
                }

                $total = (int) ($search['totalMatches'] ?? 0);

                foreach ($page as $card) {
                    $empNo = (string) ($card['employeeNo'] ?? '');
                    $cardNo = (string) ($card['cardNo'] ?? '');
                    if ($empNo !== '' && $cardNo !== '') {
                        $all[$empNo][] = $cardNo;
                    }
                }

                $offset += count($page);
            } catch (Throwable $e) {
                Log::error('Hikvision allCards failed', [
                    'terminal' => $this->terminal->name,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($page) > 0 && ($offset < $total || count($page) >= $pageSize));

        return $all;
    }

    /**
     * Return the set of employeeNos that have a face photo stored on the terminal.
     *
     * @return array<string, true>
     */
    public function empCodesWithFace(): array
    {
        $result = [];
        $offset = 0;
        $pageSize = 50;
        $searchId = (string) mt_rand(100000, 999999);
        $page = [];
        $total = 0;

        do {
            try {
                $response = $this->http()
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('/ISAPI/Intelligent/FDLib/FDSearch?format=json', [
                        'faceLibType' => 'blackFD',
                        'FDID' => '1',
                        'searchID' => $searchId,
                        'searchResultPosition' => $offset,
                        'maxResults' => $pageSize,
                    ]);

                $data = $response->json() ?? [];
                $page = $data['MatchList'] ?? [];
                $total = (int) ($data['totalMatches'] ?? 0);

                foreach ($page as $item) {
                    $fpid = (string) ($item['FPID'] ?? '');
                    if ($fpid !== '') {
                        $result[$fpid] = true;
                    }
                }

                $offset += count($page);
            } catch (Throwable $e) {
                Log::error('Hikvision empCodesWithFace failed', [
                    'terminal' => $this->terminal->name,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        } while (count($page) > 0 && ($offset < $total || count($page) >= $pageSize));

        return $result;
    }

    /**
     * Add a card for an employee on the terminal.
     */
    public function addCard(string $empCode, string $cardNo): void
    {
        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/CardInfo/SetUp?format=json', [
                'CardInfo' => [
                    'employeeNo' => $empCode,
                    'cardNo' => $cardNo,
                    'cardType' => 'normalCard',
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                'Failed to add card '.$cardNo.' for '.$empCode.': '.$response->body()
            );
        }
    }

    /**
     * Delete all cards for an employee from the terminal.
     */
    public function deleteCards(string $empCode): void
    {
        $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/CardInfo/Delete?format=json', [
                'CardInfoDelCond' => [
                    'EmployeeNoList' => [
                        ['employeeNo' => $empCode],
                    ],
                ],
            ]);
    }

    /**
     * Upload a face photo for an employee via multipart/form-data (FaceDataRecord endpoint).
     * If face already exists on the terminal, the upload is skipped (not an error).
     * To force a face update, call refreshFace() which re-adds the person entirely.
     */
    public function uploadFace(Employee $employee): void
    {
        if ($employee->photo_path === null) {
            Log::warning('Hikvision uploadFace skipped: no photo_path in DB', [
                'emp_code' => $employee->emp_code,
                'terminal' => $this->terminal->name,
            ]);

            return;
        }

        $photoPath = storage_path('app/'.$employee->photo_path);

        if (! file_exists($photoPath)) {
            Log::warning('Hikvision uploadFace skipped: file not found on disk', [
                'emp_code' => $employee->emp_code,
                'photo_path' => $employee->photo_path,
                'terminal' => $this->terminal->name,
            ]);

            return;
        }

        $imageData = file_get_contents($photoPath);

        if ($imageData === false) {
            Log::warning('Hikvision uploadFace skipped: could not read file', [
                'emp_code' => $employee->emp_code,
                'photo_path' => $employee->photo_path,
                'terminal' => $this->terminal->name,
            ]);

            return;
        }

        $imageData = $this->normalizeFaceImage($imageData);

        $response = $this->http()
            ->attach(
                'FaceDataRecord',
                json_encode([
                    'faceLibType' => 'blackFD',
                    'FDID' => '1',
                    'FPID' => (string) $employee->emp_code,
                ]),
                'FaceDataRecord',
                ['Content-Type' => 'application/json']
            )
            ->attach('img', $imageData, 'img', ['Content-Type' => 'image/jpeg'])
            ->put('/ISAPI/Intelligent/FDLib/FDSetUp?format=json');

        if ($response->successful()) {
            return;
        }

        // Face already exists on the terminal — not an error, skip silently.
        // To update a changed photo, use refreshFace() which deletes and re-adds the person.
        if (($response->json('subStatusCode') ?? '') === 'deviceUserAlreadyExistFace') {
            return;
        }

        // Terminal rejected the photo because face detection failed (bad photo quality, no face visible, etc.)
        if (($response->json('subStatusCode') ?? '') === 'SubpicAnalysisModelingError') {
            throw new RuntimeException(
                'Face photo rejected by terminal for emp '.$employee->emp_code.' — photo does not meet face detection requirements (no visible face, too small, or low quality)'
            );
        }

        throw new RuntimeException(
            'Failed to upload face for '.$employee->emp_code.': '.$response->body()
        );
    }

    /**
     * Normalize a face photo before uploading: corrects EXIF rotation (phone photos are
     * frequently stored sideways/upside-down with only an EXIF tag marking the intended
     * orientation — GD ignores this on decode, so an un-rotated upload can fail the
     * terminal's face detection even though the source photo looks fine to a human),
     * always re-encodes as JPEG (source may be PNG/WebP/etc.), and compresses to stay
     * under Hikvision's 200 KB face photo limit — reducing quality first, then
     * progressively downscaling dimensions if quality reduction alone isn't enough.
     * Returns the original bytes unchanged if GD is unavailable or the data isn't decodable.
     */
    private function normalizeFaceImage(string $imageData, int $maxBytes = 200_000): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $imageData;
        }

        $image = @imagecreatefromstring($imageData);

        if ($image === false) {
            return $imageData;
        }

        $image = $this->correctExifOrientation($image, $imageData);

        $width = imagesx($image);
        $height = imagesy($image);
        $best = null;

        foreach ([1, 0.75, 0.5, 0.35, 0.25] as $scale) {
            $scaled = $scale < 1
                ? imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)))
                : $image;

            if ($scaled === false) {
                continue;
            }

            foreach ([90, 85, 80, 75, 70] as $quality) {
                ob_start();
                imagejpeg($scaled, null, $quality);
                $encoded = ob_get_clean();
                $best ??= $encoded;

                if (strlen($encoded) <= $maxBytes) {
                    if ($scaled !== $image) {
                        imagedestroy($scaled);
                    }
                    imagedestroy($image);

                    return $encoded;
                }
            }

            if ($scaled !== $image) {
                imagedestroy($scaled);
            }
        }

        imagedestroy($image);

        // Couldn't get under the limit even at the smallest scale/lowest quality —
        // return the smallest encoding achieved (still normalized/rotated) rather than
        // giving up and sending the raw, potentially mis-oriented original.
        return $best ?? $imageData;
    }

    /**
     * Rotate a decoded GD image per its source JPEG's EXIF orientation tag, if present.
     * Returns the image unchanged if ext-exif isn't available or no rotation is needed.
     *
     * @param  \GdImage  $image
     * @return \GdImage
     */
    private function correctExifOrientation($image, string $originalData)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($originalData));
        $orientation = $exif['Orientation'] ?? 1;

        $degrees = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($degrees === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $degrees, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * Fetch alcohol detection params from the terminal.
     * Returns null when the alcohol module is not connected or not supported.
     *
     * @return array<string, mixed>|null
     */
    public function getAlcoholDetectionParams(): ?array
    {
        try {
            $response = $this->http()->get('/ISAPI/AccessControl/AccessControlAlcoholDetection/AlcoholDetectionParams?format=json');

            if (! $response->successful()) {
                return null;
            }

            return $response->json() ?? null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Push alcohol detection params to the terminal.
     *
     * @param array<string, mixed> $params
     */
    public function setAlcoholDetectionParams(array $params): bool
    {
        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put('/ISAPI/AccessControl/AccessControlAlcoholDetection/AlcoholDetectionParams?format=json', $params);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Configure this terminal to push access-control events to us in real time (ISAPI
     * "Listening" mode) instead of relying solely on the periodic poll. Devices support one
     * HTTP(S) listening host by default, hence the fixed hostID.
     *
     * Builds the target from config('hikvision.webhook_base_url') — the address terminals can
     * reach us at (ngrok during development, a real public domain in production) — and
     * config('hikvision.webhook_token'), never a value passed in by the caller, so every
     * terminal is always pointed at whatever this deployment is currently configured with.
     */
    public function configureEventListening(): bool
    {
        $baseUrl = config('hikvision.webhook_base_url');
        $token = config('hikvision.webhook_token');

        if (! $baseUrl || ! $token) {
            return false;
        }

        $parts = parse_url($baseUrl);
        $host = $parts['host'] ?? null;

        if (! $host) {
            return false;
        }

        $protocol = strtoupper($parts['scheme'] ?? 'https');
        $port = $parts['port'] ?? ($protocol === 'HTTPS' ? 443 : 80);
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $addressingFormatType = $isIp ? 'ipaddress' : 'hostname';
        $hostField = $isIp ? "<ipAddress>{$host}</ipAddress>" : "<hostName>{$host}</hostName>";
        $path = "/api/hikvision/{$this->terminal->id}/events/{$token}";

        // Unlike most other ISAPI endpoints in this class, httpHosts rejects a JSON body
        // (?format=json) with "badXmlFormat" — this one genuinely requires XML regardless.
        $xml = <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <HttpHostNotification version="2.0" xmlns="http://www.isapi.org/ver20/XMLSchema">
            <id>1</id>
            <url>{$path}</url>
            <protocolType>{$protocol}</protocolType>
            <parameterFormatType>JSON</parameterFormatType>
            <addressingFormatType>{$addressingFormatType}</addressingFormatType>
            {$hostField}
            <portNo>{$port}</portNo>
            <httpAuthenticationMethod>none</httpAuthenticationMethod>
            </HttpHostNotification>
            XML;

        try {
            $response = $this->http()
                ->withHeaders(['Accept' => 'application/xml', 'Content-Type' => 'application/xml'])
                ->withBody($xml, 'application/xml')
                ->put('/ISAPI/Event/notification/httpHosts/1');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    private const ALCOHOL_WEEK_PLAN_ID = 1;

    /**
     * Maps local lowercase day names to the terminal's numeric dayOfWeek (Monday=1..Sunday=7).
     *
     * @var array<string, int>
     */
    private const ALCOHOL_WEEK_DAYS = [
        'monday'    => 1,
        'tuesday'   => 2,
        'wednesday' => 3,
        'thursday'  => 4,
        'friday'    => 5,
        'saturday'  => 6,
        'sunday'    => 7,
    ];

    /**
     * Fetch the alcohol week plan from the terminal.
     * Returns an array keyed by lowercase day name, each with enabled/periods
     * (periods is a list of beginTime/endTime ranges). An empty array is a valid result
     * (every day currently disabled); null means the fetch itself failed.
     *
     * @return array<string, mixed>|null
     */
    public function getAlcoholWeekPlan(): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/ISAPI/AccessControl/AccessControlAlcoholDetectionPlan/GetAlcoholWeekPlan?format=json', [
                    'weekPlanID' => self::ALCOHOL_WEEK_PLAN_ID,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $dayNames = array_flip(self::ALCOHOL_WEEK_DAYS);
            $plan = [];

            foreach ($response->json('weekPlanCfg') ?? [] as $cfg) {
                $day = $dayNames[$cfg['dayOfWeek'] ?? null] ?? null;
                if ($day === null) {
                    continue;
                }

                $periods = [];
                foreach ($cfg['timeRange'] ?? [] as $range) {
                    if (! ($range['alcoholDetEnabled'] ?? false)) {
                        continue;
                    }
                    $periods[] = [
                        'beginTime' => $range['startTime'] ?? '08:00',
                        'endTime'   => $range['endTime'] ?? '18:00',
                    ];
                }

                if ($periods === []) {
                    continue;
                }

                $plan[$day] = ['enabled' => true, 'periods' => $periods];
            }

            // An empty $plan is a legitimate live state (every day currently disabled on the
            // terminal) and must be distinguished from a failed fetch — collapsing it to null
            // here would make the caller treat "all off" the same as "couldn't reach the
            // terminal" and keep showing stale cached data instead of the real empty schedule.
            return $plan;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Push the alcohol week plan to the terminal.
     * Expects $plan keyed by lowercase day name with enabled/periods (a list of
     * beginTime/endTime ranges). Disabled days, or days with no periods, are omitted
     * from the request entirely — the terminal's own UI only shows a day as off when
     * it's absent from weekPlanCfg, not when sent with alcoholDetEnabled:false.
     *
     * @param array<string, array{enabled: bool, periods: array<int, array{beginTime: string, endTime: string}>}> $plan
     */
    public function setAlcoholWeekPlan(array $plan): bool
    {
        $weekPlanCfg = [];

        foreach (self::ALCOHOL_WEEK_DAYS as $day => $dayNumber) {
            $cfg = $plan[$day] ?? ['enabled' => false, 'periods' => []];
            $periods = $cfg['enabled'] ? $cfg['periods'] : [];

            if ($periods === []) {
                continue;
            }

            $weekPlanCfg[] = [
                'dayOfWeek' => $dayNumber,
                'timeRange' => array_map(fn (array $period) => [
                    'startTime'         => $period['beginTime'],
                    'endTime'           => $period['endTime'],
                    'alcoholDetEnabled' => true,
                    'alcoholDetTimes'   => 1,
                ], $periods),
            ];
        }

        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put('/ISAPI/AccessControl/AccessControlAlcoholDetectionPlan/ModifyAlcoholWeekPlan?format=json', [
                    'weekPlanID'  => self::ALCOHOL_WEEK_PLAN_ID,
                    'enabled'     => true,
                    'weekPlanCfg' => $weekPlanCfg,
                ]);

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Set or clear the per-employee alcohol test skip flag (custom project DZP20260604103).
     * When set, the terminal bypasses the alcohol test for this person after a successful
     * face/card authentication and unlocks the door directly. Clearing it (empty
     * PersonInfoExtends) restores the normal alcohol-test requirement.
     */
    public function setAlcoholSkip(string $empCode, bool $skip): bool
    {
        try {
            // METAK.docx documents POST .../UserInfo/Record for this, but that endpoint is
            // create-only on this firmware (rejects existing employeeNos with
            // "employeeNoAlreadyExist") and separately requires userType/Valid to even reach
            // that check. UserInfo/SetUp (PUT) — the same upsert endpoint addEmployee() already
            // uses — accepts PersonInfoExtends too and behaves as a true partial update: fields
            // omitted here (name, doorRight, RightPlan, cards, face) are left untouched.
            // PersonInfoExtends: [] is silently ignored (200 OK, flag unchanged) — clearing
            // requires an explicit empty-value entry instead. Verified live against the test terminal.
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put('/ISAPI/AccessControl/UserInfo/SetUp?format=json', [
                    'UserInfo' => [
                        'employeeNo' => $empCode,
                        'userType' => 'normal',
                        'Valid' => [
                            'enable' => true,
                            'beginTime' => now()->startOfDay()->format('Y-m-d\TH:i:s'),
                            'endTime' => now()->addYears(10)->format('Y-m-d\TH:i:s'),
                        ],
                        'PersonInfoExtends' => [['value' => $skip ? 'skip_alcohol' : '']],
                    ],
                ]);

            if (! $response->successful()) {
                Log::error('Hikvision setAlcoholSkip failed', [
                    'terminal' => $this->terminal->name,
                    'emp_code' => $empCode,
                    'skip' => $skip,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            return $response->successful();
        } catch (Throwable $e) {
            Log::error('Hikvision setAlcoholSkip failed', [
                'terminal' => $this->terminal->name,
                'emp_code' => $empCode,
                'skip' => $skip,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Fetch access events from the terminal within the given time range.
     * Paginates automatically until all events are retrieved.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAccessEvents(Carbon $startTime, Carbon $endTime, int $batchSize = 50): array
    {
        $all = [];
        $position = 0;

        try {
            do {
                $response = $this->http()
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post('/ISAPI/AccessControl/AcsEvent?format=json', [
                        'AcsEventCond' => [
                            'searchID'             => (string) now()->timestamp,
                            'searchResultPosition' => $position,
                            'maxResults'           => $batchSize,
                            'major'                => 0,
                            'minor'                => 0,
                            'startTime'            => $startTime->toIso8601String(),
                            'endTime'              => $endTime->toIso8601String(),
                        ],
                    ]);

                if (! $response->successful()) {
                    break;
                }

                $data   = $response->json('AcsEvent') ?? [];
                $batch  = $data['InfoList'] ?? [];
                $all    = array_merge($all, $batch);
                $status = $data['responseStatusStrg'] ?? 'NO MORE RESULTS';
                $position += count($batch);

                if ($status !== 'MORE' || empty($batch)) {
                    break;
                }
            } while (true);
        } catch (Throwable $e) {
            Log::error('Hikvision fetchAccessEvents failed', [
                'terminal' => $this->terminal->name,
                'error'    => $e->getMessage(),
            ]);
        }

        return $all;
    }

    /**
     * Force-update a face photo by deleting the person from the terminal and re-adding them.
     * Use this when the employee photo has changed and the face needs to be replaced.
     */
    public function refreshFace(Employee $employee): void
    {
        $this->deleteEmployee($employee);
        $this->addEmployee($employee);

        foreach ($employee->keys->where('type', 'card') as $key) {
            try {
                $this->addCard((string) $employee->emp_code, str_pad($key->value, 10, '0', STR_PAD_LEFT));
            } catch (Throwable) {
                // Non-fatal — card sync is best-effort
            }
        }

        $this->uploadFace($employee);
    }
}
