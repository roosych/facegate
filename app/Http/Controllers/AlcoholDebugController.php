<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionEventIngestService;
use App\Services\HikvisionService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Live device+DB state for one employee, plus a manual skip-reset button — built to hand to
 * Hikvision's firmware engineer as evidence that the app-side state (DB flag, pushed
 * PersonInfoExtends) is clean when the terminal still silently stops prompting for a breath test.
 */
class AlcoholDebugController extends Controller
{
    public function __construct(
        private readonly RusGuardDatabaseService $rusGuardDb,
        private readonly HikvisionEventIngestService $ingestService,
    ) {}

    public function show(Employee $employee): View
    {
        return view('alcohol.debug', [
            'employee' => $employee,
            'terminal' => $employee->alcoholEnabledTerminals()->first(),
            'snapshot' => session("alcohol_debug_snapshot_{$employee->id}"),
        ]);
    }

    public function snapshot(Employee $employee): RedirectResponse
    {
        session(["alcohol_debug_snapshot_{$employee->id}" => $this->captureState($employee)]);

        return redirect()->route('alcohol.debug', $employee);
    }

    public function resetSkip(Employee $employee): RedirectResponse
    {
        $employee->update(['alcohol_skip_until' => null]);

        foreach ($employee->alcoholEnabledTerminals() as $terminal) {
            (new HikvisionService($terminal))->setAlcoholSkip((string) $employee->emp_code, false);
        }

        session(["alcohol_debug_snapshot_{$employee->id}" => $this->captureState($employee)]);

        return redirect()->route('alcohol.debug', $employee)->with('success', 'Skip reset — both DB and device flag cleared.');
    }

    /**
     * Append one random letter to the employee's last name and push the updated UserInfo to the
     * terminal via HikvisionService::addEmployee() — to verify whether an API-side name change is
     * actually reflected on the device.
     */
    public function randomizeName(Employee $employee): RedirectResponse
    {
        $letter = chr(random_int(ord('a'), ord('z')));
        $employee->update(['last_name' => $employee->last_name.$letter]);

        $terminal = $employee->alcoholEnabledTerminals()->first();

        if ($terminal === null) {
            return redirect()->route('alcohol.debug', $employee)->with('success', "Name updated to \"{$employee->full_name}\" in DB, but no alcohol-enabled terminal is linked to push it to.");
        }

        (new HikvisionService($terminal))->addEmployee($employee);

        session(["alcohol_debug_snapshot_{$employee->id}" => $this->captureState($employee)]);

        return redirect()->route('alcohol.debug', $employee)->with('success', "Name updated to \"{$employee->full_name}\" and pushed to {$terminal->name}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function captureState(Employee $employee): array
    {
        $terminal = $employee->alcoholEnabledTerminals()->first();

        if ($terminal === null) {
            return [
                'captured_at' => now()->toDateTimeString(),
                'error' => 'No alcohol-enabled terminal linked to this employee.',
            ];
        }

        $service = new HikvisionService($terminal);

        // Pull and ingest whatever the terminal has recorded in the last few minutes right
        // now, instead of waiting for the next scheduled hikvision:fetch-events run — so a
        // pass made seconds ago is already reflected below.
        $this->pullRecentEvents($terminal, $service);
        $employee->refresh();

        return [
            'captured_at' => now()->toDateTimeString(),
            'terminal' => $terminal->name,
            'db' => [
                'alcohol_skip_until' => $employee->alcohol_skip_until?->toDateTimeString(),
                'skip_active' => $employee->isAlcoholSkipActive(),
            ],
            // Exact response bodies from the terminal — not reshaped by our app — so this can
            // be handed straight to Hikvision's firmware support as evidence.
            'raw' => [
                'UserInfo/Search' => $this->prettyJson($service->rawUserInfo((string) $employee->emp_code)),
                'AlcoholDetectionParams' => $this->prettyJson($service->rawAlcoholDetectionParams()),
                'AcsEvent (last 10 min)' => $this->prettyJson($service->rawRecentEvents(now()->subMinutes(10), now())),
            ],
        ];
    }

    private function prettyJson(?string $body): ?string
    {
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body);

        return $decoded === null ? $body : json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function pullRecentEvents(HikvisionTerminal $terminal, HikvisionService $service): void
    {
        $rawEvents = $service->fetchAccessEvents(now()->subMinutes(10), now());
        $alcoholRequired = null;

        foreach ($rawEvents as $eventData) {
            if (isset($eventData['alcoholDetectionInfo'])) {
                $alcoholRequired ??= $this->rusGuardDb->getEmployeesRequiringAlcoholTest();
            }

            $this->ingestService->ingest($terminal, $eventData, $alcoholRequired ?? []);
        }
    }
}
