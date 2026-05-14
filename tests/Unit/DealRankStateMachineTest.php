<?php

namespace Tests\Unit;

use App\Models\Deal;
use Carbon\Carbon;
use Tests\TestCase;

class DealRankStateMachineTest extends TestCase
{
    private const CONFIRMED_AT = '2026-05-14 10:00:00';

    private function makeDeal(string $status, string $lifecycleStatus = 'active', array $overrides = []): Deal
    {
        $deal = new Deal;
        $deal->status = $status;
        $deal->lifecycle_status = $lifecycleStatus;

        foreach ($overrides as $key => $value) {
            $deal->{$key} = $value;
        }

        return $deal;
    }

    public function test_status_to_rank_maps_correctly(): void
    {
        $this->assertSame('C', $this->makeDeal('lead')->rank);
        $this->assertSame('B', $this->makeDeal('qualified')->rank);
        $this->assertSame('A', $this->makeDeal('negotiation')->rank);
        $this->assertSame('S', $this->makeDeal('won')->rank);
    }

    public function test_dropped_deal_reports_dropped_rank(): void
    {
        $deal = $this->makeDeal('qualified', 'dropped');

        $this->assertSame('Dropped', $deal->rank);
        $this->assertTrue($deal->isDropped());
    }

    public function test_can_transition_forward_one_step(): void
    {
        $this->assertTrue($this->makeDeal('lead')->canTransitionTo('qualified'));
        $this->assertTrue($this->makeDeal('qualified')->canTransitionTo('negotiation'));
        $this->assertTrue($this->makeDeal('negotiation')->canTransitionTo('won'));
    }

    public function test_cannot_skip_stages(): void
    {
        $this->assertFalse($this->makeDeal('lead')->canTransitionTo('negotiation'));
        $this->assertFalse($this->makeDeal('lead')->canTransitionTo('won'));
        $this->assertFalse($this->makeDeal('qualified')->canTransitionTo('won'));
    }

    public function test_cannot_transition_backward(): void
    {
        $this->assertFalse($this->makeDeal('qualified')->canTransitionTo('lead'));
        $this->assertFalse($this->makeDeal('negotiation')->canTransitionTo('qualified'));
        $this->assertFalse($this->makeDeal('negotiation')->canTransitionTo('lead'));
        $this->assertFalse($this->makeDeal('won')->canTransitionTo('negotiation'));
    }

    public function test_won_deals_cannot_transition_anywhere(): void
    {
        $deal = $this->makeDeal('won');

        $this->assertFalse($deal->canTransitionTo('lead'));
        $this->assertFalse($deal->canTransitionTo('qualified'));
        $this->assertFalse($deal->canTransitionTo('negotiation'));
        $this->assertFalse($deal->canTransitionTo('won'));
    }

    public function test_dropped_deals_cannot_transition_anywhere(): void
    {
        $deal = $this->makeDeal('qualified', 'dropped');

        $this->assertFalse($deal->canTransitionTo('lead'));
        $this->assertFalse($deal->canTransitionTo('qualified'));
        $this->assertFalse($deal->canTransitionTo('negotiation'));
        $this->assertFalse($deal->canTransitionTo('won'));
    }

    public function test_is_locked_only_in_a_or_s(): void
    {
        $this->assertFalse($this->makeDeal('lead')->isLocked());
        $this->assertFalse($this->makeDeal('qualified')->isLocked());
        $this->assertTrue($this->makeDeal('negotiation')->isLocked());
        $this->assertTrue($this->makeDeal('won')->isLocked());
    }

    public function test_dropped_deals_are_not_locked(): void
    {
        $this->assertFalse($this->makeDeal('negotiation', 'dropped')->isLocked());
        $this->assertFalse($this->makeDeal('won', 'dropped')->isLocked());
    }

    public function test_locked_fields_list_only_when_locked(): void
    {
        $this->assertSame([], $this->makeDeal('lead')->lockedFields());
        $this->assertSame([], $this->makeDeal('qualified')->lockedFields());
        $this->assertContains('final_monthly_fee', $this->makeDeal('negotiation')->lockedFields());
        $this->assertContains('workload_description', $this->makeDeal('won')->lockedFields());
    }

    public function test_can_be_dropped_only_in_c_b_a(): void
    {
        $this->assertTrue($this->makeDeal('lead')->canBeDropped());
        $this->assertTrue($this->makeDeal('qualified')->canBeDropped());
        $this->assertTrue($this->makeDeal('negotiation')->canBeDropped());
    }

    public function test_cannot_drop_won_deal(): void
    {
        $this->assertFalse($this->makeDeal('won')->canBeDropped());
    }

