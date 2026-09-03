<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\SyncLog;
use App\Models\SyncRun;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

class HikvisionSyncService
{
    public const SYNC_STATUS_KEY = 'hikvision_sync_status';

    private const LOG_FLUSH_THRESHOLD = 200;

    /** Signature standing in for "there is no photo to push at all". */
    public const NO_LOCAL_PHOTO = 'no-local-photo';

    /** @var array<int, array<string, mixed>> */
    private array $pendingLogs = [];

    public function __construct(private readonly RusGuardDatabaseService $rusGuardDb) {}

    /**
     * Sync all employees from access points linked to this terminal.
     * Adds new employees, updates existing ones, removes those no longer linked.
     *
     * @return array{synced: int, removed: int, errors: int, faces: int, cards: int, guestsSkipped: int, alcoholRequired: int, alcoholSkipped: int, alcoholFailed: int, removalSkipped: string|null}
     */
    public function syncEmployeesForTerminal(HikvisionTerminal $terminal, string $triggeredBy = SyncRun::TRIGGER_MANUAL): array
    {
        return SyncRun::track(
            SyncRun::KIND_HIKVISION,
            $triggeredBy,
            ['hikvision_terminal_id' => $terminal->id],
            fn (): array => $this->pushEmployeesToTerminal($terminal),
        );
    }

