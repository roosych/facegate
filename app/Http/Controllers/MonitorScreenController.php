<?php

namespace App\Http\Controllers;

use App\Models\AccessPoint;
use App\Models\MonitorScreen;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin CRUD for named, persistent monitor screens (see MonitorScreen doc comment). This is the
 * supported way to point the browser on a physical monitor (at a guard post, reception desk,
 * etc.) at a stable URL showing one or more turnstiles — replaces picking access points ad hoc
 * every time a screen needs to be (re)opened.
 */
class MonitorScreenController extends Controller
{
    public function index(): View
    {
        $screens = MonitorScreen::withCount('accessPoints')->latest()->paginate(20);

        return view('monitor-screens.index', compact('screens'));
    }

    public function create(): View
    {
        $accessPoints = AccessPoint::where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('monitor-screens.create', ['accessPoints' => $accessPoints, 'selectedIds' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $screen = MonitorScreen::create(['name' => $validated['name']]);
        $screen->setAccessPoints(array_map('intval', $validated['access_point_ids']));

        return redirect()->route('monitor-screens.index')->with('success', 'Экран монитора создан.');
    }

    public function edit(MonitorScreen $monitorScreen): View
    {
        $activePoints = AccessPoint::where('is_active', true)->orderBy('name')->get(['id', 'name']);
        $attachedPoints = $monitorScreen->accessPoints;

        // The picker must still list any point already on this screen even if it's since gone
        // inactive — otherwise the form's JS never learns its name, silently drops it from the
        // hidden inputs, and saving anything (even just the screen's name) detaches it.
        $accessPoints = $activePoints->concat($attachedPoints)->unique('id')->sortBy('name')->values();
        $selectedIds = $attachedPoints->pluck('id')->all();

        return view('monitor-screens.edit', compact('monitorScreen', 'accessPoints', 'selectedIds'));
    }

    public function update(Request $request, MonitorScreen $monitorScreen): RedirectResponse
    {
        $validated = $this->validated($request);

        $monitorScreen->update(['name' => $validated['name']]);
        $monitorScreen->setAccessPoints(array_map('intval', $validated['access_point_ids']));

        return redirect()->route('monitor-screens.index')->with('success', 'Экран монитора обновлён.');
    }

    public function destroy(MonitorScreen $monitorScreen): RedirectResponse
    {
        $monitorScreen->delete();

        return redirect()->route('monitor-screens.index')->with('success', 'Экран монитора удалён.');
    }

    /**
     * @return array{name: string, access_point_ids: array<int, string>}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'access_point_ids' => ['required', 'array', 'min:1'],
            'access_point_ids.*' => ['integer', 'exists:access_points,id'],
        ]);
    }
}
