<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Services\HikvisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('alcohol:clear-expired-skip')]
#[Description('Clear the alcohol test skip flag on Hikvision terminals for employees whose post-pass grace period has expired')]
class ClearExpiredAlcoholSkip extends Command
{
    public function handle(): int
    {
        $employees = Employee::whereNotNull('alcohol_skip_until')
            ->where('alcohol_skip_until', '<=', now())
            ->get();

        foreach ($employees as $employee) {
            $employee->update(['alcohol_skip_until' => null]);

            foreach ($employee->alcoholEnabledTerminals() as $terminal) {
                (new HikvisionService($terminal))->setAlcoholSkip((string) $employee->emp_code, false);
            }
        }

        $this->info("Cleared expired alcohol skip for {$employees->count()} employee(s).");

        return self::SUCCESS;
    }
}
