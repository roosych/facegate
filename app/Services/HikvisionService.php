<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
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
    /**
     * Value stored in the person's PersonInfoExtends to exempt them from alcohol testing.
     * Read back verbatim by UserInfo/Search, so callers can compare before writing.
     */
    public const ALCOHOL_SKIP_FLAG = 'skip_alcohol';

    /**
     * How many times to re-ask for a search page that came back empty while the device still
     * claims more rows. See searchAllPages().
     */
    private const SEARCH_PAGE_RETRIES = 3;

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
     * Gate every state-changing call to a physical terminal on this deployment being the one
     * that owns it (config('hikvision.sync_enabled')). Reads are never gated. Two environments
     * writing one device race for the same emp_code and silently corrupt person/card/face
     * records — see config/hikvision.php.
     *
     * @throws RuntimeException when writes are disabled for this environment
     */
    private function guardWrite(): void
    {
        if (! config('hikvision.sync_enabled')) {
            throw new RuntimeException(
                'Hikvision terminal writes are disabled in this environment '
                .'(config hikvision.sync_enabled is false). A physical terminal may be driven by only one '
                .'environment; set HIKVISION_SYNC_ENABLED=true only where this deployment owns the device.'
            );
        }
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
        $searchId = (string) mt_rand(100000, 999999);

        return $this->searchAllPages(function (int $offset, int $pageSize) use ($searchId): array {
            $result = $this->searchPersons($offset, $pageSize, $searchId);

            return ['items' => $result['persons'], 'total' => $result['total']];
        }, 'person');
    }

    /**
     * Drive a paginated ISAPI search to completion and return every row it yields.
     *
     * Two device behaviours are handled here. It caps pages below the requested size (30
     * rather than 50 in practice), which is harmless. But it also answers mid-pagination with
     * an empty page now and then while totalMatches still promises more rows, and taking that
     * as the end of the list silently truncated the result — 120 and 210 rows out of 668 were
     * both observed. A short roster is not a harmless performance issue: the sync re-pushes
     * people it already has, and removeUnlinkedPersons() never sees the persons missing from
     * it, so someone who should have lost access keeps it. Retry an empty page before
     * believing it, and refuse to hand back a knowingly partial list.
     *
     * The page callback is expected to swallow transport errors and report an empty page, so
     * a blip is retried here rather than aborting the whole scan; totalMatches is tracked as a
     * high-water mark so a failed page reporting 0 can't shrink the expectation either.
     *
     * @param  callable(int $offset, int $pageSize): array{items: array<int, mixed>, total: int}  $fetchPage
     * @return array<int, mixed>
     *
     * @throws RuntimeException when the device keeps returning nothing while claiming more rows
     */
    private function searchAllPages(callable $fetchPage, string $what, int $pageSize = 50): array
    {
        $all = [];
        $offset = 0;
        $total = 0;

        do {
            $result = $fetchPage($offset, $pageSize);
            $page = $result['items'];
            $total = max($total, $result['total']);

            for ($attempt = 1; $page === [] && $offset < $total && $attempt <= self::SEARCH_PAGE_RETRIES; $attempt++) {
                usleep(300_000 * $attempt);
                $result = $fetchPage($offset, $pageSize);
                $page = $result['items'];
                $total = max($total, $result['total']);
            }

            if ($page === [] && $offset < $total) {
                throw new RuntimeException(
                    'Hikvision '.$what.' search truncated on ['.$this->terminal->name.']: '
                    .'got '.$offset.' of '.$total.' rows after '.self::SEARCH_PAGE_RETRIES.' retries'
                );
            }

            $all = array_merge($all, $page);
            $offset += count($page);
        } while ($page !== [] && ($offset < $total || count($page) >= $pageSize));

        return $all;
    }

    /**
     * Add or update a person on the terminal.
     */
    public function addEmployee(Employee $employee): void
    {
        $this->guardWrite();

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
        $this->guardWrite();

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
        $this->guardWrite();

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
     * @return array<string, array<int, string>> employeeNo => list of cardNos
     */
    public function allCards(): array
    {
        $searchId = (string) mt_rand(100000, 999999);

        $rows = $this->searchAllPages(function (int $offset, int $pageSize) use ($searchId): array {
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

                return ['items' => $page, 'total' => (int) ($search['totalMatches'] ?? 0)];
            } catch (Throwable $e) {
                Log::error('Hikvision allCards page failed', [
                    'terminal' => $this->terminal->name,
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                ]);

                return ['items' => [], 'total' => 0];
            }
        }, 'card');

        $all = [];

        foreach ($rows as $card) {
            $empNo = (string) ($card['employeeNo'] ?? '');
            $cardNo = (string) ($card['cardNo'] ?? '');

            if ($empNo !== '' && $cardNo !== '') {
                $all[$empNo][] = $cardNo;
            }
        }

        return $all;
    }

    /**
     * Return the set of employeeNos that have a face photo stored on the terminal.
     *
     * @return array<string, true>
     */
    public function empCodesWithFace(): array
    {
        $searchId = (string) mt_rand(100000, 999999);

        $rows = $this->searchAllPages(function (int $offset, int $pageSize) use ($searchId): array {
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

                return ['items' => $data['MatchList'] ?? [], 'total' => (int) ($data['totalMatches'] ?? 0)];
            } catch (Throwable $e) {
                Log::error('Hikvision empCodesWithFace page failed', [
                    'terminal' => $this->terminal->name,
                    'offset' => $offset,
                    'error' => $e->getMessage(),
                ]);

                return ['items' => [], 'total' => 0];
            }
        }, 'face');

        $result = [];

        foreach ($rows as $item) {
            $fpid = (string) ($item['FPID'] ?? '');

            if ($fpid !== '') {
                $result[$fpid] = true;
            }
        }

        return $result;
    }

    /**
     * Add a card for an employee on the terminal.
     */
    public function addCard(string $empCode, string $cardNo): void
    {
        $this->guardWrite();

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
     *
     * A swallowed failure here leaves a stale/shifted card on the person, so the correct
     * card keeps colliding on Hikvision's global card-number uniqueness — check the response.
     */
    public function deleteCards(string $empCode): void
    {
        $this->guardWrite();

        $response = $this->http()
            ->withHeaders(['Content-Type' => 'application/json'])
            ->put('/ISAPI/AccessControl/CardInfo/Delete?format=json', [
                'CardInfoDelCond' => [
                    'EmployeeNoList' => [
                        ['employeeNo' => $empCode],
                    ],
                ],
            ]);

        if (! $response->successful() && $response->status() !== 404) {
            throw new RuntimeException(
                'Failed to delete cards for '.$empCode.' on '.$this->terminal->name.': '.$response->body()
            );
        }
    }

    /**
     * Upload a face photo for an employee via multipart/form-data (FaceDataRecord endpoint).
     * If a face already exists on the terminal, it is replaced in place via FDModify.
     */
    public function uploadFace(Employee $employee): void
    {
        $this->guardWrite();

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

        $response = $this->sendFaceRecord($employee, $imageData);

        if ($response->successful()) {
            return;
        }

        // A face record already exists for this FPID and FDSetUp refuses to overwrite it.
        // The sub-status differs across firmwares ('alreadyExist' on this device,
        // 'deviceUserAlreadyExistFace' on others), so accept both and retry through
        // FDModify, which replaces the stored picture in place. Without this the upload
        // was reported as a hard sync error even though the person was fine, and a photo
        // changed in RusGuard never reached the terminal.
        if (in_array($response->json('subStatusCode') ?? '', ['alreadyExist', 'deviceUserAlreadyExistFace'], true)) {
            $modifyResponse = $this->sendFaceRecord($employee, $imageData, modify: true);

            if ($modifyResponse->successful()) {
                return;
            }

            throw new RuntimeException(
                'Failed to update existing face for '.$employee->emp_code.': '.$modifyResponse->body()
            );
        }

        // The device deduplicates on picture content, and it remembers images it has seen even
        // after the face library is emptied — deleting the FPID, wiping the library and
        // recreating the person all leave it answering 'alreadyExistThisFace'. Since
        // normalizeFaceImage() is deterministic, a person who once hit this stayed permanently
        // without a photo. Re-encoding the same source at a different target size yields
        // different bytes, which the device accepts; verified against a person stuck this way.
        if (($response->json('subStatusCode') ?? '') === 'alreadyExistThisFace') {
            $reEncoded = $this->reEncodeJpeg($imageData, 82);

            if ($reEncoded !== $imageData && $this->sendFaceRecord($employee, $reEncoded)->successful()) {
                return;
            }

            throw new RuntimeException(
                'Terminal already holds this face for emp '.$employee->emp_code
                .' and rejected a re-encoded copy too — clear that person\'s face on the terminal itself, then re-sync'
            );
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
     * Re-encode a JPEG at a different quality so the bytes differ while the picture stays the
     * same. Returns the input unchanged if GD is unavailable or the data isn't decodable.
     */
    private function reEncodeJpeg(string $imageData, int $quality): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $imageData;
        }

        $image = @imagecreatefromstring($imageData);

        if ($image === false) {
            return $imageData;
        }

        ob_start();
        imagejpeg($image, null, $quality);
        $encoded = ob_get_clean();
        imagedestroy($image);

        return $encoded === false ? $imageData : $encoded;
    }

    /**
     * Send a face picture to the terminal as multipart/form-data. FDSetUp registers a new
     * face, FDModify replaces the picture of one that already exists; both take the same
     * descriptor, only under a differently named field.
     */
    private function sendFaceRecord(Employee $employee, string $imageData, bool $modify = false): Response
    {
        $field = $modify ? 'FaceDataModify' : 'FaceDataRecord';
        $endpoint = $modify ? 'FDModify' : 'FDSetUp';

        return $this->http()
            ->attach(
                $field,
                json_encode([
                    'faceLibType' => 'blackFD',
                    'FDID' => '1',
                    'FPID' => (string) $employee->emp_code,
                ]),
                $field,
                ['Content-Type' => 'application/json']
            )
            ->attach('img', $imageData, $employee->emp_code.'.jpg', ['Content-Type' => 'image/jpeg'])
            ->put('/ISAPI/Intelligent/FDLib/'.$endpoint.'?format=json');
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
     * Fetch one person's raw UserInfo record (name, doorRight, PersonInfoExtends, etc.) by
     * employee number — used by the alcohol debug page to show a device-side snapshot.
     *
     * @return array<string, mixed>|null
     */
    public function fetchUserInfo(string $empCode): ?array
    {
        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/ISAPI/AccessControl/UserInfo/Search?format=json', [
                    'UserInfoSearchCond' => [
                        'searchID' => (string) now()->timestamp,
                        'searchResultPosition' => 0,
                        'maxResults' => 1,
                        'EmployeeNoList' => [['employeeNo' => $empCode]],
                    ],
                ]);

            return $response->json('UserInfoSearch.UserInfo.0');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Same lookup as fetchUserInfo(), but returns the exact response body the terminal sent —
     * unparsed, unshaped — for handing raw evidence to Hikvision's firmware support.
     */
    public function rawUserInfo(string $empCode): ?string
    {
        try {
            return $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/ISAPI/AccessControl/UserInfo/Search?format=json', [
                    'UserInfoSearchCond' => [
                        'searchID' => (string) now()->timestamp,
                        'searchResultPosition' => 0,
                        'maxResults' => 1,
                        'EmployeeNoList' => [['employeeNo' => $empCode]],
                    ],
                ])->body();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Raw AlcoholDetectionParams response body, exactly as the terminal sent it.
     */
    public function rawAlcoholDetectionParams(): ?string
    {
        try {
            return $this->http()
                ->get('/ISAPI/AccessControl/AccessControlAlcoholDetection/AlcoholDetectionParams?format=json')
                ->body();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Raw AcsEvent search response body for a time window, exactly as the terminal sent it —
     * a single one-shot search (no pagination), enough for a debug snapshot of "what just
     * happened" rather than a full historical pull.
     */
    public function rawRecentEvents(Carbon $startTime, Carbon $endTime, int $maxResults = 30): ?string
    {
        try {
            return $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('/ISAPI/AccessControl/AcsEvent?format=json', [
                    'AcsEventCond' => [
                        'searchID' => (string) now()->timestamp,
                        'searchResultPosition' => 0,
                        'maxResults' => $maxResults,
                        'major' => 0,
                        'minor' => 0,
                        'startTime' => $startTime->toIso8601String(),
                        'endTime' => $endTime->toIso8601String(),
                    ],
                ])->body();
        } catch (Throwable) {
            return null;
        }
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
     * @param  array<string, mixed>  $params
     */
    public function setAlcoholDetectionParams(array $params): bool
    {
        $this->guardWrite();

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
        $this->guardWrite();

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

        // Without an explicit SubscribeEvent block the terminal defaults to eventMode=all,
        // pushing every door/system/admin event (and, on first enable, its entire backlog).
        // minorEvent 0x1/0x4b/0x9a are successful card/face auth variants, 0x81d (2077) is the
        // alcohol detection result — confirmed against this terminal's own AccessControllerEvent
        // payloads. Everything else (door open/close, alarms, admin ops) is filtered out.
        $minorEvents = '0x1,0x4b,0x9a,0x81d';

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
            <SubscribeEvent>
            <heartbeat>30</heartbeat>
            <eventMode>list</eventMode>
            <EventList>
            <Event>
            <type>AccessControllerEvent</type>
            <minorAlarm></minorAlarm>
            <minorException></minorException>
            <minorOperation></minorOperation>
            <minorEvent>{$minorEvents}</minorEvent>
            <pictureURLType>binary</pictureURLType>
            </Event>
            </EventList>
            </SubscribeEvent>
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
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
        'sunday' => 7,
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
                        'endTime' => $range['endTime'] ?? '18:00',
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
     * @param  array<string, array{enabled: bool, periods: array<int, array{beginTime: string, endTime: string}>}>  $plan
     */
    public function setAlcoholWeekPlan(array $plan): bool
    {
        $this->guardWrite();

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
                    'startTime' => $period['beginTime'],
                    'endTime' => $period['endTime'],
                    'alcoholDetEnabled' => true,
                    'alcoholDetTimes' => 1,
                ], $periods),
            ];
        }

        try {
            $response = $this->http()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->put('/ISAPI/AccessControl/AccessControlAlcoholDetectionPlan/ModifyAlcoholWeekPlan?format=json', [
                    'weekPlanID' => self::ALCOHOL_WEEK_PLAN_ID,
                    'enabled' => true,
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
        $this->guardWrite();

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
                        'PersonInfoExtends' => [['value' => $skip ? self::ALCOHOL_SKIP_FLAG : '']],
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
                            'searchID' => (string) now()->timestamp,
                            'searchResultPosition' => $position,
                            'maxResults' => $batchSize,
                            'major' => 0,
                            'minor' => 0,
                            'startTime' => $startTime->toIso8601String(),
                            'endTime' => $endTime->toIso8601String(),
                        ],
                    ]);

                if (! $response->successful()) {
                    break;
                }

                $data = $response->json('AcsEvent') ?? [];
                $batch = $data['InfoList'] ?? [];
                $all = array_merge($all, $batch);
                $status = $data['responseStatusStrg'] ?? 'NO MORE RESULTS';
                $position += count($batch);

                if ($status !== 'MORE' || empty($batch)) {
                    break;
                }
            } while (true);
        } catch (Throwable $e) {
            Log::error('Hikvision fetchAccessEvents failed', [
                'terminal' => $this->terminal->name,
                'error' => $e->getMessage(),
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
        $this->guardWrite();

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
