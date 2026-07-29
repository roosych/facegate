<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeKey;
use App\Models\SyncLog;
use App\Models\Turnstile;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncService
{
    public function __construct(
        private RusGuardDatabaseService $rusGuardDb,
        private ZKBioService $zkBio
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function syncEmployeesForTurnstile(int $turnstileId): array
    {
        $turnstile = Turnstile::with(['enterDevice', 'exitDevice'])->findOrFail($turnstileId);

        $deviceType = $this->rusGuardDb->getAccessPointDeviceType($turnstile->rusguard_access_point_id);
        if ($deviceType !== null && $deviceType !== $turnstile->device_type) {
            $turnstile->update(['device_type' => $deviceType]);
        }

        $results = ['synced' => 0, 'errors' => 0, 'employees' => []];

        $rgEmployees = $this->rusGuardDb->getEmployeesForAccessPoint($turnstile->rusguard_access_point_id);
        $total = count($rgEmployees);
        $pointName = $turnstile->rusguard_access_point_name ?: $turnstile->name;
        $statusKey = self::SYNC_STATUS_KEY.'_'.$turnstileId;

        Cache::put($statusKey, [
            'status' => 'running',
            'current' => $pointName,
            'done' => 0,
            'total' => 0,
            'emp_done' => 0,
            'emp_total' => $total,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        foreach ($rgEmployees as $i => $rgEmployee) {
            Cache::put($statusKey, [
                'status' => 'running',
                'current' => $pointName,
                'done' => 0,
                'total' => 0,
                'emp_done' => $i,
                'emp_total' => $total,
                'synced' => $results['synced'],
                'errors' => $results['errors'],
            ], now()->addHour());

            try {
                $employee = $this->createOrUpdateEmployee($rgEmployee);
                $results['synced']++;
                $results['employees'][] = $employee->id;
            } catch (Throwable $e) {
                $results['errors']++;
                $this->log(null, null, 'sync_rusguard', 'error', $e->getMessage());
            }
        }

        // Sync pivot — adds new, removes employees no longer in RusGuard
        $turnstile->employees()->sync($results['employees']);

        Cache::put($statusKey, [
            'status' => 'done',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'emp_done' => $total,
            'emp_total' => $total,
            'synced' => $results['synced'],
            'errors' => $results['errors'],
        ], now()->addHour());

        return $results;
    }

    /**
     * Pull one employee from RusGuard and save to local DB only.
     * Skips writes when name, photo and keys are all unchanged.
     * No ZKBio interaction — use pushTurnstileToDevices() for that.
     *
     * @param  array{uuid: string, fio: string}  $rgEmployee
     */
    public function createOrUpdateEmployee(array $rgEmployee): Employee
    {
        $uuid = $rgEmployee['uuid'];

        $employee = Employee::with('keys')->firstOrNew(['rusguard_uuid' => $uuid]);
        $isNew = ! $employee->exists;

        if ($isNew) {
            $maxCode = Employee::max('emp_code') ?? 0;
            $employee->emp_code = $maxCode + 1;
        }

        [$lastName, $firstName, $middleName] = $this->parseFio($rgEmployee['fio']);

        $nameDirty = $employee->first_name !== $firstName
            || $employee->last_name !== ($lastName ?: null)
            || $employee->middle_name !== ($middleName ?: null);

        if ($nameDirty) {
            $employee->first_name = $firstName;
            $employee->last_name = $lastName ?: null;
            $employee->middle_name = $middleName ?: null;
        }

        // Download photo — write to disk only if content changed (MD5 comparison)
        $photoDirty = false;

        try {
            $photoData = $this->rusGuardDb->getEmployeePhoto($uuid);

            if ($photoData !== null) {
                $photoDir = storage_path('app/photos');

                if (! is_dir($photoDir)) {
                    mkdir($photoDir, 0775, true);
                }

                $photoPath = $photoDir.'/'.$uuid.'.jpg';

                if (! file_exists($photoPath) || md5($photoData) !== md5_file($photoPath)) {
                    file_put_contents($photoPath, $photoData);
                    $photoDirty = true;
                }

                if ($employee->photo_path === null) {
                    $employee->photo_path = 'photos/'.$uuid.'.jpg';
                    $photoDirty = true;
                }
            }
        } catch (Throwable) {
            // Non-fatal — continue without photo
        }

        if ($isNew || $nameDirty || $photoDirty) {
            $employee->last_synced_at = now();
            $employee->save();
        }

        // Keys: compare signatures, update only if changed
        try {
            $newKeys = $this->rusGuardDb->getEmployeeKeys($uuid);

            $existingSignature = $employee->keys
                ->map(fn (EmployeeKey $k) => $k->type.':'.$k->value)
                ->sort()->values()->join(',');

            $newSignature = collect($newKeys)
                ->map(fn (array $k) => $k['type'].':'.$k['value'])
                ->sort()->values()->join(',');

            if ($isNew || $existingSignature !== $newSignature) {
                $employee->keys()->delete();

                foreach ($newKeys as $key) {
                    $employee->keys()->create(['type' => $key['type'], 'value' => $key['value']]);
                }
            }
        } catch (Throwable $e) {
            $this->log($employee->id, null, 'sync_keys', 'error', $e->getMessage());
        }

        return $employee;
    }

    /**
     * Push all employees of a turnstile to its ZKBio devices.
     * Reads only from local DB — no RusGuard calls.
     *
     * @return array{synced: int, errors: int}
     */
    public function pushTurnstileToDevices(int $turnstileId): array
    {
        $turnstile = Turnstile::with(['enterDevice', 'exitDevice', 'employees.keys'])->findOrFail($turnstileId);

        $devices = array_filter([$turnstile->enterDevice, $turnstile->exitDevice]);
        $results = ['synced' => 0, 'errors' => 0];
        $total = $turnstile->employees->count();
        $pointName = $turnstile->rusguard_access_point_name ?: $turnstile->name;

        Cache::put(self::SYNC_STATUS_KEY, [
            'status' => 'running',
            'current' => $pointName,
            'done' => 0,
            'total' => 0,
            'emp_done' => 0,
            'emp_total' => $total,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        $jwtToken = null;
        $session = null;

        foreach ($turnstile->employees as $i => $employee) {
            Cache::put(self::SYNC_STATUS_KEY, [
                'status' => 'running',
                'current' => $pointName,
                'done' => 0,
                'total' => 0,
                'emp_done' => $i,
                'emp_total' => $total,
                'synced' => $results['synced'],
                'errors' => $results['errors'],
            ], now()->addHour());

            try {
                // Create in ZKBio if not registered yet
                if ($employee->zkbio_id === null) {
                    $jwtToken ??= $this->zkBio->getJwtToken();
                    $cardNo = $employee->keys->where('type', 'card')->first()?->value;
                    $zkbioId = $this->zkBio->createEmployee([
                        'emp_code' => (string) $employee->emp_code,
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name ?? '',
                        'department' => (int) config('zkbio.department_id'),
                        'area' => [(int) config('zkbio.area_id')],
                        'card_no' => $cardNo !== null ? $this->formatCardNo($cardNo) : '',
                    ]);

                    $employee->zkbio_id = $zkbioId;
                    $employee->save();

                    $this->log($employee->id, null, 'create_employee', 'success', 'Created in ZKBio');
                }

                // Upload bio photo if employee has one
                $photoPath = $employee->photo_path ? storage_path('app/'.$employee->photo_path) : null;

                if ($employee->zkbio_id !== null && $photoPath !== null && file_exists($photoPath)) {
                    $session ??= $this->zkBio->getBrowserSession();

                    $uploaded = $this->zkBio->uploadBioPhoto($employee->zkbio_id, [
                        'emp_code' => $employee->emp_code,
                        'first_name' => $employee->first_name,
                        'last_name' => $employee->last_name ?? '',
                        'card_no' => $this->formatCardNo($employee->keys->where('type', 'card')->first()?->value ?? ''),
                    ], $photoPath, $session);

                    $this->log($employee->id, null, 'upload_photo', $uploaded ? 'success' : 'error',
                        $uploaded ? 'Photo uploaded' : 'Photo upload failed'
                    );

                    if ($uploaded) {
                        $approved = $this->zkBio->approveBioPhoto($employee->emp_code, $session);

                        $this->log($employee->id, null, 'approve_photo', $approved ? 'success' : 'error',
                            $approved ? 'Photo approved' : 'Photo approval failed'
                        );
                    }
                }

                // Push to each device
                foreach ($devices as $device) {
                    if ($employee->zkbio_id === null) {
                        continue;
                    }

                    $jwtToken ??= $this->zkBio->getJwtToken();
                    $synced = $this->zkBio->syncEmployeeToDevice($employee->zkbio_id, $jwtToken);

                    $this->log($employee->id, $device->id, 'sync_device', $synced ? 'success' : 'error',
                        $synced ? 'Synced to '.$device->name : 'Failed to sync to '.$device->name
                    );
                }

                $results['synced']++;
            } catch (Throwable $e) {
                $results['errors']++;
                $this->log($employee->id, null, 'push_device', 'error', $e->getMessage());
            }
        }

        if ($session !== null && file_exists($session['cookieJar'])) {
            unlink($session['cookieJar']);
        }

        Cache::put(self::SYNC_STATUS_KEY, [
            'status' => 'done',
            'current' => '',
            'done' => 0,
            'total' => 0,
            'emp_done' => $total,
            'emp_total' => $total,
            'synced' => $results['synced'],
            'errors' => $results['errors'],
        ], now()->addHour());

        return $results;
    }

    /**
     * Pull all access points with employees from RusGuard in one pass,
     * upsert turnstiles and employees into the local database.
     *
     * @return array{points: int, synced: int, errors: int}
     */
    public const SYNC_STATUS_KEY = 'sync_all_status';

    public function syncAllFromRusGuard(): array
    {
        $pointsWithEmployees = $this->rusGuardDb->getAccessPointsWithEmployees();

        $total = count($pointsWithEmployees);
        $totalEmployees = (int) array_sum(array_map(fn ($p) => count($p['employees']), $pointsWithEmployees));
        $totals = ['points' => $total, 'synced' => 0, 'errors' => 0];
        $doneEmployees = 0;

        Cache::put(self::SYNC_STATUS_KEY, [
            'status' => 'running',
            'current' => '',
            'done' => 0,
            'total' => $total,
            'emp_done' => 0,
            'emp_total' => $totalEmployees,
            'synced' => 0,
            'errors' => 0,
        ], now()->addHour());

        // RusGuard can return more than one $point entry for the same DriverID (e.g. two
        // AcsAccessLevels — one removed, one active — pointing at the same physical door), so
        // updateOrCreate() below can resolve multiple entries to the same Turnstile row.
        // Accumulate per-turnstile employee ids across all of a driverId's entries and sync()
        // once at the end — calling sync() separately per entry both loses data (each call
        // replaces the previous one's set instead of merging) and can throw a unique
        // constraint violation when the same employee/turnstile pair gets attached twice.
        $turnstiles = [];
        $turnstileEmployeeIds = [];

        // The same employee can appear under many access points (one entry per point they
        // have access to), so without memoizing by uuid, createOrUpdateEmployee() — including
        // its RusGuard photo/keys network round-trips — reran once per point instead of once
        // per person (e.g. ~11,900 calls for ~1,000 distinct employees). Cache the outcome
        // (including failures, so a broken employee isn't retried on every point) per uuid.
        $employeeCache = [];

        foreach ($pointsWithEmployees as $i => $point) {
            $turnstile = Turnstile::updateOrCreate(
                ['rusguard_access_point_id' => $point['driverId']],
                ['name' => $point['name'], 'rusguard_access_point_name' => $point['name'], 'device_type' => $point['deviceType'] ?? null, 'is_active' => true]
            );

            $turnstiles[$turnstile->id] = $turnstile;
            $turnstileEmployeeIds[$turnstile->id] ??= [];

            foreach ($point['employees'] as $rgEmployee) {
                Cache::put(self::SYNC_STATUS_KEY, [
                    'status' => 'running',
                    'current' => $point['name'],
                    'done' => $i + 1,
                    'total' => $total,
                    'emp_done' => $doneEmployees,
                    'emp_total' => $totalEmployees,
                    'synced' => $totals['synced'],
                    'errors' => $totals['errors'],
                ], now()->addHour());

                $uuid = $rgEmployee['uuid'];

                if (array_key_exists($uuid, $employeeCache)) {
                    $employee = $employeeCache[$uuid];
                } else {
                    try {
                        $employee = $this->createOrUpdateEmployee($rgEmployee);
                        $totals['synced']++;
                    } catch (Throwable $e) {
                        $employee = null;
                        $totals['errors']++;
                        $this->log(null, null, 'sync_rusguard', 'error', $e->getMessage());
                    }

                    $employeeCache[$uuid] = $employee;
                }

                if ($employee !== null) {
                    $turnstileEmployeeIds[$turnstile->id][] = $employee->id;
                }

                $doneEmployees++;
            }
        }

        foreach ($turnstileEmployeeIds as $turnstileId => $employeeIds) {
            // Sync pivot: добавляет новых, отвязывает тех кого больше нет в RusGuard
            $turnstiles[$turnstileId]->employees()->sync(array_unique($employeeIds));
        }

        $totals['deactivated'] = $this->deactivateEmployeesGoneFromRusGuard();

        Cache::put(self::SYNC_STATUS_KEY, [
            'status' => 'done',
            'current' => '',
            'done' => $total,
            'total' => $total,
            'emp_done' => $totalEmployees,
            'emp_total' => $totalEmployees,
            'synced' => $totals['synced'],
            'errors' => $totals['errors'],
        ], now()->addHour());

        return $totals;
    }

    /**
     * Employees are only ever added/updated by point-scoped syncs — someone who disappears
     * from RusGuard entirely (fired, moved to the excluded/departed group) just silently stops
     * appearing in any access point's employee list, but their local Employee row is never
     * touched, so they'd stay active forever. Compare the full local active roster against
     * RusGuard's authoritative active-employee set and deactivate anyone no longer in it.
     */
    private function deactivateEmployeesGoneFromRusGuard(): int
    {
        $activeInRusGuard = array_flip($this->rusGuardDb->getActiveEmployeeUuids());

        $stale = Employee::where('is_active', true)
            ->whereNotNull('rusguard_uuid')
            ->get(['id', 'rusguard_uuid'])
            ->filter(fn (Employee $employee): bool => ! isset($activeInRusGuard[strtolower($employee->rusguard_uuid)]));

        if ($stale->isEmpty()) {
            return 0;
        }

        foreach ($stale as $employee) {
            $employee->turnstiles()->detach();
        }

        Employee::whereIn('id', $stale->pluck('id'))->update(['is_active' => false]);

        return $stale->count();
    }

    public function syncAllTurnstiles(): void
    {
        Turnstile::where('is_active', true)->each(function (Turnstile $turnstile): void {
            $this->syncEmployeesForTurnstile($turnstile->id);
        });
    }

    /**
     * Parse "Фамилия Имя Отчество" into parts.
     *
     * @return array{string, string, string}
     */
    private function parseFio(string $fio): array
    {
        $parts = preg_split('/\s+/', trim($fio), 3);

        return [
            $parts[0] ?? '',
            $parts[1] ?? '',
            $parts[2] ?? '',
        ];
    }

    /**
     * Prepend leading zero to match the physical Wiegand card number the terminal reads.
     * RusGuard stores e.g. "505835446", terminal reads "0505835446".
     */
    private function formatCardNo(string $cardNo): string
    {
        if ($cardNo === '') {
            return '';
        }

        return '0'.$cardNo;
    }

    private function log(?int $employeeId, ?int $deviceId, string $action, string $status, ?string $message = null): void
    {
        SyncLog::create([
            'employee_id' => $employeeId,
            'device_id' => $deviceId,
            'action' => $action,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
