<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════
//  tests/ScoreServiceTest.php
//  PHPUnit 10 — unit tests for services/ScoreService.php
//
//  Covers every penalty function, applyMissingLength,
//  scoreToCondition, conditionColour, and the
//  band edges in penaltyCyclistSlowed.
//
//  Run:
//    composer install
//    ./vendor/bin/phpunit --testdox
// ═══════════════════════════════════════════════════════════════

use PHPUnit\Framework\TestCase;

// ScoreService.php is autoloaded via composer.json "files" key.
// No require_once needed here.

class ScoreServiceTest extends TestCase
{
    // ── penaltyBufferZone ──────────────────────────────────────

    public function test_bufferZone_segregated_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyBufferZone('Segregated'));
    }

    public function test_bufferZone_bufferZone_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyBufferZone('Buffer Zone'));
    }

    public function test_bufferZone_none_returns_100(): void
    {
        $this->assertSame(100.0, penaltyBufferZone('None'));
    }

    public function test_bufferZone_null_returns_100(): void
    {
        $this->assertSame(100.0, penaltyBufferZone(null));
    }

    // ── penaltyLight ───────────────────────────────────────────

    public function test_light_yes_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyLight('Yes'));
    }

    public function test_light_partial_returns_50(): void
    {
        $this->assertSame(50.0, penaltyLight('Partial'));
    }

    public function test_light_no_returns_100(): void
    {
        $this->assertSame(100.0, penaltyLight('No'));
    }

    public function test_light_null_returns_100(): void
    {
        $this->assertSame(100.0, penaltyLight(null));
    }

    // ── penaltyTrafficCalming ──────────────────────────────────

    public function test_trafficCalming_zero_absent_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyTrafficCalming(0));
    }

    public function test_trafficCalming_one_absent_returns_50(): void
    {
        $this->assertSame(50.0, penaltyTrafficCalming(1));
    }

    public function test_trafficCalming_two_absent_returns_75(): void
    {
        $this->assertSame(75.0, penaltyTrafficCalming(2));
    }

    public function test_trafficCalming_three_or_more_returns_100(): void
    {
        $this->assertSame(100.0, penaltyTrafficCalming(3));
        $this->assertSame(100.0, penaltyTrafficCalming(10));
    }

    // ── penaltyPartialObstructions ─────────────────────────────

    public function test_partialObs_under5_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyPartialObstructions(0));
        $this->assertSame(0.0, penaltyPartialObstructions(4.9));
    }

    public function test_partialObs_5to10_returns_50(): void
    {
        $this->assertSame(50.0, penaltyPartialObstructions(5));
        $this->assertSame(50.0, penaltyPartialObstructions(10));
    }

    public function test_partialObs_over10_returns_100(): void
    {
        $this->assertSame(100.0, penaltyPartialObstructions(10.1));
        $this->assertSame(100.0, penaltyPartialObstructions(50));
    }

    // ── penaltyMissingRamps ────────────────────────────────────

    public function test_missingRamps_zero_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyMissingRamps(0));
    }

    public function test_missingRamps_one_returns_25(): void
    {
        $this->assertSame(25.0, penaltyMissingRamps(1));
        $this->assertSame(25.0, penaltyMissingRamps(2));
    }

    public function test_missingRamps_three_returns_50(): void
    {
        $this->assertSame(50.0, penaltyMissingRamps(3));
        $this->assertSame(50.0, penaltyMissingRamps(4));
    }

    public function test_missingRamps_five_or_more_returns_100(): void
    {
        $this->assertSame(100.0, penaltyMissingRamps(5));
        $this->assertSame(100.0, penaltyMissingRamps(99));
    }

    // ── penaltyMissingSignage ──────────────────────────────────

    public function test_missingSignage_zero_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyMissingSignage(0));
    }

    public function test_missingSignage_one_returns_50(): void
    {
        $this->assertSame(50.0, penaltyMissingSignage(1));
    }

    public function test_missingSignage_two_returns_75(): void
    {
        $this->assertSame(75.0, penaltyMissingSignage(2));
    }

    public function test_missingSignage_three_or_more_returns_100(): void
    {
        $this->assertSame(100.0, penaltyMissingSignage(3));
        $this->assertSame(100.0, penaltyMissingSignage(10));
    }

    // ── penaltyTotalObstructions ───────────────────────────────

    public function test_totalObs_under5_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyTotalObstructions(0));
        $this->assertSame(0.0, penaltyTotalObstructions(4.99));
    }

    public function test_totalObs_5to10_returns_50(): void
    {
        $this->assertSame(50.0, penaltyTotalObstructions(5));
        $this->assertSame(50.0, penaltyTotalObstructions(10));
    }

    public function test_totalObs_over10_returns_100(): void
    {
        $this->assertSame(100.0, penaltyTotalObstructions(11));
    }

    // ── penaltySurface ─────────────────────────────────────────

    public function test_surface_interlock_returns_100(): void
    {
        $this->assertSame(100.0, penaltySurface('Interlock Blocks'));
        $this->assertSame(100.0, penaltySurface('Interblocks'));
    }

    public function test_surface_concrete_returns_zero(): void
    {
        $this->assertSame(0.0, penaltySurface('Concrete'));
    }

    public function test_surface_asphalt_returns_zero(): void
    {
        $this->assertSame(0.0, penaltySurface('Asphalt'));
    }

    public function test_surface_null_returns_zero(): void
    {
        $this->assertSame(0.0, penaltySurface(null));
    }

    // ── penaltyCyclistSlowed — one scale for every road ────────

    public function test_cyclistSlowed_under5_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyCyclistSlowed(0));
        $this->assertSame(0.0, penaltyCyclistSlowed(4.9));
    }

    public function test_cyclistSlowed_5to10_returns_50(): void
    {
        $this->assertSame(50.0, penaltyCyclistSlowed(5));
        $this->assertSame(50.0, penaltyCyclistSlowed(10));
    }

    public function test_cyclistSlowed_10to20_returns_75(): void
    {
        $this->assertSame(75.0, penaltyCyclistSlowed(11));
        $this->assertSame(75.0, penaltyCyclistSlowed(20));
    }

    public function test_cyclistSlowed_over20_returns_100(): void
    {
        $this->assertSame(100.0, penaltyCyclistSlowed(21));
        $this->assertSame(100.0, penaltyCyclistSlowed(50));
    }

    // ── penaltyShade ───────────────────────────────────────────

    public function test_shade_yes_returns_zero(): void
    {
        $this->assertSame(0.0, penaltyShade('Yes'));
    }

    public function test_shade_null_returns_zero(): void
    {
        // xlsx: Shade NaN → 0, not penalised
        $this->assertSame(0.0, penaltyShade(null));
    }

    public function test_shade_partial_returns_50(): void
    {
        $this->assertSame(50.0, penaltyShade('Partial'));
    }

    public function test_shade_no_returns_100(): void
    {
        $this->assertSame(100.0, penaltyShade('No'));
    }

    // ── applyMissingLength ─────────────────────────────────────

    public function test_applyMissingLength_no_missing_returns_raw(): void
    {
        // presentLen = totalLen, missingLen = 0 → score unchanged
        $this->assertEqualsWithDelta(60.0, applyMissingLength(60.0, 0, 500, 500), 0.001);
    }

    public function test_applyMissingLength_all_missing_returns_100(): void
    {
        $this->assertEqualsWithDelta(100.0, applyMissingLength(60.0, 500, 0, 500), 0.001);
    }

    public function test_applyMissingLength_half_missing(): void
    {
        // (100×250 + 60×250) / 500 = 80
        $this->assertEqualsWithDelta(80.0, applyMissingLength(60.0, 250, 250, 500), 0.001);
    }

    public function test_applyMissingLength_zero_totalLen_returns_100(): void
    {
        $this->assertEqualsWithDelta(100.0, applyMissingLength(60.0, 0, 0, 0), 0.001);
    }

    // ── scoreToCondition ───────────────────────────────────────

    public function test_condition_good(): void
    {
        $this->assertSame('Good', scoreToCondition(0));
        $this->assertSame('Good', scoreToCondition(20));
    }

    public function test_condition_ok(): void
    {
        $this->assertSame('OK', scoreToCondition(20.1));
        $this->assertSame('OK', scoreToCondition(40));
    }

    public function test_condition_poor(): void
    {
        $this->assertSame('Poor', scoreToCondition(40.1));
        $this->assertSame('Poor', scoreToCondition(60));
    }

    public function test_condition_bad(): void
    {
        $this->assertSame('Bad', scoreToCondition(60.1));
        $this->assertSame('Bad', scoreToCondition(80));
    }

    public function test_condition_very_bad(): void
    {
        $this->assertSame('Very Bad', scoreToCondition(80.1));
        $this->assertSame('Very Bad', scoreToCondition(100));
    }

    // ── conditionColour ────────────────────────────────────────

    public function test_conditionColour_returns_correct_hex(): void
    {
        $this->assertSame('#27ae60', conditionColour('Good'));
        $this->assertSame('#f1c40f', conditionColour('OK'));
        $this->assertSame('#e67e22', conditionColour('Poor'));
        $this->assertSame('#e74c3c', conditionColour('Bad'));
        $this->assertSame('#8e1010', conditionColour('Very Bad'));
    }

    public function test_conditionColour_unknown_returns_grey(): void
    {
        $this->assertSame('#95a5a6', conditionColour('Unknown'));
    }

    // ── weighted final score formula check ────────────────────

    public function test_weighted_score_formula(): void
    {
        // All perfect → final should be 0
        $safety     = 0.0;
        $comfort    = 0.0;
        $continuity = 0.0;
        $final = ($safety * 1.0 + $comfort * 1.25 + $continuity * 1.5) / 3.75;
        $this->assertEqualsWithDelta(0.0, $final, 0.001);
    }

    public function test_weighted_score_all_worst(): void
    {
        // All worst → final should be 100
        $safety     = 100.0;
        $comfort    = 100.0;
        $continuity = 100.0;
        $final = ($safety * 1.0 + $comfort * 1.25 + $continuity * 1.5) / 3.75;
        $this->assertEqualsWithDelta(100.0, $final, 0.001);
    }

    public function test_weighted_score_continuity_weighs_more(): void
    {
        // Continuity (1.5) weighs more than Safety (1.0)
        // So bad continuity should pull the score higher than bad safety
        $badContinuity = (0.0 * 1.0 + 0.0 * 1.25 + 100.0 * 1.5) / 3.75; // 40.0
        $badSafety     = (100.0 * 1.0 + 0.0 * 1.25 + 0.0 * 1.5) / 3.75; // 26.67
        $this->assertGreaterThan($badSafety, $badContinuity);
    }
}
