<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('hikvision:audit-terminal {terminal? : Terminal ID — omit to audit every active terminal}')]
#[Description('Compare each terminal\'s stored person/card/face against the local DB and report drift')]
class HikvisionAuditTerminal extends Command
{
    /**
     * Reads only — safe on any environment. Exits non-zero when drift is found so it can gate
     * a scheduled alert. Drift here is what "person authenticates as a colleague" looks like
     * before anyone notices: a terminal slot whose name/card no longer matches the emp_code.
     */
    public function handle(): int
    {
        $terminals = $this->argument('terminal')
            ? HikvisionTerminal::where('id', $this->argument('terminal'))->get()
            : HikvisionTerminal::where('is_active', true)->get();

        if ($terminals->isEmpty()) {
            $this->error('No matching terminal(s) found.');

            return self::FAILURE;
        }

        $anyDrift = false;

        foreach ($terminals as $terminal) {
            $service = new HikvisionService($terminal);

            if (! $service->isOnline()) {
                $this->warn("[{$terminal->name}] offline — skipped.");

                continue;
            }

            $persons = collect($service->allPersons())
                ->filter(fn ($p) => (string) ($p['employeeNo'] ?? '') !== '')
                ->keyBy(fn ($p) => (string) $p['employeeNo']);
            $cards = $service->allCards();
            $faces = $service->empCodesWithFace();

            $employees = $terminal->accessPoint?->employees->unique('id') ?? collect();
            $rows = $this->driftRows($employees, $persons, $cards, $faces);

            if ($rows->isEmpty()) {
                $this->info("[{$terminal->name}] OK — {$employees->count()} linked employees, no drift.");

                continue;
            }

            $anyDrift = true;
            $this->error("[{$terminal->name}] {$rows->count()} record(s) drifted:");
            $this->table(['emp_code', 'DB name', 'terminal name', 'name', 'card', 'face'], $rows->all());
        }

        return $anyDrift ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @param  Collection<string, array<string, mixed>>  $persons
     * @param  array<string, array<int, string>>  $cards
     * @param  array<string, true>  $faces
     * @return Collection<int, array<int, string>>
     */
    private function driftRows(Collection $employees, Collection $persons, array $cards, array $faces): Collection
    {
        return $employees->map(function (Employee $e) use ($persons, $cards, $faces): ?array {
            $code = (string) $e->emp_code;
            $person = $persons->get($code);
            $expectedName = mb_substr($e->full_name, 0, 32);

            $expectedCards = $e->keys->where('type', 'card')
                ->map(fn ($k) => ltrim((string) $k->value, '0'))->sort()->values()->all();
            $terminalCards = collect($cards[$code] ?? [])->map(fn ($c) => ltrim($c, '0'))->sort()->values()->all();

            $nameOk = $person !== null && ($person['name'] ?? null) === $expectedName;
            $cardOk = $expectedCards === $terminalCards;
            $faceOk = $expectedCards === [] || isset($faces[$code]); // face-only people legitimately have no card
            $faceExpected = $e->photo_path !== null;

            if ($person === null) {
                return [$code, $expectedName, '<missing>', 'MISSING', '', ''];
            }

            if ($nameOk && $cardOk && (! $faceExpected || isset($faces[$code]))) {
                return null;
            }

            return [
                $code,
                $expectedName,
                (string) ($person['name'] ?? '?'),
                $nameOk ? 'ok' : 'DRIFT',
                $cardOk ? 'ok' : 'want ['.implode(',', $expectedCards).'] have ['.implode(',', $terminalCards).']',
                $faceExpected ? (isset($faces[$code]) ? 'ok' : 'MISSING') : 'n/a',
            ];
        })->filter()->values();
    }
}
