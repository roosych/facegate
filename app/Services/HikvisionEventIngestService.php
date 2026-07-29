<?php

namespace App\Services;

use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Support\Carbon;

/**
 * Shared per-event processing for Hikvision access-control events — used by both the
 * scheduled poll (FetchHikvisionEventsJob) and the real-time push webhook
 * (HikvisionEventWebhookController), so a passed alcohol test is handled identically
 * regardless of which path delivered the event.
 */
class HikvisionEventIngestService
{
    /**
     * @param  array<string, mixed>  $eventData
     * @param  array<string, true>  $alcoholRequiredUuids  From RusGuardDatabaseService::getEmployeesRequiringAlcoholTest()
     */
    public function ingest(HikvisionTerminal $terminal, array $eventData, array $alcoholRequiredUuids): ?AccessEvent
    {
        if (empty($eventData['employeeNoString']) && empty($eventData['cardNo'])) {
            return null;
        }

        // Only import events that actually carry an alcohol reading (skip plain door
        // events). Filtering on concentration > 0 would also drop genuine passes with
        // a 0.00 reading — the best possible result — so presence of the key is what matters.
        if (! isset($eventData['alcoholDetectionInfo'])) {
            return null;
        }

        $empCode = isset($eventData['employeeNoString']) ? (int) $eventData['employeeNoString'] : null;
        $employee = $empCode !== null ? Employee::where('emp_code', $empCode)->first() : null;
        $eventTime = isset($eventData['time']) ? Carbon::parse($eventData['time']) : now();

        $alreadyExists = AccessEvent::where('hikvision_terminal_id', $terminal->id)
            ->where('event_time', '>=', $eventTime->copy()->subSeconds(30))
            ->where('event_time', '<=', $eventTime->copy()->addSeconds(30))
            ->where(fn ($q) => $employee
                ? $q->where('employee_id', $employee->id)
                : $q->whereNull('employee_id')->where('card_no', $eventData['cardNo'] ?? null)
            )
            ->exists();

        if ($alreadyExists) {
            return null;
        }

        $event = AccessEvent::create([
            'employee_id' => $employee?->id,
            'hikvision_terminal_id' => $terminal->id,
            'access_point_id' => $terminal->access_point_id,
            'event_time' => $eventTime,
            'verify_type' => $eventData['currentVerifyMode'] ?? 'unknown',
            'direction' => $eventData['direction'] ?? null,
            'card_no' => $eventData['cardNo'] ?? null,
            'raw_data' => $eventData,
        ]);

        if ($employee !== null && $event->alcoholPassed() === true && isset($alcoholRequiredUuids[$employee->rusguard_uuid])) {
            $employee->update(['alcohol_skip_until' => now()->addMinutes(Setting::alcoholSkipGraceMinutes())]);
            $this->pushAlcoholSkipToLinkedTerminals($employee);
        }

        return $event;
    }

    /**
     * Apply the post-pass grace period across every alcohol-enabled Hikvision terminal the
     * employee has RusGuard access to — not just the one they just passed at — so they
     * aren't re-tested at another post on the same site within the re-test window.
     */
    private function pushAlcoholSkipToLinkedTerminals(Employee $employee): void
    {
        foreach ($employee->alcoholEnabledTerminals() as $terminal) {
            (new HikvisionService($terminal))->setAlcoholSkip((string) $employee->emp_code, true);
        }
    }
}
