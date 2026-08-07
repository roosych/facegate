<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\HikvisionTerminal;
use App\Models\AccessPoint;
use App\Services\RusGuard\RusGuardDatabaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

class AccessPointController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));

        $accessPoints = AccessPoint::with([
            'enterDevice',
            'exitDevice',
            'hikvisionTerminal',
            'employees' => fn ($q) => $q->orderBy('last_name')->orderBy('first_name'),
        ])
            ->withCount('employees')
            ->when($search !== '', function ($query) use ($search) {
                $like = '%'.$search.'%';
                $query->where('name', 'ilike', $like)
                    ->orWhere('rusguard_access_point_name', 'ilike', $like);
            })
            ->latest()
            ->paginate(20)
            ->withQueryString();

        $freeTerminals = HikvisionTerminal::whereNull('access_point_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'location']);

        return view('access-points.index', compact('accessPoints', 'freeTerminals', 'search'));
    }

    public function create(RusGuardDatabaseService $db): View
    {
        $devices = Device::where('is_active', true)->orderBy('name')->get();
        $accessPoints = $db->getAccessPoints();

        return view('access-points.create', compact('devices', 'accessPoints'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rusguard_access_point_id' => ['required', 'uuid'],
            'rusguard_access_point_name' => ['required', 'string', 'max:255'],
            'enter_device_id' => ['nullable', 'exists:devices,id'],
            'exit_device_id' => ['nullable', 'exists:devices,id'],
            'is_active' => ['boolean'],
        ]);

        AccessPoint::create($validated);

        return redirect()->route('access-points.index')->with('success', 'Access point created.');
    }

    public function edit(AccessPoint $accessPoint, RusGuardDatabaseService $db): View
    {
        $devices = Device::where('is_active', true)->orderBy('name')->get();
        $accessPoints = $db->getAccessPoints();

        return view('access-points.edit', compact('accessPoint', 'devices', 'accessPoints'));
    }

    public function update(Request $request, AccessPoint $accessPoint): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'rusguard_access_point_id' => ['required', 'uuid'],
            'rusguard_access_point_name' => ['required', 'string', 'max:255'],
            'enter_device_id' => ['nullable', 'exists:devices,id'],
            'exit_device_id' => ['nullable', 'exists:devices,id'],
            'is_active' => ['boolean'],
        ]);

        $accessPoint->update($validated);

        return redirect()->route('access-points.index')->with('success', 'Access point updated.');
    }

    public function checkPoints(RusGuardDatabaseService $db): JsonResponse
    {
        try {
            $rgPoints = $db->getAccessPoints();
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        // SQL Server's CONVERT(varchar(36), ...) returns uppercase uuids, but Postgres' native
        // uuid column canonicalizes to lowercase — normalize both sides so this plain PHP
        // Collection::has() key lookup isn't a case-sensitive string compare (unlike the
        // whereNotIn() below, which compares against a real uuid-typed column and is already
        // case-insensitive at the SQL level).
        $localByDriverId = AccessPoint::pluck('name', 'rusguard_access_point_id')
            ->mapWithKeys(fn ($name, $uuid) => [strtolower($uuid) => $name]);
        $rgIds = array_column($rgPoints, 'driverId');

        // Points in RusGuard but not matched locally by UUID
        $notByUuid = array_values(array_filter(
            $rgPoints,
            fn ($p) => ! $localByDriverId->has(strtolower($p['driverId']))
        ));

        // Among those, detect name-based matches (same name, wrong/null UUID)
        $rgMissingNames = array_column($notByUuid, 'name');

        $localByName = $rgMissingNames !== []
            ? AccessPoint::where(function ($q) use ($rgMissingNames) {
                $q->whereIn('name', $rgMissingNames)
                    ->orWhereIn('rusguard_access_point_name', $rgMissingNames);
            })->get(['id', 'name', 'rusguard_access_point_id', 'rusguard_access_point_name'])
                ->keyBy('name')
            : collect();

        $missing = array_values(array_filter(
            $notByUuid,
            fn ($p) => $localByName->get($p['name']) === null
        ));

        $extra = AccessPoint::where('is_active', true)
            ->whereNotIn('rusguard_access_point_id', $rgIds)
            ->whereNotNull('rusguard_access_point_id')
            ->get(['id', 'name', 'rusguard_access_point_id', 'rusguard_access_point_name']);

        return response()->json([
            'local' => $localByDriverId->count(),
            'rusguard' => count($rgPoints),
            'missing' => $missing,
            'extra' => $extra,
        ]);
    }

    public function fixPoint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'local_id' => ['required', 'integer', 'exists:access_points,id'],
            'driver_id' => ['required', 'string'],
        ]);

        $accessPoint = AccessPoint::findOrFail($validated['local_id']);
        $accessPoint->update(['rusguard_access_point_id' => $validated['driver_id']]);

        return response()->json(['success' => true]);
    }

    public function createPoint(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'driver_id' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
            'device_type' => ['nullable', 'string', 'max:50'],
        ]);

        $accessPoint = AccessPoint::updateOrCreate(
            ['rusguard_access_point_id' => $validated['driver_id']],
            ['name' => $validated['name'], 'rusguard_access_point_name' => $validated['name'], 'device_type' => $validated['device_type'] ?? null, 'is_active' => true]
        );

        return response()->json(['id' => $accessPoint->id, 'name' => $accessPoint->name]);
    }

    public function rusguardEmployees(AccessPoint $accessPoint, RusGuardDatabaseService $db): JsonResponse
    {
        try {
            $employees = $db->getEmployeesForAccessPoint($accessPoint->rusguard_access_point_id);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        $result = array_map(function (array $emp) use ($db) {
            $cardKeys = [];

            try {
                $keys = $db->getEmployeeKeys($emp['uuid']);
                $cardKeys = array_column(
                    array_filter($keys, fn ($k) => $k['type'] === 'card'),
                    'value'
                );
            } catch (Throwable) {
                // Non-fatal — show employee without keys
            }

            return [
                'uuid' => $emp['uuid'],
                'fio' => $emp['fio'],
                'card_keys' => $cardKeys,
            ];
        }, $employees);

        return response()->json([
            'count' => count($result),
            'employees' => $result,
        ]);
    }

    public function checkCount(AccessPoint $accessPoint, RusGuardDatabaseService $db): JsonResponse
    {
        $local = $accessPoint->employees()->count();

        try {
            $rgEmployees = $db->getEmployeesForAccessPoint(
                $accessPoint->rusguard_access_point_id
            );
            $rusguard = count($rgEmployees);
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'local' => $local,
            'rusguard' => $rusguard,
            'diff' => $rusguard - $local,
        ]);
    }

    public function show(AccessPoint $accessPoint): View
    {
        $accessPoint->load(['enterDevice', 'exitDevice', 'employees']);

        $recentEvents = $accessPoint->accessEvents()
            ->with(['employee', 'device'])
            ->latest('event_time')
            ->limit(50)
            ->get();

        return view('access-points.show', compact('accessPoint', 'recentEvents'));
    }
}
