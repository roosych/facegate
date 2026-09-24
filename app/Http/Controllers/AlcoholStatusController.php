<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Setting;
use App\Services\HikvisionService;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AlcoholStatusController extends Controller
{
    public function index(RusGuardDatabaseService $rusGuardDb): View
    {
        $required = $rusGuardDb->getEmployeesRequiringAlcoholTest();

        $employees = Employee::whereIn('rusguard_uuid', array_keys($required))
            ->orderBy('last_name')
            ->get();

        $missingUuids = array_diff(array_keys($required), $employees->pluck('rusguard_uuid')->all());

        $rows = $employees->map(fn (Employee $employee) => [
            'employee' => $employee,
            'terminals' => $employee->alcoholEnabledTerminals(),
            'lastPass' => $employee->accessEvents()
                ->alcoholPassed()
                ->with('hikvisionTerminal')
                ->latest('event_time')
                ->first(),
        ]);

        return view('alcohol.index', [
            'rows' => $rows,
            'missingCount' => count($missingUuids),
            'graceMinutes' => Setting::alcoholSkipGraceMinutes(),
            'notificationThreshold' => Setting::alcoholNotificationThreshold(),
            'notificationEmails' => implode(', ', Setting::alcoholNotificationEmails()),
            'cleaningNotificationEmails' => implode(', ', Setting::alcoholCleaningNotificationEmails()),
        ]);
    }

    public function updateGracePeriod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grace_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        Setting::set('alcohol_skip_grace_minutes', (string) $validated['grace_minutes']);

        return redirect()->route('alcohol.index')->with('success', 'Льготный период обновлён.');
    }

    public function updateNotificationSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'notification_threshold' => ['required', 'numeric', 'min:0', 'max:1000'],
            'notification_emails' => ['nullable', 'string'],
        ]);

        $emails = $this->parseEmailList($validated['notification_emails'] ?? '');

        if (($invalid = $this->firstInvalidEmail($emails)) !== null) {
            return back()->withErrors(['notification_emails' => "\"{$invalid}\" — некорректный email-адрес."])->withInput();
        }

        Setting::set('alcohol_notification_threshold', (string) $validated['notification_threshold']);
        Setting::set('alcohol_notification_emails', implode(',', $emails));

        return redirect()->route('alcohol.index')->with('success', 'Настройки уведомлений обновлены.');
    }

    public function updateCleaningNotificationSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'cleaning_notification_emails' => ['nullable', 'string'],
        ]);

        $emails = $this->parseEmailList($validated['cleaning_notification_emails'] ?? '');

        if (($invalid = $this->firstInvalidEmail($emails)) !== null) {
            return back()->withErrors(['cleaning_notification_emails' => "\"{$invalid}\" — некорректный email-адрес."])->withInput();
        }

        Setting::set('alcohol_cleaning_notification_emails', implode(',', $emails));

        return redirect()->route('alcohol.index')->with('success', 'Адресаты уведомлений об очистке обновлены.');
    }

    /** @return array<int, string> */
    private function parseEmailList(string $raw): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    /**
     * @param  array<int, string>  $emails
     */
    private function firstInvalidEmail(array $emails): ?string
    {
        foreach ($emails as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Manually end an employee's post-pass grace period early — clears the DB flag and pushes
     * the "must test" state back to every alcohol-enabled terminal they're linked to, so the
     * next pass immediately requires a fresh breath test again (mainly for testing the flow
     * without waiting out the configured grace period).
     */
    public function clearSkip(Employee $employee): RedirectResponse
    {
        $employee->update(['alcohol_skip_until' => null]);

        foreach ($employee->alcoholEnabledTerminals() as $terminal) {
            (new HikvisionService($terminal))->setAlcoholSkip((string) $employee->emp_code, false);
        }

        return redirect()->route('alcohol.index')->with('success', "Пропуск сброшен для {$employee->full_name} — при следующем проходе потребуется тест.");
    }
}
