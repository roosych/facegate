<?php

namespace App\Http\Controllers;

use App\Models\AccessEvent;
use App\Models\Device;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AdmsController extends Controller
{
    public function handle(Request $request): Response
    {
        $sn = $request->query('SN', $request->query('sn', ''));

        $device = Device::where('sn', $sn)->first();

        $body = $request->getContent();

        // Parse ATTLOG lines: PIN=123 Time=2024-01-15 09:30:00 Status=0 Verify=15
        foreach (explode("\n", $body) as $line) {
            $line = trim($line);

            if (empty($line) || ! str_contains($line, 'PIN=')) {
                continue;
            }

            $parsed = [];
            preg_match_all('/(\w+)=([^\s]+(?:\s+[^\s=]+(?!\s*=))*)/', $line, $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $parsed[$match[1]] = trim($match[2]);
            }

            $empCode = isset($parsed['PIN']) ? (int) $parsed['PIN'] : null;
            $eventTime = $parsed['Time'] ?? now()->toDateTimeString();
            $verifyType = $this->resolveVerifyType((int) ($parsed['Verify'] ?? 0));

            $employee = $empCode !== null
                ? Employee::where('emp_code', $empCode)->first()
                : null;

            AccessEvent::create([
                'employee_id' => $employee?->id,
                'device_id' => $device?->id,
                'access_point_id' => null,
                'event_time' => $eventTime,
                'verify_type' => $verifyType,
                'direction' => null,
                'card_no' => $employee?->card_no,
                'raw_data' => $parsed,
            ]);
        }

        return response('OK: '.$sn, 200);
    }

    private function resolveVerifyType(int $code): string
    {
        return match ($code) {
            1, 2 => 'finger',
            15 => 'face',
            0, 4 => 'card',
            default => 'unknown',
        };
    }
}
