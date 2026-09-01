<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EmployeeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('search', ''));

        $employees = Employee::with(['accessPoints', 'keys'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($q) use ($search) {
                    $like = '%'.$search.'%';
                    $q->where('last_name', 'ilike', $like)
                        ->orWhere('first_name', 'ilike', $like)
                        ->orWhere('middle_name', 'ilike', $like)
                        ->orWhere('emp_code', 'ilike', $like)
                        ->orWhereHas('keys', fn ($k) => $k->where('value', 'ilike', $like));
                });
            })
            ->latest()
            ->paginate(30)
            ->withQueryString();

        return view('employees.index', compact('employees', 'search'));
    }

    public function photo(Employee $employee): BinaryFileResponse
    {
        $path = $employee->photo_path
            ? storage_path('app/'.$employee->photo_path)
            : null;

        if ($path === null || ! file_exists($path)) {
            abort(404);
        }

        return response()->file($path, ['Content-Type' => 'image/jpeg']);
    }

    public function show(Employee $employee): View
    {
        $employee->load(['accessPoints', 'syncLogs' => fn ($q) => $q->latest()->limit(20)]);

        $recentEvents = $employee->accessEvents()
            ->with(['hikvisionTerminal', 'accessPoint'])
            ->latest('event_time')
            ->limit(20)
            ->get();

        return view('employees.show', compact('employee', 'recentEvents'));
    }
}
