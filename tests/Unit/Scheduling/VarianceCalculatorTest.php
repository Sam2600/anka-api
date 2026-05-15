<?php

namespace Tests\Unit\Scheduling;

use App\Models\PhaseProgressLog;
use App\Models\ProjectTaskPhaseAssignment;
use App\Services\Scheduling\VarianceCalculator;
use App\Services\Scheduling\WorkingDayCalendar;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Reproduces the six patterns from schedule_tracking_pattern.xlsx. Each test
 * is a regression net for the variance formula — keep these passing as the
 * formula evolves.
 */
class VarianceCalculatorTest extends TestCase
{
    private function makePhase(array $attrs, array $logs = []): ProjectTaskPhaseAssignment
    {
        $phase = new ProjectTaskPhaseAssignment(array_merge([
            'phase_code'      => 'development',
            'phase_name'      => 'Development',
            'phase_order'     => 5,
            'estimated_hours' => 8,
            'planned_start'   => '2026-05-15',
            'planned_end'     => '2026-05-15',
            'status'          => '未着手',
        ], $attrs));

        if (isset($attrs['actual_end'])) {
            $phase->actual_end = $attrs['actual_end'];
        }

        $logCollection = new Collection(array_map(
            fn ($l) => new PhaseProgressLog($l),
            $logs,
        ));
        $phase->setRelation('progressLogs', $logCollection);

        return $phase;
    }

    private function calc(string $asOf = '2026-05-15'): VarianceCalculator
    {
        return new VarianceCalculator(new WorkingDayCalendar(), Carbon::parse($asOf));
    }

    public function test_pattern_1_1_on_time(): void
    {
        $phase = $this->makePhase(
            ['actual_end' => '2026-05-15'],
            [['progress_hours' => 8, 'used_hours' => 8]],
        );

        $r = $this->calc()->forPhase($phase);

        $this->assertSame('completed_on_time', $r['schedule_state']);
        $this->assertEqualsWithDelta(0.0, $r['variance_hours'], 0.01);
        $this->assertSame('on_track', $r['health']);
        $this->assertTrue($r['is_completed']);
    }

    public function test_pattern_1_2_late(): void
    {
        $phase = $this->makePhase(
            [], // no actual_end, in-flight
            [['progress_hours' => 6, 'used_hours' => 8]],
        );

        $r = $this->calc()->forPhase($phase);

        $this->assertSame('late', $r['schedule_state']);
        // expected_progress is 8 (asOf >= planned_end), actual is 6 → -2.
        $this->assertEqualsWithDelta(-2.0, $r['variance_hours'], 0.01);
        // |−2| / 8 = 25% → slipping (>10%).
        $this->assertSame('slipping', $r['health']);
    }

    public function test_pattern_1_3_early(): void
    {
        $phase = $this->makePhase(
            ['actual_end' => '2026-05-15'],
            [['progress_hours' => 8, 'used_hours' => 7]],
        );

        $r = $this->calc()->forPhase($phase);

        $this->assertSame('completed_early', $r['schedule_state']);
        $this->assertEqualsWithDelta(1.0, $r['variance_hours'], 0.01);
        // Positive variance = ahead of plan. PMs don't need a warning badge for
        // delivering more than planned, so health stays on_track regardless of
        // magnitude. (Previously this was 'slipping' because the classifier used
        // abs(variance) — fixed in the asymmetric-health change.)
        $this->assertSame('on_track', $r['health']);
    }

    public function test_pattern_1_4_recovery_eats_today(): void
    {
        // Day 1 dev: planned 8, used 8, progress 6 (slipped 2).
        // Day 2 dev: catch-up 2h used, 2h progress (cumulative dev: P=8, U=10, completed).
        $dev = $this->makePhase(
            ['actual_end' => '2026-05-16'],
            [
                ['progress_hours' => 6, 'used_hours' => 8],  // day 1
                ['progress_hours' => 2, 'used_hours' => 2],  // day 2 catch-up
            ],
        );
        $devVar = $this->calc('2026-05-16')->forPhase($dev);
        // Dev is now completed with U=10, H=8 → over budget 2.
        $this->assertSame('completed_over_budget', $devVar['schedule_state']);
        $this->assertEqualsWithDelta(-2.0, $devVar['variance_hours'], 0.01);

        // Day 2 unit_test: planned 8, used 6 (rest went to dev catch-up), progress 7.
        $unit = $this->makePhase(
            [
                'phase_code'      => 'unit_test',
                'phase_name'      => '単体テスト',
                'phase_order'     => 6,
                'estimated_hours' => 8,
                'planned_start'   => '2026-05-16',
                'planned_end'     => '2026-05-16',
            ],
            [['progress_hours' => 7, 'used_hours' => 6]],
        );
        $unitVar = $this->calc('2026-05-16')->forPhase($unit);

        // expected_progress = 8 (asOf >= planned_end), actual = 7 → late 1.
        $this->assertSame('late', $unitVar['schedule_state']);
        $this->assertEqualsWithDelta(-1.0, $unitVar['variance_hours'], 0.01);
        // |−1| / 8 = 12.5% → slipping (just barely).
        $this->assertSame('slipping', $unitVar['health']);
    }

