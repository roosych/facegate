<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Setting;
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
        ]);
    }

    public function updateGracePeriod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'grace_minutes' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        Setting::set('alcohol_skip_grace_minutes', (string) $validated['grace_minutes']);

        return redirect()->route('alcohol.index')->with('success', 'Grace period updated.');
    }
}