    /**
     * @return array{synced: int, removed: int, errors: int, faces: int, cards: int, guestsSkipped: int, alcoholRequired: int, alcoholSkipped: int, alcoholFailed: int, removalSkipped: string|null}
     */
    private function pushEmployeesToTerminal(HikvisionTerminal $terminal): array
    {
        $service = new HikvisionService($terminal);

        if (! $service->isOnline()) {
            Cache::put(self::SYNC_STATUS_KEY.'_'.$terminal->id, [
                'status' => 'failed',
                'terminal' => $terminal->name,
                'done' => 0,
                'total' => 0,
                'synced' => 0,
                'removed' => 0,
                'errors' => 0,
                'message' => 'Terminal is offline or unreachable',
            ], now()->addHour());

            throw new \RuntimeException("Terminal [{$terminal->name}] is offline or unreachable at {$terminal->ip}");
        }

        $terminal->loadMissing(['accessPoint.employees.keys']);

        $employees = $terminal->accessPoint
            ?->employees
            ->unique('id')
            ->values()
            ?? collect();

        $total = $employees->count();

        // Prefetch terminal state once to avoid redundant API calls per employee
        Cache::put(self::SYNC_STATUS_KEY.'_'.$terminal->id, [
            'status' => 'running',
            'terminal' => $terminal->name,
            'done' => 0,
            'total' => $total,
            'synced' => 0,
            'removed' => 0,
            'errors' => 0,
            'message' => 'Fetching terminal state…',
        ], now()->addHour());

        $allPersons = $service->allPersons();
        $terminalCards = $service->allCards();
        $terminalFaces = $service->empCodesWithFace();
        $terminalPersons = collect($allPersons)
            ->filter(fn ($p) => ($p['employeeNo'] ?? '') !== '')
            ->keyBy(fn ($p) => (string) $p['employeeNo']);

        // Alcohol testing (custom project DZP20260604103): who currently must test is
        // resolved fresh from RusGuard's "Назначить группу алкотестирования" (AlcoGroup)
        // config on every sync — not cached locally — so RusGuard stays the single source of
        // truth for group membership. The post-pass grace/skip period, however, is NOT read
        // from RusGuard (AlcoGroup.PeriodAlcoTesting is an unrelated days-based compliance
        // cycle) — see Setting::alcoholSkipGraceMinutes(), configurable from the /alcohol page.
        $alcoholEnabled = (bool) ($terminal->resolvedAlcoholParams()['enabled'] ?? false);
        $alcoholRequired = $alcoholEnabled ? $this->rusGuardDb->getEmployeesRequiringAlcoholTest() : [];

        $results = [
            'synced' => 0, 'removed' => 0, 'errors' => 0, 'faces' => 0, 'cards' => 0, 'guestsSkipped' => 0,
            'removalSkipped' => null,
            'alcoholRequired' => 0, 'alcoholSkipped' => 0, 'alcoholFailed' => 0,
        ];
        $syncedCodes = [];
        $personsFailed = [];
        $facesFailed = [];
        $cardsFailed = [];

        // emp_code => photo signature that already failed to reach this terminal. Carried
        // across runs so a permanently rejected photo is neither retried nor re-logged until
        // the photo changes; anything that resolves simply stops being written back.
        $knownFaceProblems = $terminal->sync_stats['face_problems'] ?? [];
        $faceProblems = [];

        foreach ($employees as $i => $employee) {
            if ($i % 10 === 0 || $i === $total - 1) {
                Cache::put(self::SYNC_STATUS_KEY.'_'.$terminal->id, [
                    'status' => 'running',
                    'terminal' => $terminal->name,
                    'done' => $i,
                    'total' => $total,
                    'synced' => $results['synced'],
                    'removed' => $results['removed'],
                    'errors' => $results['errors'],
                ], now()->addHour());
            }

            $empCodeStr = (string) $employee->emp_code;

            try {
                // Person: add if missing, or re-apply if the name on the terminal is stale
                // (UserInfo/SetUp is an upsert, so this also fixes name drift).
                $terminalPerson = $terminalPersons->get($empCodeStr);
                $expectedName = mb_substr($employee->full_name, 0, 32);
                $nameDrifted = $terminalPerson !== null && ($terminalPerson['name'] ?? null) !== $expectedName;
                $forceFace = false;

                if ($terminalPerson === null || $nameDrifted) {
                    $service->addEmployee($employee);

                    if ($nameDrifted) {
                        // A slot that already held a *different* name is not a normal new-hire add:
                        // either the firmware dropped an earlier write, or something else is writing
                        // this device. Confirm the correction actually stuck rather than logging a
                        // blind "success" — a silently-dropped write here is how a person ends up
                        // authenticating as a colleague. Also force a face re-upload: a drifted
                        // person record means the face at this FPID likely belongs to whoever
                        // previously held the slot, and the "face already exists" skip below would
                        // keep it forever.
                        $forceFace = true;
                        $after = $service->fetchUserInfo($empCodeStr);

                        if (($after['name'] ?? null) !== $expectedName) {
                            $this->log($employee->id, $terminal->id, 'hikvision_add', 'error',
                                'Name still wrong after re-push — terminal has ['.($after['name'] ?? '?').'], expected ['.$expectedName.']');
                        } else {
                            $this->log($employee->id, $terminal->id, 'hikvision_add', 'success',
                                'Name drift corrected ['.($terminalPerson['name'] ?? '?').' -> '.$expectedName.'] on '.$terminal->name);
                        }
                    } else {
                        $this->log($employee->id, $terminal->id, 'hikvision_add', 'success', 'Added to '.$terminal->name);
                    }
                }

                $syncedCodes[] = $empCodeStr;
                $results['synced']++;

                // Card: compare the full set of local cards against the terminal's, only
                // write when the sets differ (order-independent).
                $cardKeys = $employee->keys->where('type', 'card');
                $expectedCardNos = $cardKeys
                    ->map(fn ($key) => str_pad((string) $key->value, 10, '0', STR_PAD_LEFT))
                    ->values()
                    ->all();
                $terminalCardNos = $terminalCards[$empCodeStr] ?? [];
                $hasCard = false;

                if ($expectedCardNos === []) {
                    $this->log($employee->id, $terminal->id, 'hikvision_card', 'error', 'No card keys in local DB — skipped');
                } elseif ($this->cardSetsMatch($expectedCardNos, $terminalCardNos)) {
                    $hasCard = true; // cards unchanged — skip write
                } else {
                    try {
                        if ($terminalCardNos !== []) {
                            $service->deleteCards($empCodeStr);
                        }
                        foreach ($expectedCardNos as $cardNo) {
                            $service->addCard($empCodeStr, $cardNo);
                        }
                        $this->log($employee->id, $terminal->id, 'hikvision_card', 'success', 'Card(s) '.implode(', ', $expectedCardNos).' added');
                        $terminalCards[$empCodeStr] = $expectedCardNos;
                        $hasCard = true;
                    } catch (Throwable $e) {
                        $this->log($employee->id, $terminal->id, 'hikvision_card', 'error', $e->getMessage());
                    }
                }

                // Face: skip upload only if both the face model and its picture are on the
                // terminal. A model alone isn't enough — persons enrolled while the device's
                // "Save Registered Picture" option was off keep a working model (recognition
                // passes) but no stored JPEG, which is why the terminal's own web UI showed
                // them with an empty avatar. The person record exposes a faceURL only once a
                // picture is actually stored, so an empty one means "re-upload me", and
                // uploadFace() now replaces an existing model in place via FDModify.
                $hasFace = false;
                $isGuest = false;
                $hasStoredPicture = ($terminalPerson['faceURL'] ?? '') !== '';

                if (isset($terminalFaces[$empCodeStr]) && $hasStoredPicture && ! $forceFace) {
                    $hasFace = true; // face and picture unchanged — skip write
                } elseif ($employee->photo_path === null) {
                    if ($this->isLikelyGuestName($employee->full_name)) {
                        // Placeholder/guest badge record (e.g. "Гость 44 ресепшн") — never
                        // has a real photo, so don't count it as a genuine sync failure.
                        $isGuest = true;
                    } else {
                        $this->logFaceProblem($employee, $terminal, self::NO_LOCAL_PHOTO, 'No photo in local DB — skipped', $knownFaceProblems, $faceProblems);
                    }
                } else {
                    // Retrying a photo the terminal has already refused just fails the same way
                    // on every run: 33 employees produced 825 identical error rows in six hours
                    // and 66 wasted uploads per pass. Attempt it again only once the photo
                    // itself changes — which is the only thing that could alter the outcome.
                    $photoSignature = $this->photoSignature($employee);

                    if (! $forceFace && ($knownFaceProblems[$empCodeStr] ?? null) === $photoSignature) {
                        $faceProblems[$empCodeStr] = $photoSignature;
                    } else {
                        try {
                            $service->uploadFace($employee);
                            $this->log($employee->id, $terminal->id, 'hikvision_face', 'success', 'Face uploaded');
                            $terminalFaces[$empCodeStr] = true;
                            $hasFace = true;
                        } catch (Throwable $e) {
                            $this->logFaceProblem($employee, $terminal, $photoSignature, $e->getMessage(), $knownFaceProblems, $faceProblems);
                        }
                    }
                }

                if ($hasFace) {
                    $results['faces']++;
                } elseif ($isGuest) {
                    $results['guestsSkipped']++;
                } else {
                    $facesFailed[] = ['emp_code' => $employee->emp_code, 'name' => $employee->full_name];
                }

                if ($hasCard) {
                    $results['cards']++;
                } else {
                    $cardsFailed[] = ['emp_code' => $employee->emp_code, 'name' => $employee->full_name];
                }

                if ($alcoholEnabled) {
                    // Grace period from a recent pass overrides the group requirement — don't
                    // clobber an active skip with "must test" mid-window.
                    $mustTest = isset($alcoholRequired[$employee->rusguard_uuid]) && ! $employee->isAlcoholSkipActive();
                    $desiredFlag = $mustTest ? '' : HikvisionService::ALCOHOL_SKIP_FLAG;

                    // Compare before writing, like the person/card/face branches above. The
                    // flag comes back in the person list already prefetched for this run, and
                    // it survives the UserInfo/SetUp that addEmployee() issues (that call omits
                    // the field and the terminal keeps it), so the prefetched value is still
                    // accurate here. Writing unconditionally cost one PUT per employee on every
                    // run — on 668 people that alone was ~137s of an otherwise no-op sync, and
                    // it multiplied by terminal count.
                    $flagMatches = $this->alcoholFlagOf($terminalPerson) === $desiredFlag;

                    if ($flagMatches || $service->setAlcoholSkip($empCodeStr, ! $mustTest)) {
                        $mustTest ? $results['alcoholRequired']++ : $results['alcoholSkipped']++;
                    } else {
                        $results['alcoholFailed']++;
                        $this->log($employee->id, $terminal->id, 'hikvision_alcohol', 'error', 'Failed to set alcohol skip flag');
                    }
                }
            } catch (Throwable $e) {
                $results['errors']++;
                $personsFailed[] = ['emp_code' => $employee->emp_code, 'name' => $employee->full_name];
                $this->log($employee->id, $terminal->id, 'hikvision_add', 'error', $e->getMessage());
            }
        }

        // Employees who must test per RusGuard but never made it onto this terminal as a
        // person — there's no physical enforcement point for them until this is fixed.
        $alcoRequiredMissing = [];

        if ($alcoholEnabled) {
            $confirmedOnTerminal = array_flip($syncedCodes);

            foreach ($employees as $employee) {
                $empCodeStr = (string) $employee->emp_code;

                if (isset($alcoholRequired[$employee->rusguard_uuid]) && ! isset($confirmedOnTerminal[$empCodeStr])) {
                    $alcoRequiredMissing[] = ['emp_code' => $employee->emp_code, 'name' => $employee->full_name];
                }
            }
        }

        // Remove persons on the terminal that are no longer linked. Reuses the person
        // list already fetched above instead of re-scanning the whole terminal.
        //
        // Only ever act on a roster we trust. An empty one does not mean "nobody belongs on
        // this device" — it is what a terminal bound to a detached or deactivated access point
        // looks like, and removal would then wipe every person off working hardware. That is
        // reachable in practice: when a point's driverId changes in RusGuard the sync creates a
        // fresh access point and deactivates the old one, leaving any terminal still bound to the
        // old row reading an empty, no-longer-updated pivot.
        $roster = $this->rosterTrustProblem($terminal, $employees->count());

        if ($roster !== null) {
            $results['removed'] = 0;
            $results['removalSkipped'] = $roster;
            $this->log(null, $terminal->id, 'hikvision_remove', 'error', 'Removal skipped — '.$roster);
        } else {
            $results['removed'] = $this->removeUnlinkedPersons($service, $terminal, $allPersons, $syncedCodes);
        }

        $this->flushLogs();

        Cache::put(self::SYNC_STATUS_KEY.'_'.$terminal->id, [
            'status' => 'done',
            'terminal' => $terminal->name,
            'done' => $total,
            'total' => $total,
            'synced' => $results['synced'],
            'removed' => $results['removed'],
            'errors' => $results['errors'],
        ], now()->addHour());

        $terminal->update([
            'sync_stats' => [
                'persons_added' => $results['synced'],
                'persons_not_added' => $total - $results['synced'],
                'persons_failed' => $personsFailed,
                'faces_added' => $results['faces'],
                'faces_not_added' => $results['synced'] - $results['faces'] - $results['guestsSkipped'],
                'faces_failed' => $facesFailed,
                'guests_skipped' => $results['guestsSkipped'],
                'cards_added' => $results['cards'],
                'cards_not_added' => $results['synced'] - $results['cards'],
                'cards_failed' => $cardsFailed,
                'face_problems' => $faceProblems,
                'removal_skipped' => $results['removalSkipped'],
                'alcohol_enabled' => $alcoholEnabled,
                'alcohol_required' => $results['alcoholRequired'],
                'alcohol_skipped' => $results['alcoholSkipped'],
                'alcohol_failed' => $results['alcoholFailed'],
                'alco_required_missing_from_terminal' => $alcoRequiredMissing,
                'synced_at' => now()->toDateTimeString(),
            ],
        ]);

        return $results;
    }