    public function test_pattern_1_5_recovery_absorbed(): void
    {
        // Same dev arc as 1.4 (completed_over_budget 2).
        $dev = $this->makePhase(
            ['actual_end' => '2026-05-16'],
            [
                ['progress_hours' => 6, 'used_hours' => 8],
                ['progress_hours' => 2, 'used_hours' => 2],
            ],
        );
        $devVar = $this->calc('2026-05-16')->forPhase($dev);
        $this->assertEqualsWithDelta(-2.0, $devVar['variance_hours'], 0.01);

        // Unit test fully completed in 6h: H=8, P=8, U=6 → completed_early 2.
        $unit = $this->makePhase(
            [
                'phase_code'      => 'unit_test',
                'phase_name'      => '単体テスト',
                'phase_order'     => 6,
                'estimated_hours' => 8,
                'planned_start'   => '2026-05-16',
                'planned_end'     => '2026-05-16',
                'actual_end'      => '2026-05-16',
            ],
            [['progress_hours' => 8, 'used_hours' => 6]],
        );
        $unitVar = $this->calc('2026-05-16')->forPhase($unit);
        $this->assertSame('completed_early', $unitVar['schedule_state']);
        $this->assertEqualsWithDelta(2.0, $unitVar['variance_hours'], 0.01);

        // Rolled up across both: dev −2 + unit +2 = 0 net.
        $rollup = (new VarianceCalculator(new WorkingDayCalendar(), Carbon::parse('2026-05-16')))
            ->rollup([$devVar, $unitVar], [8, 8]);

        $this->assertEqualsWithDelta(0.0, $rollup['variance_hours'], 0.01);
        $this->assertSame('on_track', $rollup['health']);
    }

    public function test_multi_day_phase_uses_start_day_hours_for_today_line(): void
    {
        // 17h phase split across Tue 2h + Wed 8h + Thu 7h (matches Pattern D
        // canonical scenario for T3 development). At EOD Wed, expected
        // progress should be 2 + 8 = 10h — NOT the old linear (2/3)*17 = 11.33h.
        $phase = $this->makePhase([
            'estimated_hours' => 17,
            'start_day_hours' => 2,
            'planned_start'   => '2026-06-02', // Tue
            'planned_end'     => '2026-06-04', // Thu
        ]);

        // As-of Tue (day 1) → expected = start_day_hours = 2h.
        $rTue = $this->calc('2026-06-02')->forPhase($phase);
        $this->assertEqualsWithDelta(2.0, $rTue['expected_progress_hours'], 0.01);

        // As-of Wed (day 2 of 3) → expected = 2 + 8 = 10h.
        $rWed = $this->calc('2026-06-03')->forPhase($phase);
        $this->assertEqualsWithDelta(10.0, $rWed['expected_progress_hours'], 0.01);

        // As-of Thu (planned_end) → expected = 17h.
        $rThu = $this->calc('2026-06-04')->forPhase($phase);
        $this->assertEqualsWithDelta(17.0, $rThu['expected_progress_hours'], 0.01);
    }

    public function test_legacy_phase_with_null_start_day_hours_remains_back_compatible(): void
    {
        // A single-day legacy row from before the hour-packing change.
        // start_day_hours is NULL — the reconstruction must still produce the
        // same expected_progress as the old linear-prorating code did:
        //   asOf < planned_start  → 0
        //   asOf >= planned_end   → estimated
        $phase = $this->makePhase(
            [
                'estimated_hours' => 8,
                'planned_start'   => '2026-05-15',
                'planned_end'     => '2026-05-15',
                // start_day_hours intentionally omitted → NULL
            ],
            [['progress_hours' => 8, 'used_hours' => 8]],
        );
        $phase->actual_end = '2026-05-15';

        $r = $this->calc('2026-05-15')->forPhase($phase);

        // Single-day, asOf == planned_end → expected = estimated.
        $this->assertEqualsWithDelta(8.0, $r['expected_progress_hours'], 0.01);
        $this->assertSame('completed_on_time', $r['schedule_state']);
    }

    public function test_pattern_1_6_cross_developer_offset(): void
    {
        // Sam's task — late 2.
        $sam = $this->makePhase(
            ['actual_end' => '2026-05-15'],
            [['progress_hours' => 6, 'used_hours' => 8]],
        );
        // Sam ended his day with actual_end set but progress < estimated.
        // The variance formula for completed phases uses Used; H=8, U=8 → variance 0.
        // To match the spec ("Sam still individually late"), Pattern 1.6 should be
        // interpreted as a still-in-flight task at end-of-day — drop actual_end.
        $sam = $this->makePhase(
            [],
            [['progress_hours' => 6, 'used_hours' => 8]],
        );
        $samVar = $this->calc()->forPhase($sam);
        $this->assertSame('late', $samVar['schedule_state']);
        $this->assertEqualsWithDelta(-2.0, $samVar['variance_hours'], 0.01);

        // Jenny's task — early 2.
        $jenny = $this->makePhase(
            ['actual_end' => '2026-05-15'],
            [['progress_hours' => 8, 'used_hours' => 6]],
        );
        $jennyVar = $this->calc()->forPhase($jenny);
        $this->assertSame('completed_early', $jennyVar['schedule_state']);
        $this->assertEqualsWithDelta(2.0, $jennyVar['variance_hours'], 0.01);

        // Project rollup nets to 0, but Sam stays late and Jenny stays early in their per-row data.
        $rollup = (new VarianceCalculator(new WorkingDayCalendar(), Carbon::parse('2026-05-15')))
            ->rollup([$samVar, $jennyVar], [8, 8]);
        $this->assertEqualsWithDelta(0.0, $rollup['variance_hours'], 0.01);
        $this->assertSame('on_track', $rollup['health']);
    }
}
