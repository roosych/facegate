<?php

namespace App\Services;

use App\Mail\AlcoholTestFailedMail;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

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

        // serialNo is the terminal's own monotonically increasing per-event counter — present
        // on both the real-time push and the polled AcsEvent shape — so it's an exact identity
        // check when available. Real-time push payloads don't carry a "time" field on the
        // per-event object (only the outer envelope's heartbeat-style "dateTime" does), so
        // $eventTime above is just now() for push-delivered events; back-to-back genuine tests
        // only seconds apart were colliding in the old ±30s time-window dedup below and getting
        // silently dropped as "duplicates" even though they were distinct passes. Fall back to
        // the time-window heuristic only when serialNo isn't present in the payload.
        $serialNo = $eventData['serialNo'] ?? null;

        $alreadyExists = AccessEvent::where('hikvision_terminal_id', $terminal->id)
            ->when(
                $serialNo !== null,
                fn ($q) => $q->where('raw_data->serialNo', $serialNo),
                fn ($q) => $q->where('event_time', '>=', $eventTime->copy()->subSeconds(30))
                    ->where('event_time', '<=', $eventTime->copy()->addSeconds(30))
                    ->where(fn ($q2) => $employee
                        ? $q2->where('employee_id', $employee->id)
                        : $q2->whereNull('employee_id')->where('card_no', $eventData['cardNo'] ?? null)
                    )
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

        if ($event->alcoholPassed() === false) {
            $this->notifyOnFailedTest($event);
        }

        return $event;
    }

    /**
     * Email the configured recipients when a reading is at or above the notification
     * threshold — a failed "normal" result alone doesn't necessarily mean it crossed the
     * threshold the site actually wants to be alerted about.
     */
    private function notifyOnFailedTest(AccessEvent $event): void
    {
        $concentration = $event->alcoholConcentration();
        $recipients = Setting::alcoholNotificationEmails();

        if ($concentration === null || $recipients === [] || $concentration < Setting::alcoholNotificationThreshold()) {
            return;
        }

        Mail::to($recipients)->send(new AlcoholTestFailedMail($event));
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
