<?php

namespace Tests\Unit\Services;

use App\Models\HikvisionTerminal;
use App\Services\HikvisionService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HikvisionServicePaginationTest extends TestCase
{
    private function terminal(): HikvisionTerminal
    {
        return HikvisionTerminal::factory()->make(['ip' => '127.0.0.1']);
    }

    /**
     * @param  array<int, int>  $counts  rows returned per successive page
     */
    private function personPages(int $total, array $counts): void
    {
        $sequence = Http::sequence();
        $employeeNo = 1;

        foreach ($counts as $count) {
            $people = [];

            for ($i = 0; $i < $count; $i++) {
                $people[] = ['employeeNo' => (string) $employeeNo++];
            }

            $sequence->push(['UserInfoSearch' => ['totalMatches' => $total, 'UserInfo' => $people]], 200);
        }

        Http::fake(['*' => $sequence]);
    }

    public function test_keeps_paging_when_the_device_returns_smaller_pages_than_requested(): void
    {
        // Device caps pages at 30 even though 50 was asked for — harmless, must not stop early.
        $this->personPages(68, [30, 30, 8]);

        $persons = (new HikvisionService($this->terminal()))->allPersons();

        $this->assertCount(68, $persons);
    }

    public function test_retries_an_empty_page_instead_of_treating_it_as_the_end(): void
    {
        // Third request comes back empty although 68 rows were promised; the retry recovers it.
        $this->personPages(68, [30, 30, 0, 8]);

        $persons = (new HikvisionService($this->terminal()))->allPersons();

        $this->assertCount(68, $persons);
    }

    public function test_throws_rather_than_returning_a_truncated_roster(): void
    {
        // Empty from here on: returning the 60 rows collected so far would hide 8 people from
        // the removal pass, so this must fail loudly instead.
        $this->personPages(68, [30, 30, 0, 0, 0, 0, 0]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('got 60 of 68 rows');

        (new HikvisionService($this->terminal()))->allPersons();
    }

    public function test_a_failing_page_reporting_zero_total_cannot_shrink_the_expectation(): void
    {
        $sequence = Http::sequence()
            ->push(['UserInfoSearch' => ['totalMatches' => 40, 'UserInfo' => array_map(
                fn ($i) => ['employeeNo' => (string) $i], range(1, 30)
            )]], 200)
            // Transport error surfaces as an empty page with total 0 — must not be believed.
            ->push(['UserInfoSearch' => ['totalMatches' => 0, 'UserInfo' => []]], 500)
            ->push(['UserInfoSearch' => ['totalMatches' => 40, 'UserInfo' => array_map(
                fn ($i) => ['employeeNo' => (string) $i], range(31, 40)
            )]], 200);

        Http::fake(['*' => $sequence]);

        $persons = (new HikvisionService($this->terminal()))->allPersons();

        $this->assertCount(40, $persons);
    }
}
