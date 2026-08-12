<?php

namespace App\Http\Controllers;

use App\Models\SyncLog;
use Illuminate\View\View;

class SyncLogController extends Controller
{
    public function index(): View
    {
        $logs = SyncLog::with('employee')
            ->latest()
            ->paginate(50);

        return view('logs.index', compact('logs'));
    }
}
