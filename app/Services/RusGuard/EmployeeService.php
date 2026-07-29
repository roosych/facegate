<?php

namespace App\Services\RusGuard;

use SoapFault;

class EmployeeService extends RusGuardService
{
    /**
     * @return array<int, array{driverId: string, name: string, type: string}>
     */
    public function getAccessPoints(): array
    {
        try {
            $result = $this->client->GetAcsAccessPointDrivers([]);

            $points = $result->GetAcsAccessPointDriversResult->AcsAccessPointDriverInfo ?? [];

            if (! is_array($points)) {
                $points = [$points];
            }

            return array_map(fn ($p) => [
                'driverId' => (string) $p->DriverId,
                'name' => (string) $p->Name,
                'type' => (string) ($p->Type ?? ''),
            ], $points);
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

    /**
     * Get deduplicated employees for a specific access point (DriverId).
     *
     * Algorithm:
     *   1. GetAcsAccessLevelsSlimInfo2        → all levels
     *   2. GetAcsAccessPoints(levelId)        → filter levels containing the driverId
     *   3. GetEmployeesByAccessLevel(levelId) → employees of those levels
     *   4. Deduplicate by uuid
     *
     * @return array<int, array{uuid: string, fio: string}>
     */
    public function getEmployeesForAccessPoint(string $driverId): array
    {
        $levels = $this->getAccessLevels();
        $employeesMap = [];

        foreach ($levels as $level) {
            try {
                $points = $this->getAccessPointsByLevel($level['id']);
            } catch (SoapFault) {
                continue;
            }

            $hasPoint = array_filter($points, fn ($p) => $p['driverId'] === $driverId) !== [];

            if (! $hasPoint) {
                continue;
            }

            try {
                $employees = $this->getEmployeesByAccessLevel($level['id']);
            } catch (SoapFault) {
                continue;
            }

            foreach ($employees as $emp) {
                $employeesMap[$emp['uuid']] = $emp;
            }
        }

        return array_values($employeesMap);
    }

    /**
     * Builds a map of access points → deduplicated employees across all levels.
     *
     * Algorithm:
     *   1. GetAcsAccessLevelsSlimInfo2       → all levels
     *   2. GetAcsAccessPoints(levelId)       → access points per level
     *   3. GetEmployeesByAccessLevel(levelId) → employees per level
     *   4. Map employees onto each access point, deduplicate by uuid
     *
     * @return array<int, array{driverId: string, name: string, employees: array<int, array{uuid: string, fio: string}>}>
     */
    public function getAccessPointsWithEmployees(): array
    {
        $levels = $this->getAccessLevels();
        $pointsMap = [];

        foreach ($levels as $level) {
            try {
                $points = $this->getAccessPointsByLevel($level['id']);
            } catch (SoapFault) {
                continue;
            }

            try {
                $employees = $this->getEmployeesByAccessLevel($level['id']);
            } catch (SoapFault) {
                $employees = [];
            }

            foreach ($points as $point) {
                $id = $point['driverId'];

                if (! isset($pointsMap[$id])) {
                    $pointsMap[$id] = [
                        'driverId' => $id,
                        'name' => $point['name'],
                        'employees' => [],
                    ];
                }

                foreach ($employees as $emp) {
                    $pointsMap[$id]['employees'][$emp['uuid']] = $emp;
                }
            }
        }

        return array_values(array_map(function (array $point): array {
            $point['employees'] = array_values($point['employees']);

            return $point;
        }, $pointsMap));
    }

    /**
     * @return array<int, array{id: string, name: string, accessPointCount: int}>
     */
    public function getAccessLevels(): array
    {
        try {
            $result = $this->client->GetAcsAccessLevelsSlimInfo2([]);

            $levels = $result->GetAcsAccessLevelsSlimInfo2Result->AcsAccessLevelSlimInfo ?? [];

            if (! is_array($levels)) {
                $levels = [$levels];
            }

            return array_map(fn ($l) => [
                'id' => (string) $l->Id,
                'name' => (string) $l->Name,
                'accessPointCount' => (int) ($l->AccessPointCount ?? 0),
            ], $levels);
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

    /**
     * @return array<int, array{uuid: string, fio: string, groupId: string}>
     */
    public function getEmployeesByAccessLevel(string $levelId): array
    {
        try {
            $result = $this->client->GetEmployeesByAccessLevel([
                'accessLevelId' => $levelId,
            ]);

            $employees = $result->GetEmployeesByAccessLevelResult->EmployeeShortInfo ?? [];

            if (! is_array($employees)) {
                $employees = [$employees];
            }

            $excludedGroupIds = config('rusguard.excluded_group_ids', []);

            return array_values(array_filter(
                array_map(fn ($e) => [
                    'uuid' => (string) ($e->ID ?? ''),
                    'fio' => (string) ($e->FIO ?? ''),
                    'groupId' => (string) ($e->EmployeeGroupID ?? ''),
                ], $employees),
                fn ($e) => $e['uuid'] !== '' && ! in_array($e['groupId'], $excludedGroupIds, true)
            ));
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getEmployee(string $uuid): array
    {
        try {
            $result = $this->client->GetAcsEmployee([
                'EmployeeId' => $uuid,
            ]);

            $emp = $result->GetAcsEmployeeResult;

            return [
                'uuid' => (string) ($emp->Id ?? $uuid),
                'firstName' => (string) ($emp->FirstName ?? ''),
                'lastName' => (string) ($emp->LastName ?? ''),
                'middleName' => (string) ($emp->MiddleName ?? ''),
            ];
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

    public function getEmployeePhoto(string $uuid, int $photoNumber = 1): ?string
    {
        try {
            $result = $this->client->GetAcsEmployeePhoto([
                'employeeId' => $uuid,
                'photoNumber' => $photoNumber,
            ]);

            $photoData = $result->GetAcsEmployeePhotoResult ?? null;

            if (empty($photoData)) {
                return null;
            }

            // SoapClient auto-decodes base64Binary fields from the WSDL,
            // so $photoData is already raw binary — no base64_decode needed.
            return (string) $photoData;
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

/**
     * @return array<int, array{driverId: string, name: string}>
     */
    public function getAccessPointsByLevel(string $levelId): array
    {
        try {
            $result = $this->client->GetAcsAccessPoints([
                'accessLevelId' => $levelId,
            ]);

            $points = $result->GetAcsAccessPointsResult->AcsAccessPointSlimInfo ?? [];

            if (! is_array($points)) {
                $points = [$points];
            }

            return array_values(array_filter(array_map(fn ($p) => [
                'driverId' => (string) ($p->AcsAccessPointDriverInfo->DriverId ?? ''),
                'name' => (string) ($p->AcsAccessPointDriverInfo->Name ?? ''),
            ], $points)));
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public function getEmployeeKeys(string $uuid): array
    {
        try {
            $result = $this->client->GetAcsKeysForEmployee([
                'employeeId' => $uuid,
            ]);

            $keys = $result->GetAcsKeysForEmployeeResult->AcsKeyInfo ?? [];

            if (! is_array($keys)) {
                $keys = [$keys];
            }

            return array_values(array_filter(array_map(function (object $key): ?array {
                $value = (string) ($key->KeyNumber ?? '');

                if ($value === '') {
                    return null;
                }

                if (filter_var($key->IsLost ?? false, FILTER_VALIDATE_BOOLEAN)) {
                    return null;
                }

                $typeName = (string) ($key->CardTypeName ?? '');

                return [
                    'type' => $typeName !== '' ? $typeName : 'card',
                    'value' => $value,
                ];
            }, $keys)));
        } catch (SoapFault $e) {
            $this->logError();
            throw $e;
        }
    }
}
