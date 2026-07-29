<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Employee;
use App\Models\Turnstile;
use App\Services\ZKBioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

class DeviceController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('hikvision.index');
    }

    public function create(): View
    {
        return view('devices.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'ip' => ['required', 'string', 'max:45'],
            'sn' => ['required', 'string', 'max:255'],
            'zkbio_terminal_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        Device::create($validated);

        return redirect()->route('devices.index')->with('success', 'Device created.');
    }

    public function syncFromZKBio(ZKBioService $zkBio): RedirectResponse
    {
        try {
            $terminals = $zkBio->getTerminals();
        } catch (Throwable $e) {
            return redirect()->route('devices.index')->with('error', 'Failed to fetch terminals: '.$e->getMessage());
        }

        $synced = 0;

        foreach ($terminals as $terminal) {
            $sn = (string) ($terminal['sn'] ?? '');

            if ($sn === '') {
                continue;
            }

            Device::updateOrCreate(
                ['sn' => $sn],
                [
                    'name' => (string) ($terminal['alias'] ?? $terminal['terminal_name'] ?? $sn),
                    'ip' => (string) ($terminal['ip_address'] ?? ''),
                    'zkbio_terminal_id' => isset($terminal['id']) ? (int) $terminal['id'] : null,
                    'alias' => (string) ($terminal['alias'] ?? ''),
                    'terminal_name' => (string) ($terminal['terminal_name'] ?? ''),
                    'area_name' => (string) ($terminal['area_name'] ?? $terminal['area']['area_name'] ?? ''),
                    'last_activity' => $terminal['last_activity'] ?? null,
                    'user_count' => (int) ($terminal['user_count'] ?? 0),
                    'face_count' => (int) ($terminal['face_count'] ?? 0),
                ]
            );

            $synced++;
        }

        return redirect()->route('devices.index')->with('success', "Synced {$synced} terminals from ZKBio.");
    }

    public function pushFromAccessPoint(Request $request, Device $device, ZKBioService $zkBio): RedirectResponse
    {
        $validated = $request->validate([
            'access_point_id' => ['required', 'integer', 'exists:access_points,id'],
        ]);

        $turnstile = Turnstile::with('employees.keys')->findOrFail($validated['access_point_id']);

        $employees = $turnstile->employees;

        if ($employees->isEmpty()) {
            return redirect()->route('devices.index')->with('error', 'No employees found for this access point.');
        }

        try {
            $jwtToken = $zkBio->getJwtToken();
        } catch (Throwable $e) {
            return redirect()->route('devices.index')->with('error', 'ZKBio auth failed: '.$e->getMessage());
        }

        // Remove from ZKBio employees who are no longer in this access point
        // (and not linked to this device via other turnstiles)
        $pushIds = $employees->pluck('id')->all();

        $otherTurnstileIds = Turnstile::where('enter_device_id', $device->id)
            ->orWhere('exit_device_id', $device->id)
            ->pluck('id');

        $keepIds = $otherTurnstileIds->isNotEmpty()
            ? \DB::table('access_point_employee')
                ->whereIn('access_point_id', $otherTurnstileIds)
                ->pluck('employee_id')
                ->all()
            : [];

        Employee::whereNotNull('zkbio_id')
            ->whereNotIn('id', $pushIds)
            ->whereNotIn('id', $keepIds)
            ->each(function (Employee $emp) use ($zkBio, $jwtToken): void {
                try {
                    $zkBio->deleteEmployee($emp->zkbio_id, $jwtToken);
                    $emp->zkbio_id = null;
                    $emp->save();
                } catch (Throwable) {
                    // non-fatal
                }
            });

        $pushed = 0;
        $errorMessages = [];

        foreach ($employees as $employee) {
            try {
                $cardNo = $employee->keys->where('type', 'card')->first()?->value ?? '';

                $baseData = [
                    'emp_code' => (string) $employee->emp_code,
                    'first_name' => $employee->first_name,
                    'last_name' => $employee->last_name ?? '',
                    'department' => (int) config('zkbio.department_id'),
                    'area' => [(int) config('zkbio.area_id')],
                ];

                if ($cardNo !== '') {
                    $baseData['card_no'] = $cardNo;
                }

                if ($employee->zkbio_id === null) {
                    $zkbioId = $zkBio->createEmployee($baseData, $jwtToken);
                    $employee->zkbio_id = $zkbioId;
                    $employee->save();
                } else {
                    try {
                        $zkBio->updateEmployee($employee->zkbio_id, $baseData, $jwtToken);
                    } catch (Throwable) {
                        // Card may be taken by another ZKBio employee — retry without it
                        $dataWithoutCard = array_diff_key($baseData, ['card_no' => '']);
                        $zkBio->updateEmployee($employee->zkbio_id, $dataWithoutCard, $jwtToken);

                        if ($cardNo !== '') {
                            $conflict = $zkBio->findByCardNo($cardNo, $jwtToken);
                            $conflictInfo = $conflict
                                ? " (card held by ZKBio id={$conflict['id']} emp_code={$conflict['emp_code']})"
                                : '';
                            $errorMessages[] = $employee->full_name.': card conflict'.$conflictInfo.'. Name updated, card skipped.';
                        }

                        $pushed++;

                        continue;
                    }
                }

                if ($employee->zkbio_id !== null) {
                    $zkBio->syncEmployeeToDevice($employee->zkbio_id, $jwtToken);
                    $pushed++;
                }
            } catch (Throwable $e) {
                $errorMessages[] = $employee->full_name.': '.$e->getMessage();
            }
        }

        $pointName = $turnstile->rusguard_access_point_name ?: $turnstile->name;
        $message = "Pushed {$pushed} employees from \"{$pointName}\" to {$device->name}.";

        if (! empty($errorMessages)) {
            return redirect()->route('devices.index')
                ->with('success', $message)
                ->with('error', implode(' | ', $errorMessages));
        }

        return redirect()->route('devices.index')->with('success', $message);
    }
}