    /**
     * Push a single employee (person + cards + face) to the terminal.
     *
     * @return array{face: bool, card: bool}
     */
    public function pushEmployee(HikvisionService $service, Employee $employee, HikvisionTerminal $terminal): array
    {
        $service->addEmployee($employee);
        $this->log($employee->id, $terminal->id, 'hikvision_add', 'success', 'Added to '.$terminal->name);

        $hasCard = false;
        $hasFace = false;

        // Sync cards: clear existing first so stale card numbers don't persist
        $cardKeys = $employee->keys->where('type', 'card');

        if ($cardKeys->isEmpty()) {
            $this->log($employee->id, $terminal->id, 'hikvision_card', 'error', 'No card keys in local DB — skipped');
        } else {
            try {
                $service->deleteCards((string) $employee->emp_code);
            } catch (Throwable) {
                // Non-fatal — proceed to add
            }

            foreach ($cardKeys as $key) {
                try {
                    $cardNo = str_pad($key->value, 10, '0', STR_PAD_LEFT);
                    $service->addCard((string) $employee->emp_code, $cardNo);
                    $this->log($employee->id, $terminal->id, 'hikvision_card', 'success', 'Card '.$cardNo.' added');
                    $hasCard = true;
                } catch (Throwable $e) {
                    $this->log($employee->id, $terminal->id, 'hikvision_card', 'error', $e->getMessage());
                }
            }
        }

        // Upload face photo
        if ($employee->photo_path === null) {
            $this->log($employee->id, $terminal->id, 'hikvision_face', 'error', 'No photo in local DB — skipped');
        } else {
            try {
                $service->uploadFace($employee);
                $this->log($employee->id, $terminal->id, 'hikvision_face', 'success', 'Face uploaded');
                $hasFace = true;
            } catch (Throwable $e) {
                $this->log($employee->id, $terminal->id, 'hikvision_face', 'error', $e->getMessage());
            }
        }

        $this->flushLogs();

        return ['face' => $hasFace, 'card' => $hasCard];
    }

