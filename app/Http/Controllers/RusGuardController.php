<?php

namespace App\Http\Controllers;

use App\Services\RusGuard\EmployeeService;
use Illuminate\Http\JsonResponse;

class RusGuardController extends Controller
{
    public function __construct(private EmployeeService $employeeService) {}

    public function accessLevels(): JsonResponse
    {
        return response()->json($this->employeeService->getAccessPointsWithEmployees());
    }

}
