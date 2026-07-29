<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Turnstile;
use App\Services\HikvisionService;
use App\Services\HikvisionSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class HikvisionTerminalController extends Controller
{
    public function index(): View
    {
        $terminals = HikvisionTerminal::withCount('syncLogs')
            ->with('turnstile:id,name,rusguard_access_point_name')
            ->latest()
            ->paginate(20);

        return view('hikvision.index', compact('terminals'));
    }

    public function create(): View
    {
        $turnstiles = Turnstile::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'rusguard_access_point_name']);

        return view('hikvision.create', compact('turnstiles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'string', 'max:45'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'protocol' => ['required', 'in:http,https'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'access_point_id' => ['nullable', 'integer', 'exists:access_points,id'],
        ]);

        HikvisionTerminal::create($validated);

        return redirect()->route('hikvision.index')->with('success', 'Terminal created.');
    }

    public function edit(HikvisionTerminal $hikvision): View
    {
        $turnstiles = Turnstile::where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'rusguard_access_point_name']);

        return view('hikvision.edit', compact('hikvision', 'turnstiles'));
    }

    public function update(Request $request, HikvisionTerminal $hikvision): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'string', 'max:45'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'protocol' => ['required', 'in:http,https'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'access_point_id' => ['nullable', 'integer', 'exists:access_points,id'],
        ]);

        // Only update password if a new one was provided
        if (empty($validated['password'])) {
            unset($validated['password']);
        }

        $hikvision->update($validated);

        return redirect()->route('hikvision.index')->with('success', 'Terminal updated.');
    }

    public function destroy(HikvisionTerminal $hikvision): RedirectResponse
    {
        $hikvision->delete();

        return redirect()->route('hikvision.index')->with('success', 'Terminal deleted.');
    }

    public function employees(HikvisionTerminal $hikvision): JsonResponse
    {
        $service = new HikvisionService($hikvision);

        // Fetch persons actually stored on the terminal
        $persons = $service->allPersons();

        if (empty($persons)) {
            return response()->json([]);
        }

        // Fetch cards and face data stored on the terminal.
        // Normalize keys by stripping leading zeros to handle terminals that
        // return employeeNo in different formats across endpoints (e.g. "333" vs "000000333").
        $normalizeEmpNo = static fn (string $no): string => ltrim($no, '0') ?: '0';

        $cardsByEmpNo = collect($service->allCards())
            ->mapWithKeys(fn ($cardNos, $empNo) => [$normalizeEmpNo($empNo) => $cardNos[0] ?? null])
            ->all();

        $faceEmpCodes = collect($service->empCodesWithFace())
            ->mapWithKeys(fn ($v, $k) => [$normalizeEmpNo($k) => $v])
            ->all();

        $result = collect($persons)
            ->filter(fn ($p) => ($p['employeeNo'] ?? '') !== '')
            ->map(function (array $p) use ($cardsByEmpNo, $faceEmpCodes, $normalizeEmpNo) {
                $code = (string) ($p['employeeNo'] ?? '');
                $norm = $normalizeEmpNo($code);

                return [
                    'emp_code' => $code,
                    'full_name' => $p['name'] ?? $code,
                    'card_no' => $cardsByEmpNo[$norm] ?? null,
                    'has_face' => isset($faceEmpCodes[$norm]),
                ];
            })
            ->sortBy('full_name')
            ->values();

        return response()->json($result);
    }

    public function deleteAllEmployees(HikvisionTerminal $hikvision): JsonResponse
    {
        $service = new HikvisionService($hikvision);
        $persons = $service->allPersons();
        $deleted = 0;
        $errors = 0;

        foreach ($persons as $person) {
            $empCode = (string) ($person['employeeNo'] ?? '');

            if ($empCode === '') {
                continue;
            }

            try {
                $service->deleteByEmpCode($empCode);
                $deleted++;
            } catch (Throwable) {
                $errors++;
            }
        }

        return response()->json(['deleted' => $deleted, 'errors' => $errors]);
    }

    public function resyncEmployee(Request $request, HikvisionTerminal $hikvision, HikvisionSyncService $syncService): JsonResponse
    {
        $validated = $request->validate([
            'emp_code' => ['required', 'string'],
        ]);

        $employee = Employee::where('emp_code', (int) $validated['emp_code'])
            ->with('keys')
            ->first();

        if (! $employee) {
            return response()->json(['error' => 'Employee not found in local database'], 404);
        }

        if (! $employee->is_active) {
            return response()->json(['error' => 'Employee is no longer active in RusGuard — refusing to re-push access'], 422);
        }

        try {
            $service = new HikvisionService($hikvision);
            $pushed = $syncService->pushEmployee($service, $employee, $hikvision);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $this->removeSyncStatsError($hikvision, $employee->emp_code, $pushed);

        $cardKey = $employee->keys->firstWhere('type', 'card');
        $cardNo = $cardKey ? str_pad((string) $cardKey->value, 10, '0', STR_PAD_LEFT) : null;

        return response()->json([
            'success' => true,
            'card_no' => $cardNo,
            'has_face' => $employee->photo_path !== null,
        ]);
    }

    /**
     * Remove an employee from sync_stats failed lists after a successful manual resync.
     *
     * @param  array{face: bool, card: bool}  $pushed
     */
    private function removeSyncStatsError(HikvisionTerminal $terminal, int $empCode, array $pushed): void
    {
        $stats = $terminal->sync_stats;

        if (! $stats) {
            return;
        }

        $remove = fn (array $list) => array_values(
            array_filter($list, fn ($e) => (int) $e['emp_code'] !== $empCode)
        );

        $stats['persons_failed'] = $remove($stats['persons_failed'] ?? []);
        $stats['persons_not_added'] = count($stats['persons_failed']);

        if ($pushed['face']) {
            $stats['faces_failed'] = $remove($stats['faces_failed'] ?? []);
            $stats['faces_not_added'] = count($stats['faces_failed']);
        }

        if ($pushed['card']) {
            $stats['cards_failed'] = $remove($stats['cards_failed'] ?? []);
            $stats['cards_not_added'] = count($stats['cards_failed']);
        }

        $terminal->update(['sync_stats' => $stats]);
    }

    public function linkTurnstile(Request $request, HikvisionTerminal $hikvision): JsonResponse
    {
        $validated = $request->validate([
            'access_point_id' => ['required', 'integer', 'exists:access_points,id'],
        ]);

        $hikvision->update(['access_point_id' => $validated['access_point_id']]);

        return response()->json(['success' => true]);
    }

    public function alcoholSettings(HikvisionTerminal $hikvision): View
    {
        $service = new HikvisionService($hikvision);

        $terminalOnline = $service->isOnline();
        $liveParams = $terminalOnline ? $service->getAlcoholDetectionParams() : null;
        $livePlan = ($terminalOnline && $liveParams !== null) ? $service->getAlcoholWeekPlan() : null;
        $moduleDetected = $liveParams !== null;

        if ($moduleDetected) {
            $stored = $hikvision->resolvedAlcoholParams();

            // getAlcoholWeekPlan() only returns days that have periods on the terminal;
            // a day absent from $livePlan is disabled there, not "keep the stored value".
            $weekPlan = $stored['weekPlan'];
            if ($livePlan !== null) {
                foreach (array_keys($weekPlan) as $day) {
                    $weekPlan[$day] = $livePlan[$day] ?? ['enabled' => false, 'periods' => []];
                }
            }

            $merged = array_replace($stored, [
                'enabled'              => $liveParams['enabled'] ?? $stored['enabled'],
                'drinkingThreshold'    => $liveParams['alcoholThreshold']['drinkingThreshold'] ?? $stored['drinkingThreshold'],
                'drunkennessThreshold' => $liveParams['alcoholThreshold']['drunkennessThreshold'] ?? $stored['drunkennessThreshold'],
                'timeout'              => $liveParams['timeout'] ?? $stored['timeout'],
                'weekPlan'             => $weekPlan,
            ]);
            $hikvision->update(['alcohol_params' => $merged]);
            $hikvision->refresh();
        }

        $params = $hikvision->resolvedAlcoholParams();

        return view('hikvision.alcohol', compact('hikvision', 'params', 'terminalOnline', 'moduleDetected'));
    }

    public function toggleAlcohol(HikvisionTerminal $hikvision): JsonResponse
    {
        $service = new HikvisionService($hikvision);
        $live = $service->getAlcoholDetectionParams();

        $enabled = $live !== null ? ! $live['enabled'] : ! $hikvision->resolvedAlcoholParams()['enabled'];

        // The terminal's PUT applies the full params object, not a partial merge, so the
        // toggle must round-trip the live params rather than reconstruct them from local fields.
        $pushed = false;
        if ($live !== null) {
            $live['enabled'] = $enabled;
            $pushed = $service->setAlcoholDetectionParams($live);
        }

        $params = $hikvision->resolvedAlcoholParams();
        $params['enabled'] = $enabled;
        $hikvision->update(['alcohol_params' => $params]);

        return response()->json([
            'enabled' => $enabled,
            'pushed'  => $pushed,
        ]);
    }

    public function updateAlcoholSettings(Request $request, HikvisionTerminal $hikvision): RedirectResponse
    {
        $validated = $request->validate([
            'drinkingThreshold'             => ['required', 'integer', 'min:0', 'max:439'],
            'drunkennessThreshold'          => ['required', 'integer', 'min:1', 'max:440'],
            'timeout'                       => ['required', 'integer', 'min:3', 'max:60'],
            'weekPlan'                      => ['nullable', 'array'],
            'weekPlan.*.enabled'            => ['boolean'],
            'weekPlan.*.periods'            => ['nullable', 'array'],
            'weekPlan.*.periods.*.beginTime' => ['required', 'date_format:H:i'],
            'weekPlan.*.periods.*.endTime'   => ['required', 'date_format:H:i'],
        ]);

        $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
        $weekPlanInput = $request->input('weekPlan', []);
        $weekPlan = [];

        foreach ($days as $day) {
            $dayData = $weekPlanInput[$day] ?? [];
            $periods = [];

            foreach ($dayData['periods'] ?? [] as $period) {
                if (empty($period['beginTime']) || empty($period['endTime'])) {
                    continue;
                }
                $periods[] = [
                    'beginTime' => $period['beginTime'],
                    'endTime'   => $period['endTime'],
                ];
            }

            $weekPlan[$day] = [
                'enabled' => isset($dayData['enabled']) && $periods !== [],
                'periods' => $periods,
            ];
        }

        $service = new HikvisionService($hikvision);
        $liveParams = $service->getAlcoholDetectionParams();

        // 'enabled' is controlled by the separate toggle, not this form — preserve whatever
        // the device currently reports rather than defaulting it to false.
        $params = $hikvision->resolvedAlcoholParams();
        $params['drinkingThreshold'] = (int) $validated['drinkingThreshold'];
        $params['drunkennessThreshold'] = (int) $validated['drunkennessThreshold'];
        $params['timeout'] = (int) $validated['timeout'];
        $params['weekPlan'] = $weekPlan;
        if ($liveParams !== null) {
            $params['enabled'] = $liveParams['enabled'];
        }

        $hikvision->update(['alcohol_params' => $params]);

        $pushOk = false;

        // The terminal's PUT applies the full params object, not a partial merge, so the
        // live params are round-tripped rather than reconstructed from local fields alone.
        if ($liveParams !== null) {
            $liveParams['alcoholThreshold']['drinkingThreshold'] = $params['drinkingThreshold'];
            $liveParams['alcoholThreshold']['drunkennessThreshold'] = $params['drunkennessThreshold'];
            $liveParams['timeout'] = $params['timeout'];

            $pushOk = $service->setAlcoholDetectionParams($liveParams);
        }

        if (! $service->setAlcoholWeekPlan($weekPlan)) {
            $pushOk = false;
        }

        $message = $pushOk
            ? 'Alcohol settings saved and pushed to terminal.'
            : 'Settings saved locally. Terminal push failed — module may not be connected.';

        return redirect()->route('hikvision.alcohol', $hikvision)
            ->with($pushOk ? 'success' : 'error', $message);
    }

    public function checkConnection(HikvisionTerminal $hikvision): JsonResponse
    {
        try {
            $service = new HikvisionService($hikvision);
            $online = $service->isOnline();
            $personCount = $online ? $service->personCount() : null;

            return response()->json([
                'online' => $online,
                'person_count' => $personCount,
            ]);
        } catch (Throwable $e) {
            return response()->json(['online' => false, 'error' => $e->getMessage()]);
        }
    }
}