    /**
     * Remove a single employee from the terminal.
     */
    public function removeEmployee(HikvisionService $service, Employee $employee, HikvisionTerminal $terminal): void
    {
        $service->deleteEmployee($employee);
        $this->log($employee->id, $terminal->id, 'hikvision_remove', 'success', 'Removed from '.$terminal->name);
        $this->flushLogs();
    }

    /**
     * Whether two card-number sets are the same, regardless of order.
     *
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     */
    private function cardSetsMatch(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Heuristic for guest/reception badge placeholder records synced from RusGuard
     * (e.g. "Гость 44 ресепшн", "Гость3 ПОСТ 1") — these are ordinary Employee rows with
     * no employee "type" column to distinguish them, but never get a real photo. Real
     * staff names don't contain digits; guest/placeholder names reliably do.
     */
    private function isLikelyGuestName(string $name): bool
    {
        return preg_match('/\d/u', $name) === 1;
    }

    /**
     * Remove persons from terminal that are no longer in the linked employee list.
     * Expects the terminal's full person list (as returned by HikvisionService::allPersons())
     * so it doesn't need to re-scan the terminal.
     *
     * @param  array<int, array<string, mixed>>  $allPersons
     * @param  array<int, string>  $keepEmpCodes
     */
    /**
     * Identifies the photo we are about to push, so a face problem can be remembered against
     * the exact image that caused it. Falls back to the path when the file can't be hashed.
     */
    private function photoSignature(Employee $employee): string
    {
        $path = storage_path('app/'.$employee->photo_path);

        return is_readable($path) ? (md5_file($path) ?: $employee->photo_path) : (string) $employee->photo_path;
    }

    /**
     * Record a face problem, logging it only when it is new or the photo has changed.
     *
     * Some of these never resolve on their own — the terminal refuses a handful of photos with
     * 'alreadyExistThisFace' no matter what is sent, including under a brand-new employee
     * number — so logging every pass buried genuinely new failures under hundreds of repeats.
     *
     * @param  array<string, string>  $known  signatures carried over from the previous run
     * @param  array<string, string>  $current  signatures being collected for the next one
     */
    private function logFaceProblem(
        Employee $employee,
        HikvisionTerminal $terminal,
        string $signature,
        string $message,
        array $known,
        array &$current,
    ): void {
        $empCodeStr = (string) $employee->emp_code;
        $current[$empCodeStr] = $signature;

        if (($known[$empCodeStr] ?? null) !== $signature) {
            $this->log($employee->id, $terminal->id, 'hikvision_face', 'error', $message);
        }
    }

    /**
     * Why this run's employee roster can't be trusted to drive deletions, or null when it can.
     *
     * Deleting people is the only irreversible thing this sync does, so it is gated on the
     * roster being meaningful rather than merely present.
     */
    private function rosterTrustProblem(HikvisionTerminal $terminal, int $rosterSize): ?string
    {
        $accessPoint = $terminal->accessPoint;

        if ($accessPoint === null) {
            return 'terminal is not linked to an access point';
        }

        if (! $accessPoint->is_active) {
            return 'access point ['.$accessPoint->name.'] is deactivated, so its employee list is no longer maintained';
        }

        if ($rosterSize === 0) {
            return 'access point ['.$accessPoint->name.'] has no employees linked';
        }

        return null;
    }

    /**
     * Alcohol-skip flag currently stored for a person on the terminal, as returned by
     * UserInfo/Search. Null when the person isn't on the terminal yet or the field is absent,
     * which never equals a desired value and so always forces a write.
     *
     * @param  array<string, mixed>|null  $terminalPerson
     */
    private function alcoholFlagOf(?array $terminalPerson): ?string
    {
        $value = $terminalPerson['PersonInfoExtends'][0]['value'] ?? null;

        return is_string($value) ? $value : null;
    }

    private function removeUnlinkedPersons(HikvisionService $service, HikvisionTerminal $terminal, array $allPersons, array $keepEmpCodes): int
    {
        $keep = array_flip($keepEmpCodes);
        $toRemove = [];

        foreach ($allPersons as $person) {
            $empCode = (string) ($person['employeeNo'] ?? '');
            if ($empCode !== '' && ! isset($keep[$empCode])) {
                $toRemove[] = $empCode;
            }
        }

        if ($toRemove === []) {
            return 0;
        }

        $localEmployees = Employee::whereIn('emp_code', array_map('intval', $toRemove))
            ->get()
            ->keyBy(fn (Employee $e) => (string) $e->emp_code);

        $removed = 0;

        foreach ($toRemove as $empCode) {
            $employee = $localEmployees->get($empCode);

            try {
                if ($employee !== null) {
                    $service->deleteEmployee($employee);
                    $this->log($employee->id, $terminal->id, 'hikvision_remove', 'success', 'Removed from '.$terminal->name);
                } else {
                    $service->deleteByEmpCode($empCode);
                }
                $removed++;
            } catch (Throwable $e) {
                $this->log($employee?->id, $terminal->id, 'hikvision_remove', 'error', $e->getMessage());
            }
        }

        return $removed;
    }

    private function log(?int $employeeId, int $terminalId, string $action, string $status, ?string $message = null): void
    {
        $now = Carbon::now();

        $this->pendingLogs[] = [
            'employee_id' => $employeeId,
            'hikvision_terminal_id' => $terminalId,
            'action' => $action,
            'status' => $status,
            'message' => $message,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if (count($this->pendingLogs) >= self::LOG_FLUSH_THRESHOLD) {
            $this->flushLogs();
        }
    }

    private function flushLogs(): void
    {
        if ($this->pendingLogs === []) {
            return;
        }

        SyncLog::insert($this->pendingLogs);
        $this->pendingLogs = [];
    }
}
