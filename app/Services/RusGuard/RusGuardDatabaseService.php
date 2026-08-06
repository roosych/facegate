<?php

namespace App\Services\RusGuard;

use PDO;
use PDOException;
use RuntimeException;

class RusGuardDatabaseService
{
    private ?PDO $pdo = null;

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        $host = config('database.connections.rusguard.host');
        $db = config('database.connections.rusguard.database');
        $user = config('database.connections.rusguard.username');
        $pass = config('database.connections.rusguard.password');

        try {
            $this->pdo = new PDO(
                "dblib:host={$host};dbname={$db}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            throw new RuntimeException('RusGuard DB connection failed: '.$e->getMessage(), 0, $e);
        }

        return $this->pdo;
    }

    /**
     * Get deduplicated employees for a specific access point (DriverId UUID string).
     *
     * Combines:
     *   - Direct employee → access level → access point assignments
     *   - Group → access level → access point assignments (inherited)
     *
     * @return array<int, array{uuid: string, fio: string, groupId: string}>
     */
    public function getEmployeesForAccessPoint(string $driverId): array
    {
        $excludedGroupIds = array_map('strtoupper', config('rusguard.excluded_group_ids', []));
        $excludePlaceholders = $excludedGroupIds !== [] ? implode(',', array_fill(0, count($excludedGroupIds), '?')) : null;

        $excludeClause = $excludePlaceholders !== null
            ? "AND CONVERT(varchar(36), e.EmployeeGroupID) NOT IN ({$excludePlaceholders})"
            : '';

        $sql = "
            SELECT DISTINCT
                CONVERT(varchar(36), e._id)             AS uuid,
                ISNULL(e.LastName, '')
                    + CASE WHEN e.FirstName  <> '' THEN ' ' + e.FirstName  ELSE '' END
                    + CASE WHEN e.SecondName <> '' THEN ' ' + e.SecondName ELSE '' END AS fio,
                CONVERT(varchar(36), e.EmployeeGroupID) AS groupId
            FROM Employee e
            WHERE e.IsRemoved = 0
              AND e.IsLocked = 0
              {$excludeClause}
              AND (
                  EXISTS (
                      SELECT 1
                      FROM EmployeeAcsAccessLevel eal
                      INNER JOIN AcsAccessPoint ap ON ap.AcsAccessLevelID = eal.AcsAccessLevelID
                      WHERE eal.EmployeeID  = e._id
                        AND CONVERT(varchar(36), ap.DriverID) = ?
                        AND ap.IsRemoved = 0
                        AND (eal.EndDate IS NULL OR eal.EndDate > GETDATE())
                  )
                  OR (
                      e.IsAccessLevelsInherited = 1
                      AND EXISTS (
                          SELECT 1
                          FROM EmployeeGroupAcsAccessLevel gal
                          INNER JOIN AcsAccessPoint ap ON ap.AcsAccessLevelID = gal.AcsAccessLevelID
                          WHERE gal.EmployeeGroupID = e.EmployeeGroupID
                            AND CONVERT(varchar(36), ap.DriverID) = ?
                            AND ap.IsRemoved = 0
                            AND (gal.EndDate IS NULL OR gal.EndDate > GETDATE())
                      )
                  )
              )
        ";

        $params = array_merge($excludedGroupIds, [strtoupper($driverId), strtoupper($driverId)]);
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get all access points with their deduplicated employees in a single pass.
     *
     * @return array<int, array{driverId: string, name: string, employees: array<int, array{uuid: string, fio: string, groupId: string}>}>
     */
    public function getAccessPointsWithEmployees(): array
    {
        // First, get all active access points (one per level — level name = point name in RusGuard)
        $apSql = "
            SELECT DISTINCT
                CONVERT(varchar(36), ap.DriverID)          AS driverId,
                CONVERT(varchar(36), ap.AcsAccessLevelID)  AS levelId,
                ISNULL(p.Value, ISNULL(al.Name, ''))       AS name,
                ISNULL(d.ObjectTypeName, '')               AS driverType
            FROM AcsAccessPoint ap
            LEFT JOIN AcsAccessLevel al ON al._id = ap.AcsAccessLevelID
            LEFT JOIN Property p ON p._idResource = ap.DriverID AND p.PropertyName = 'Name'
            LEFT JOIN Driver d ON d._idResource = ap.DriverID
            WHERE ap.IsRemoved = 0
        ";

        $apStmt = $this->pdo()->query($apSql);
        $accessPoints = $apStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($accessPoints)) {
            return [];
        }

        $typeMap = [
            'TourniquetDriver' => 'Турникет',
            'OneSidedDoorDriver' => 'Дверь',
            'TwoSidedDoorDriver' => 'Дверь (2 стор.)',
            'GateDriver' => 'Ворота',
            'FaceRecognitionDriver' => 'Биометрия',
        ];

        // Build a map of levelId → [driverIds]
        $levelToPoints = [];
        $pointMap = [];

        foreach ($accessPoints as $ap) {
            $rawType = $ap['driverType'] ?? '';
            preg_match('/\.(\w+),/', $rawType, $m);
            $deviceType = $typeMap[$m[1] ?? ''] ?? null;

            $levelToPoints[$ap['levelId']][] = $ap['driverId'];
            $pointMap[$ap['driverId']] = [
                'driverId' => $ap['driverId'],
                'name' => $ap['name'],
                'deviceType' => $deviceType,
                'employees' => [],
            ];
        }

        $excludedGroupIds = array_map('strtoupper', config('rusguard.excluded_group_ids', []));
        $excludePlaceholders = $excludedGroupIds !== [] ? implode(',', array_fill(0, count($excludedGroupIds), '?')) : null;
        $excludeClause = $excludePlaceholders !== null
            ? "AND CONVERT(varchar(36), e.EmployeeGroupID) NOT IN ({$excludePlaceholders})"
            : '';

        // Fetch employees via direct access level assignments
        $empSql = "
            SELECT DISTINCT
                CONVERT(varchar(36), ap.DriverID)             AS driverId,
                CONVERT(varchar(36), e._id)                   AS uuid,
                ISNULL(e.LastName, '')
                    + CASE WHEN e.FirstName  <> '' THEN ' ' + e.FirstName  ELSE '' END
                    + CASE WHEN e.SecondName <> '' THEN ' ' + e.SecondName ELSE '' END AS fio,
                CONVERT(varchar(36), e.EmployeeGroupID)       AS groupId
            FROM Employee e
            INNER JOIN EmployeeAcsAccessLevel eal ON eal.EmployeeID = e._id
            INNER JOIN AcsAccessPoint ap ON ap.AcsAccessLevelID = eal.AcsAccessLevelID
            WHERE e.IsRemoved = 0
              AND e.IsLocked = 0
              {$excludeClause}
              AND ap.IsRemoved = 0
              AND (eal.EndDate IS NULL OR eal.EndDate > GETDATE())
        ";

        $empStmt = $this->pdo()->prepare($empSql);
        $empStmt->execute($excludedGroupIds);

        foreach ($empStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $driverId = $row['driverId'];

            if (isset($pointMap[$driverId])) {
                $pointMap[$driverId]['employees'][$row['uuid']] = [
                    'uuid' => $row['uuid'],
                    'fio' => trim($row['fio']),
                    'groupId' => $row['groupId'],
                ];
            }
        }

        // Fetch employees via group access level assignments
        $groupEmpSql = "
            SELECT DISTINCT
                CONVERT(varchar(36), ap.DriverID)             AS driverId,
                CONVERT(varchar(36), e._id)                   AS uuid,
                ISNULL(e.LastName, '')
                    + CASE WHEN e.FirstName  <> '' THEN ' ' + e.FirstName  ELSE '' END
                    + CASE WHEN e.SecondName <> '' THEN ' ' + e.SecondName ELSE '' END AS fio,
                CONVERT(varchar(36), e.EmployeeGroupID)       AS groupId
            FROM Employee e
            INNER JOIN EmployeeGroupAcsAccessLevel gal ON gal.EmployeeGroupID = e.EmployeeGroupID
            INNER JOIN AcsAccessPoint ap ON ap.AcsAccessLevelID = gal.AcsAccessLevelID
            WHERE e.IsRemoved = 0
              AND e.IsLocked = 0
              {$excludeClause}
              AND e.IsAccessLevelsInherited = 1
              AND ap.IsRemoved = 0
              AND (gal.EndDate IS NULL OR gal.EndDate > GETDATE())
        ";

        $groupEmpStmt = $this->pdo()->prepare($groupEmpSql);
        $groupEmpStmt->execute($excludedGroupIds);

        foreach ($groupEmpStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $driverId = $row['driverId'];

            if (isset($pointMap[$driverId])) {
                $pointMap[$driverId]['employees'][$row['uuid']] = [
                    'uuid' => $row['uuid'],
                    'fio' => trim($row['fio']),
                    'groupId' => $row['groupId'],
                ];
            }
        }

        // Normalize employees arrays
        return array_values(array_map(function (array $point): array {
            $point['employees'] = array_values($point['employees']);

            return $point;
        }, $pointMap));
    }

