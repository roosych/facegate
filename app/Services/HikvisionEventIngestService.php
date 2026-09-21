<?php

namespace App\Services;

use App\Mail\AlcoholTestFailedMail;
use App\Models\AccessEvent;
use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Models\Setting;
use App\Services\RusGuard\RusGuardDatabaseService;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
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
        $eventTime = $this->terminalEventTime($terminal, $eventData);

        // serialNo is the terminal's own monotonically increasing per-event counter — present
        // on both the real-time push and the polled AcsEvent shape — so it's an exact identity
        // check when available. Back-to-back genuine tests only seconds apart were colliding
        // in the old ±30s time-window dedup below and getting silently dropped as "duplicates"
        // even though they were distinct passes. Fall back to the time-window heuristic only
        // when serialNo isn't present in the payload.
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
     * When the event happened, by the terminal's own clock — the terminal is the source of
     * truth for event_time, whichever path delivered the event. The polled AcsEvent shape
     * carries it as "time"; the push shape carries the envelope's "dateTime" (copied onto the
     * event by the webhook controller). Both are ISO 8601 with the device's offset, so the
     * instant is converted to the application timezone before it is stored in the
     * timezone-less column; otherwise it would be saved as the device's wall-clock digits.
     *
     * Only a payload with no usable timestamp falls back to the server clock, and that is
     * logged: created_at is the server-side receipt time, so an event whose event_time is the
     * receipt time as well can no longer be compared against it.
     *
     * @param  array<string, mixed>  $eventData
     */
    private function terminalEventTime(HikvisionTerminal $terminal, array $eventData): Carbon
    {
        $stamp = $eventData['time'] ?? $eventData['dateTime'] ?? null;

        if (is_string($stamp) && $stamp !== '') {
            try {
                return Carbon::parse($stamp)->setTimezone(config('app.timezone'));
            } catch (InvalidFormatException) {
                // fall through to the server clock below
            }
        }

        Log::warning('Hikvision event has no usable terminal timestamp — using server time', [
            'terminal_id' => $terminal->id,
            'serialNo' => $eventData['serialNo'] ?? null,
            'timestamp' => $stamp,
        ]);

        return now();
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
