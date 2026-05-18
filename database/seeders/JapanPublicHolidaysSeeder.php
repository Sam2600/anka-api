<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

/**
 * Seeds Japanese public holidays into every tenant for the next three years
 * starting from the current year. Recurring fixed-date holidays (元日, 建国記念の日,
 * 昭和の日, etc.) are flagged `is_recurring = true`; floating Happy Monday and
 * equinox dates are concrete per-year rows so the AI scheduler and capacity
 * service can look them up by exact date without recurrence expansion logic.
 *
 * Equinox dates are sourced from the National Astronomical Observatory of Japan
 * (NAOJ) — they vary year to year and must be hardcoded.
 */
class JapanPublicHolidaysSeeder extends Seeder
{
    public function run(): void
    {
        $startYear = (int) date('Y');
        $years = [$startYear, $startYear + 1, $startYear + 2];

        $holidays = $this->buildHolidaysForYears($years);

        Tenant::query()->get()->each(function (Tenant $tenant) use ($holidays) {
            foreach ($holidays as $h) {
                Holiday::firstOrCreate(
                    ['tenant_id' => $tenant->id, 'date' => $h['date']],
                    ['name' => $h['name'], 'is_recurring' => $h['is_recurring']]
                );
            }
        });
    }

    /**
     * @return list<array{date: string, name: string, is_recurring: bool}>
     */
    private function buildHolidaysForYears(array $years): array
    {
        $out = [];
        $equinox = $this->equinoxDates();

        foreach ($years as $y) {
            // Fixed-date national holidays.
            $out[] = ['date' => sprintf('%d-01-01', $y), 'name' => '元日',         'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-02-11', $y), 'name' => '建国記念の日', 'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-02-23', $y), 'name' => '天皇誕生日',   'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-04-29', $y), 'name' => '昭和の日',     'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-05-03', $y), 'name' => '憲法記念日',   'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-05-04', $y), 'name' => 'みどりの日',   'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-05-05', $y), 'name' => 'こどもの日',   'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-08-11', $y), 'name' => '山の日',       'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-11-03', $y), 'name' => '文化の日',     'is_recurring' => true];
            $out[] = ['date' => sprintf('%d-11-23', $y), 'name' => '勤労感謝の日', 'is_recurring' => true];

            // Happy Monday holidays — Nth weekday of month.
            $out[] = ['date' => $this->nthWeekdayOfMonth($y, 1, Carbon::MONDAY, 2), 'name' => '成人の日',     'is_recurring' => false];
            $out[] = ['date' => $this->nthWeekdayOfMonth($y, 7, Carbon::MONDAY, 3), 'name' => '海の日',       'is_recurring' => false];
            $out[] = ['date' => $this->nthWeekdayOfMonth($y, 9, Carbon::MONDAY, 3), 'name' => '敬老の日',     'is_recurring' => false];
            $out[] = ['date' => $this->nthWeekdayOfMonth($y, 10, Carbon::MONDAY, 2), 'name' => 'スポーツの日', 'is_recurring' => false];

            // Equinoxes — astronomical, hardcoded per year.
            if (isset($equinox[$y])) {
                $out[] = ['date' => $equinox[$y]['spring'], 'name' => '春分の日', 'is_recurring' => false];
                $out[] = ['date' => $equinox[$y]['autumn'], 'name' => '秋分の日', 'is_recurring' => false];
            }
        }

        return $out;
    }

    private function nthWeekdayOfMonth(int $year, int $month, int $dayOfWeek, int $n): string
    {
        $date = Carbon::create($year, $month, 1)->startOfDay();
        // Advance to the first occurrence of the target weekday.
        while ($date->dayOfWeek !== $dayOfWeek) {
            $date->addDay();
        }
        // Then jump (n - 1) weeks forward.
        $date->addWeeks($n - 1);

        return $date->toDateString();
    }

    /**
     * Vernal / autumnal equinox dates per NAOJ.
     *
     * @return array<int, array{spring: string, autumn: string}>
     */
    private function equinoxDates(): array
    {
        return [
            2024 => ['spring' => '2024-03-20', 'autumn' => '2024-09-22'],
            2025 => ['spring' => '2025-03-20', 'autumn' => '2025-09-23'],
            2026 => ['spring' => '2026-03-20', 'autumn' => '2026-09-23'],
            2027 => ['spring' => '2027-03-21', 'autumn' => '2027-09-23'],
            2028 => ['spring' => '2028-03-20', 'autumn' => '2028-09-22'],
            2029 => ['spring' => '2029-03-20', 'autumn' => '2029-09-23'],
            2030 => ['spring' => '2030-03-20', 'autumn' => '2030-09-23'],
        ];
    }
}