    public function test_cannot_drop_already_dropped_deal(): void
    {
        $this->assertFalse($this->makeDeal('qualified', 'dropped')->canBeDropped());
    }

    public function test_cannot_drop_legacy_lost_deal(): void
    {
        $this->assertFalse($this->makeDeal('lost')->canBeDropped());
    }

    public function test_is_contract_eligible_requires_b_rank(): void
    {
        $full = [
            'final_monthly_fee' => 5000,
            'final_contract_months' => 12,
            'final_team_summary' => 'Two backend engineers',
            'final_currency' => 'USD',
            'final_confirmed_at' => Carbon::parse(self::CONFIRMED_AT),
        ];

        $this->assertFalse($this->makeDeal('lead', 'active', $full)->isContractEligible());
        $this->assertTrue($this->makeDeal('qualified', 'active', $full)->isContractEligible());
        $this->assertFalse($this->makeDeal('negotiation', 'active', $full)->isContractEligible());
        $this->assertFalse($this->makeDeal('won', 'active', $full)->isContractEligible());
    }

    public function test_is_contract_eligible_requires_all_final_fields(): void
    {
        $missingMonthly = [
            'final_contract_months' => 12,
            'final_team_summary' => 'Team',
            'final_currency' => 'USD',
            'final_confirmed_at' => Carbon::parse(self::CONFIRMED_AT),
        ];

        $deal = $this->makeDeal('qualified', 'active', $missingMonthly);

        $this->assertFalse($deal->isContractEligible());
        $this->assertSame(['final_monthly_fee'], $deal->missingEstimationFields());
    }

    public function test_is_contract_eligible_rejects_dropped_deal(): void
    {
        $deal = $this->makeDeal('qualified', 'dropped', [
            'final_monthly_fee' => 5000,
            'final_contract_months' => 12,
            'final_team_summary' => 'Team',
            'final_currency' => 'USD',
            'final_confirmed_at' => Carbon::parse(self::CONFIRMED_AT),
        ]);

        $this->assertFalse($deal->isContractEligible());
    }

    public function test_missing_estimation_fields_lists_every_blank_required_field(): void
    {
        $deal = $this->makeDeal('qualified');

        $missing = $deal->missingEstimationFields();

        $this->assertContains('final_monthly_fee', $missing);
        $this->assertContains('final_contract_months', $missing);
        $this->assertContains('final_team_summary', $missing);
        $this->assertContains('final_currency', $missing);
        $this->assertContains('final_confirmed_at', $missing);
        $this->assertCount(5, $missing);
    }

    public function test_rank_probability_constants_match_spec(): void
    {
        $this->assertSame(30, Deal::RANK_PROBABILITY['C']);
        $this->assertSame(50, Deal::RANK_PROBABILITY['B']);
        $this->assertSame(80, Deal::RANK_PROBABILITY['A']);
        $this->assertSame(100, Deal::RANK_PROBABILITY['S']);
    }

    public function test_lock_violations_empty_when_unlocked(): void
    {
        $deal = $this->makeDeal('lead');

        $this->assertSame([], $deal->lockViolations(['workload_description', 'final_monthly_fee']));
    }

    public function test_lock_violations_lists_blocked_fields_in_a(): void
    {
        $deal = $this->makeDeal('negotiation');

        $errors = $deal->lockViolations(['name', 'workload_description', 'final_monthly_fee', 'contact_phone']);

        // Non-locked fields pass through; locked fields are flagged.
        $this->assertArrayNotHasKey('name', $errors);
        $this->assertArrayNotHasKey('contact_phone', $errors);
        $this->assertArrayHasKey('workload_description', $errors);
        $this->assertArrayHasKey('final_monthly_fee', $errors);
        $this->assertStringContainsString('rank A', $errors['workload_description'][0]);
    }

    public function test_lock_violations_lists_blocked_fields_in_s(): void
    {
        $deal = $this->makeDeal('won');

        $errors = $deal->lockViolations(['timeline_months', 'client_budget']);

        $this->assertArrayHasKey('timeline_months', $errors);
        $this->assertArrayHasKey('client_budget', $errors);
        $this->assertStringContainsString('rank S', $errors['timeline_months'][0]);
    }

    public function test_drop_refuses_won_deal(): void
    {
        $deal = $this->makeDeal('won');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('rank S cannot be dropped');

        $deal->drop('customer cancelled');
    }

    public function test_drop_refuses_already_dropped_deal(): void
    {
        $deal = $this->makeDeal('qualified', 'dropped');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('already dropped');

        $deal->drop('redundant call');
    }
}
