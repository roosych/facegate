<?php

namespace App\Services;

use App\Models\AccessPoint;
use App\Models\Employee;
use App\Models\EmployeeKey;
use App\Models\SyncLog;
use App\Models\SyncRun;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Support\Facades\Cache;
use Throwable;

class SyncService
{
    public function __construct(private RusGuardDatabaseService $rusGuardDb) {}

    /**
     * @return array<string, mixed>
     */
    public function syncEmployeesForAccessPoint(int $accessPointId, string $triggeredBy = SyncRun::TRIGGER_MANUAL): array
    {
        return SyncRun::track(
            SyncRun::KIND_ACCESS_POINT,
            $triggeredBy,
            ['access_point_id' => $accessPointId],
            fn (): array => $this->pullAccessPointFromRusGuard($accessPointId),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pullAccessPointFromRusGuard(int $accessPointId): array
    {
        $accessPoint = AccessPoint::findOrFail($accessPointId);

        $deviceType = $this->rusGuardDb->getAccessPointDeviceType($accessPoint->rusguard_access_point_id);
        if ($deviceType !== null && $deviceType !== $accessPoint->device_type) {
            $accessPoint->update(['device_type' => $deviceType]);
        }

        $results = ['synced' => 0, 'errors' => 0, 'employees' => []];

        $rgEmployees = $this->rusGuardDb->getEmployeesForAccessPoint($accessPoint->rusguard_access_point_id);
        $total = count($rgEmployees);
        $pointName = $accessPoint->rusguard_access_point_name ?: $accessPoint->name;
        $statusKey = self::SYNC_STATUS_KEY.'_'.$accessPointId;

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
                $this->log(null, 'sync_rusguard', 'error', $e->getMessage());
            }
        }

        // Sync pivot — adds new, removes employees no longer in RusGuard
        $accessPoint->employees()->sync($results['employees']);

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
            $this->log($employee->id, 'sync_keys', 'error', $e->getMessage());
        }

        return $employee;
    }

    /**
     * Pull all access points with employees from RusGuard in one pass,
     * upsert access points and employees into the local database.
     *
     * @return array{points: int, synced: int, errors: int, deactivated: int, pointsDeactivated: int}
     */
    public const SYNC_STATUS_KEY = 'sync_all_status';

    public function syncAllFromRusGuard(string $triggeredBy = SyncRun::TRIGGER_MANUAL): array
    {
        return SyncRun::track(
            SyncRun::KIND_RUSGUARD,
            $triggeredBy,
            [],
            fn (): array => $this->pullEverythingFromRusGuard(),
        );
    }

    /**
     * @return array{points: int, synced: int, errors: int, deactivated: int, pointsDeactivated: int}
     */
    private function pullEverythingFromRusGuard(): array
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
        // updateOrCreate() below can resolve multiple entries to the same AccessPoint row.
        // Accumulate per-access point employee ids across all of a driverId's entries and sync()
        // once at the end — calling sync() separately per entry both loses data (each call
        // replaces the previous one's set instead of merging) and can throw a unique
        // constraint violation when the same employee/access point pair gets attached twice.
        $accessPoints = [];
        $accessPointEmployeeIds = [];

        // The same employee can appear under many access points (one entry per point they
        // have access to), so without memoizing by uuid, createOrUpdateEmployee() — including
        // its RusGuard photo/keys network round-trips — reran once per point instead of once
        // per person (e.g. ~11,900 calls for ~1,000 distinct employees). Cache the outcome
        // (including failures, so a broken employee isn't retried on every point) per uuid.
        $employeeCache = [];

        foreach ($pointsWithEmployees as $i => $point) {
            $accessPoint = AccessPoint::updateOrCreate(
                ['rusguard_access_point_id' => $point['driverId']],
                ['name' => $point['name'], 'rusguard_access_point_name' => $point['name'], 'device_type' => $point['deviceType'] ?? null, 'is_active' => true]
            );

            $accessPoints[$accessPoint->id] = $accessPoint;
            $accessPointEmployeeIds[$accessPoint->id] ??= [];

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
                        $this->log(null, 'sync_rusguard', 'error', $e->getMessage());
                    }

                    $employeeCache[$uuid] = $employee;
                }

                if ($employee !== null) {
                    $accessPointEmployeeIds[$accessPoint->id][] = $employee->id;
                }

                $doneEmployees++;
            }
        }

        foreach ($accessPointEmployeeIds as $accessPointId => $employeeIds) {
            // Sync pivot: добавляет новых, отвязывает тех кого больше нет в RusGuard
            $accessPoints[$accessPointId]->employees()->sync(array_unique($employeeIds));
        }

        $totals['deactivated'] = $this->deactivateEmployeesGoneFromRusGuard();
        $totals['pointsDeactivated'] = $this->deactivateAccessPointsGoneFromRusGuard(
            array_column($pointsWithEmployees, 'driverId')
        );

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
     * AccessPoints are only ever created or refreshed from what RusGuard currently returns, so a
     * point deleted there just stops appearing and its local row stayed active forever — the
     * Check Points page listed them as "in local but not in RusGuard" and someone had to
     * deactivate each one by hand.
     *
     * Deactivate rather than delete: access events reference these rows, a Hikvision terminal
     * may still be bound to one, and a point that reappears in RusGuard is reactivated anyway
     * by the updateOrCreate() above. Employee links are deliberately left in place — detaching
     * them would empty $terminal->accessPoint->employees, and the terminal sync would then read
     * that as "nobody belongs here" and wipe every person off the device.
     *
     * @param  array<int, string>  $rusGuardDriverIds  driverIds RusGuard returned this run
     */
    private function deactivateAccessPointsGoneFromRusGuard(array $rusGuardDriverIds): int
    {
        // An empty list means RusGuard returned nothing at all — an outage or a failed query,
        // not "every access point was deleted". Deactivating the lot on that basis would take
        // the whole installation offline.
        if ($rusGuardDriverIds === []) {
            return 0;
        }

        $known = array_flip(array_map('strtolower', $rusGuardDriverIds));

        $orphans = AccessPoint::where('is_active', true)
            ->whereNotNull('rusguard_access_point_id')
            ->get(['id', 'rusguard_access_point_id'])
            ->filter(fn (AccessPoint $accessPoint): bool => ! isset($known[strtolower($accessPoint->rusguard_access_point_id)]));

        if ($orphans->isEmpty()) {
            return 0;
        }

        AccessPoint::whereIn('id', $orphans->pluck('id'))->update(['is_active' => false]);

        return $orphans->count();
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
            $employee->accessPoints()->detach();
        }

        Employee::whereIn('id', $stale->pluck('id'))->update(['is_active' => false]);

        return $stale->count();
    }

    public function syncAllAccessPoints(): void
    {
        AccessPoint::where('is_active', true)->each(function (AccessPoint $accessPoint): void {
            $this->syncEmployeesForAccessPoint($accessPoint->id);
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

    private function log(?int $employeeId, string $action, string $status, ?string $message = null): void
    {
        SyncLog::create([
            'employee_id' => $employeeId,
            'action' => $action,
            'status' => $status,
            'message' => $message,
        ]);
    }
}