    /**
     * @return array{uuid: string, firstName: string, lastName: string, middleName: string}
     */
    public function getEmployee(string $uuid): array
    {
        $stmt = $this->pdo()->prepare(
            'SELECT CONVERT(varchar(36), _id) AS uuid, FirstName, SecondName, LastName
             FROM Employee WHERE CONVERT(varchar(36), _id) = ?'
        );
        $stmt->execute([strtoupper($uuid)]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $row) {
            return ['uuid' => $uuid, 'firstName' => '', 'lastName' => '', 'middleName' => ''];
        }

        return [
            'uuid' => $row['uuid'],
            'firstName' => (string) ($row['FirstName'] ?? ''),
            'lastName' => (string) ($row['LastName'] ?? ''),
            'middleName' => (string) ($row['SecondName'] ?? ''),
        ];
    }

    /**
     * Returns raw binary photo data or null if not found.
     */
    public function getEmployeePhoto(string $uuid, int $photoNumber = 1): ?string
    {
        $stmt = $this->pdo()->prepare(
            'SELECT Photo FROM EmployeePhoto
             WHERE CONVERT(varchar(36), EmployeeID) = ? AND PhotoNumber = ?'
        );
        $stmt->execute([strtoupper($uuid), $photoNumber]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (! $row || empty($row['Photo'])) {
            return null;
        }

        return (string) $row['Photo'];
    }

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public function getEmployeeKeys(string $uuid): array
    {
        $stmt = $this->pdo()->prepare('
            SELECT DISTINCT k.KeyNumber
            FROM AcsKeys k
            INNER JOIN AcsKey2EmployeeAssignment a
                ON k.KeyNumber = a.AcsKeyId
            WHERE CONVERT(varchar(36), a.EmployeeId) = ?
              AND a.AssignmentModificationType = 0
              AND k.CardTypeID IS NULL
              AND (k.EndDate IS NULL OR k.EndDate >= GETDATE())
        ');
        $stmt->execute([strtoupper($uuid)]);

        $keys = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $value = (string) $row['KeyNumber'];

            if ($value === '') {
                continue;
            }

            $keys[] = [
                'type' => 'card',
                'value' => $value,
            ];
        }

        return $keys;
    }

    public function getAccessPointDeviceType(string $driverId): ?string
    {
        $typeMap = [
            'TourniquetDriver' => 'Турникет',
            'OneSidedDoorDriver' => 'Дверь',
            'TwoSidedDoorDriver' => 'Дверь (2 стор.)',
            'GateDriver' => 'Ворота',
            'FaceRecognitionDriver' => 'Биометрия',
        ];

        $stmt = $this->pdo()->prepare('
            SELECT TOP 1 d.ObjectTypeName AS driverType
            FROM AcsAccessPoint ap
            INNER JOIN Driver d ON d._idResource = ap.DriverID
            WHERE ap.IsRemoved = 0
              AND d.ObjectTypeName IS NOT NULL
              AND CONVERT(varchar(36), ap.DriverID) = ?
        ');

        $stmt->execute([strtoupper($driverId)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false || $row['driverType'] === null) {
            return null;
        }

        preg_match('/\.(\w+),/', $row['driverType'], $m);

        return $typeMap[$m[1] ?? ''] ?? null;
    }

    /**
     * The full set of employees RusGuard currently considers active — not scoped to any
     * access point, unlike getAccessPointsWithEmployees()/getEmployeesForAccessPoint(). Used
     * to detect employees who've been synced locally in the past (because they once had
     * access to something) but have since disappeared from RusGuard entirely (fired, moved to
     * the excluded/departed-employees group, blocked) so they can be deactivated locally.
     *
     * Must stay in step with the point-scoped queries above: anything they exclude has to be
     * excluded here too, otherwise the employee is dropped from every turnstile but never
     * deactivated, and their local row lingers as active forever.
     *
     * @return array<int, string> lowercase uuids
     */
    public function getActiveEmployeeUuids(): array
    {
        $excludedGroupIds = array_map('strtoupper', config('rusguard.excluded_group_ids', []));
        $excludePlaceholders = $excludedGroupIds !== [] ? implode(',', array_fill(0, count($excludedGroupIds), '?')) : null;

        $excludeClause = $excludePlaceholders !== null
            ? "AND CONVERT(varchar(36), EmployeeGroupID) NOT IN ({$excludePlaceholders})"
            : '';

        $stmt = $this->pdo()->prepare("
            SELECT CONVERT(varchar(36), _id) AS uuid
            FROM Employee
            WHERE IsRemoved = 0
              AND IsLocked = 0
              {$excludeClause}
        ");
        $stmt->execute($excludedGroupIds);

        // See getEmployeesRequiringAlcoholTest() — normalize to lowercase to match Postgres'
        // native uuid column canonicalization for plain PHP array-key/set comparisons.
        return array_map(fn (string $uuid): string => strtolower($uuid), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @return array<int, string> AlcoGroup ids
     */
    private function getAlcoGroupIds(): array
    {
        return $this->pdo()->query('SELECT CONVERT(varchar(36), _id) AS id FROM AlcoGroup')->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Resolve the set of employees required to undergo alcohol testing, per RusGuard's
     * AlcoGroup configuration ("Назначить группу алкотестирования" — direct employee
     * assignment, employee-group assignment with optional recursive child-group inclusion,
     * and position assignment).
     *
     * Note: AlcoGroup.PeriodAlcoTesting/Priority are NOT used here — confirmed (2026-07-29,
     * via the RusGuard client UI itself) that PeriodAlcoTesting is in *days* and represents
     * RusGuard's own testing-frequency compliance cycle, not a "skip re-test for N minutes
     * after passing" grace window. That grace period is a concept we built on top of RusGuard,
     * not something RusGuard's own config expresses — see Setting::alcoholSkipGraceMinutes().
     *
     * @return array<string, true> uuid => true
     */
    public function getEmployeesRequiringAlcoholTest(): array
    {
        $result = [];

        foreach ($this->getAlcoGroupIds() as $groupId) {
            foreach ($this->getAlcoGroupMemberUuids($groupId) as $uuid) {
                // Postgres' native uuid column canonicalizes to lowercase, but SQL Server's
                // CONVERT(varchar(36), ...) preserves uppercase — normalize here so this map
                // matches cleanly against Employee::rusguard_uuid via plain PHP array lookups.
                $result[strtolower($uuid)] = true;
            }
        }

        return $result;
    }

    /**
     * @return array<int, string>
     */
    private function getAlcoGroupMemberUuids(string $alcoGroupId): array
    {
        $uuids = [];

        $stmt = $this->pdo()->prepare('SELECT CONVERT(varchar(36), EmployeeID) AS uuid FROM Employee2AlcoGroup WHERE AlcoGroupID = ?');
        $stmt->execute([$alcoGroupId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $uuid) {
            $uuids[$uuid] = true;
        }

        $stmt = $this->pdo()->prepare('SELECT CONVERT(varchar(36), EmployeeGroupID) AS groupId, IncludeChilds FROM AlcoGroup2EmployeeGroup WHERE AlcoGroupID = ?');
        $stmt->execute([$alcoGroupId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            foreach ($this->getEmployeeGroupMemberUuids($row['groupId'], (bool) $row['IncludeChilds']) as $uuid) {
                $uuids[$uuid] = true;
            }
        }

        $stmt = $this->pdo()->prepare('SELECT CONVERT(varchar(36), EmployeePositonID) AS positionId FROM AlcoGroup2EmployeePositon WHERE AlcoGroupID = ?');
        $stmt->execute([$alcoGroupId]);
        $positionIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $this->pdo()->prepare('SELECT PositionCode FROM AlcoGroup2PositionCode WHERE AlcoGroupID = ?');
        $stmt->execute([$alcoGroupId]);
        $positionCodes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if ($positionIds !== [] || $positionCodes !== []) {
            foreach ($this->getEmployeeUuidsByPosition($positionIds, $positionCodes) as $uuid) {
                $uuids[$uuid] = true;
            }
        }

        return array_keys($uuids);
    }

    /**
     * @return array<int, string>
     */
    private function getEmployeeGroupMemberUuids(string $groupId, bool $includeChilds): array
    {
        if ($includeChilds) {
            $sql = '
                WITH GroupTree AS (
                    SELECT _id FROM EmployeeGroup WHERE _id = ?
                    UNION ALL
                    SELECT eg._id
                    FROM EmployeeGroup eg
                    INNER JOIN GroupTree gt ON eg.ParentID = gt._id
                )
                SELECT CONVERT(varchar(36), e._id) AS uuid
                FROM Employee e
                WHERE e.IsRemoved = 0
                  AND e.EmployeeGroupID IN (SELECT _id FROM GroupTree)
            ';
        } else {
            $sql = '
                SELECT CONVERT(varchar(36), e._id) AS uuid
                FROM Employee e
                WHERE e.IsRemoved = 0
                  AND e.EmployeeGroupID = ?
            ';
        }

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute([$groupId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @param  array<int, string>  $positionIds
     * @param  array<int, string>  $positionCodes
     * @return array<int, string>
     */
    private function getEmployeeUuidsByPosition(array $positionIds, array $positionCodes): array
    {
        $conditions = [];
        $params = [];

        if ($positionIds !== []) {
            $placeholders = implode(',', array_fill(0, count($positionIds), '?'));
            $conditions[] = "CONVERT(varchar(36), e.EmployeePositionID) IN ({$placeholders})";
            $params = array_merge($params, $positionIds);
        }

        if ($positionCodes !== []) {
            $placeholders = implode(',', array_fill(0, count($positionCodes), '?'));
            $conditions[] = "p.PositionCode IN ({$placeholders})";
            $params = array_merge($params, $positionCodes);
        }

        if ($conditions === []) {
            return [];
        }

        $sql = '
            SELECT CONVERT(varchar(36), e._id) AS uuid
            FROM Employee e
            LEFT JOIN EmployeePosition p ON p._id = e.EmployeePositionID
            WHERE e.IsRemoved = 0
              AND ('.implode(' OR ', $conditions).')
        ';

        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * AuditData.MsgTypeId values worth reacting to: anything that can change who has access
     * to a point, what we push for them, or who's required to alcohol-test. Excludes purely
     * informational entries (operator login/logout, device commands, etc.) to avoid syncing on
     * unrelated activity.
     *
     * Keep this generous. It is the only low-latency trigger there is, and measuring the real
     * audit log showed how easily a gap hides here: 22 of 24 employee creations and 147 of 174
     * key changes over 30 days carried no other watched event within ten minutes of them, so
     * those people and cards only reached the terminals whenever something unrelated happened
     * to provoke a resync — once after three days. The hourly SyncAllJob is the actual
     * correctness floor; this list only decides how fast a change lands.
     *
     * @var array<int, int>
     */
    private const RELEVANT_AUDIT_MSG_TYPES = [
        6,  // Удаление сотрудника
        9,  // Блокировка сотрудника
        10, // Разблокировка сотрудника
        14, // Изменение ключа у сотрудника
        16, // Изменение данных сотрудника
        17, // Добавление сотрудника
        19, // Перемещение сотрудника в другую группу
        22, // Добавление уровня доступа сотруднику
        23, // Добавление уровня доступа группе сотрудников
        24, // Удаление уровня доступа у сотрудника
        25, // Удаление уровня доступа у группы сотрудников
        26, // Редактирование срока действия уровня доступа
        44, // Алкотестирование. Добавление группы
        45, // Алкотестирование. Удаление группы
        46, // Алкотестирование. Изменение приоритета
        47, // Алкотестирование. Изменение политики
        48, // Алкотестирование. Изменение способа назначения
        49, // Алкотестирование. Добавление должности в фильтр
        50, // Алкотестирование. Удаление должности
        51, // Алкотестирование. Добавление группы сотрудников
        52, // Алкотестирование. Удаление группы сотрудников
        53, // Алкотестирование. Добавление кода должности
        54, // Алкотестирование. Удаление кода должности
        55, // Алкотестирование. Добавление сотрудника
        56, // Алкотестирование. Удаление сотрудника
    ];

    /**
     * Highest AuditData._id currently in RusGuard — used to bootstrap the polling cursor
     * without replaying the entire audit history on first run.
     */
    public function getLatestAuditId(): int
    {
        $stmt = $this->pdo()->query('SELECT MAX(_id) AS maxId FROM AuditData');

        return (int) ($stmt->fetch(PDO::FETCH_ASSOC)['maxId'] ?? 0);
    }

    /**
     * Audit log entries after $lastId that could affect access-point membership or alcohol
     * test requirements (see RELEVANT_AUDIT_MSG_TYPES), oldest first.
     *
     * @return array<int, array{id: int, dateTime: string, msgTypeId: int, message: string, details: string, employeeUuid: ?string}>
     */
    public function getRelevantAuditEventsSince(int $lastId): array
    {
        $placeholders = implode(',', array_fill(0, count(self::RELEVANT_AUDIT_MSG_TYPES), '?'));

        $stmt = $this->pdo()->prepare("
            SELECT
                a._id                             AS id,
                CONVERT(varchar(30), a.DateTime, 126) AS dateTime,
                a.MsgTypeId                       AS msgTypeId,
                m.Message                         AS message,
                a.Details                         AS details,
                CONVERT(varchar(36), a.EmployeeId) AS employeeUuid
            FROM AuditData a
            LEFT JOIN AuditMsgTypes m ON m._id = a.MsgTypeId
            WHERE a._id > ?
              AND a.MsgTypeId IN ({$placeholders})
            ORDER BY a._id ASC
        ");

        $stmt->execute(array_merge([$lastId], self::RELEVANT_AUDIT_MSG_TYPES));

        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'dateTime' => $row['dateTime'],
            'msgTypeId' => (int) $row['msgTypeId'],
            'message' => (string) $row['message'],
            'details' => (string) $row['details'],
            'employeeUuid' => $row['employeeUuid'] !== null ? strtolower($row['employeeUuid']) : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, array{driverId: string, name: string, type: string}>
     */
    public function getAccessPoints(): array
    {
        $stmt = $this->pdo()->prepare("
            SELECT DISTINCT
                CONVERT(varchar(36), ap.DriverID) AS driverId,
                p.Value                           AS name,
                ISNULL(d.ObjectTypeName, '')      AS driverType,
                (
                    SELECT COUNT(DISTINCT e._id)
                    FROM Employee e
                    WHERE e.IsRemoved = 0
                      AND e.IsLocked = 0
                      AND (
                          EXISTS (
                              SELECT 1 FROM EmployeeAcsAccessLevel eal
                              INNER JOIN AcsAccessPoint ap2 ON ap2.AcsAccessLevelID = eal.AcsAccessLevelID
                              WHERE eal.EmployeeID = e._id
                                AND ap2.DriverID = ap.DriverID
                                AND (eal.EndDate IS NULL OR eal.EndDate > GETDATE())
                          )
                          OR (
                              e.IsAccessLevelsInherited = 1
                              AND EXISTS (
                                  SELECT 1 FROM EmployeeGroupAcsAccessLevel gal
                                  INNER JOIN AcsAccessPoint ap2 ON ap2.AcsAccessLevelID = gal.AcsAccessLevelID
                                  WHERE gal.EmployeeGroupID = e.EmployeeGroupID
                                    AND ap2.DriverID = ap.DriverID
                                    AND (gal.EndDate IS NULL OR gal.EndDate > GETDATE())
                              )
                          )
                      )
                ) AS employeeCount
            FROM AcsAccessPoint ap
            INNER JOIN Property p ON p._idResource = ap.DriverID AND p.PropertyName = ?
            LEFT JOIN Driver d ON d._idResource = ap.DriverID
            WHERE ap.IsRemoved = 0
        ");

        $stmt->execute(['Name']);

        $typeMap = [
            'TourniquetDriver' => 'Турникет',
            'OneSidedDoorDriver' => 'Дверь',
            'TwoSidedDoorDriver' => 'Дверь (2 стор.)',
            'GateDriver' => 'Ворота',
            'FaceRecognitionDriver' => 'Биометрия',
        ];

        $points = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rawType = $row['driverType'] ?? '';
            preg_match('/\.(\w+),/', $rawType, $m);
            $typeKey = ($m[1] ?? '').'';
            $type = $typeMap[$typeKey] ?? '';

            $points[] = [
                'driverId' => $row['driverId'],
                'name' => $row['name'] ?? '',
                'type' => $type,
                'employeeCount' => (int) $row['employeeCount'],
            ];
        }

        return $points;
    }
}
