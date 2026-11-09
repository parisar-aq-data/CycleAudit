<?php

use PHPUnit\Framework\TestCase;

class ScoreHelpersTest extends TestCase
{
    /**
     * Test buffer zone scoring
     */
    public function testBufferZone()
    {
        $this->assertEquals(0.0, ScoreHelpers::bufferZone('Segregated'));
        $this->assertEquals(0.0, ScoreHelpers::bufferZone('Buffer Zone'));
        $this->assertEquals(100.0, ScoreHelpers::bufferZone('None'));
        $this->assertEquals(100.0, ScoreHelpers::bufferZone(null));
    }

    /**
     * Test light after dark scoring
     */
    public function testLightAfterDark()
    {
        $this->assertEquals(0.0, ScoreHelpers::lightAfterDark('Yes'));
        $this->assertEquals(50.0, ScoreHelpers::lightAfterDark('Partial'));
        $this->assertEquals(100.0, ScoreHelpers::lightAfterDark('No'));
        $this->assertEquals(100.0, ScoreHelpers::lightAfterDark(null));
    }

    /**
     * Test traffic calming
     */
    public function testTrafficCalming()
    {
        $this->assertEquals(0.0, ScoreHelpers::trafficCalming(0));
        $this->assertEquals(50.0, ScoreHelpers::trafficCalming(1));
        $this->assertEquals(75.0, ScoreHelpers::trafficCalming(2));
        $this->assertEquals(100.0, ScoreHelpers::trafficCalming(3));
    }

    /**
     * Test obstruction counts
     */
    public function testPartialObstructions()
    {
        $this->assertEquals(0.0, ScoreHelpers::partialObstructions(3));
        $this->assertEquals(50.0, ScoreHelpers::partialObstructions(7));
        $this->assertEquals(100.0, ScoreHelpers::partialObstructions(12));
    }

    /**
     * Test missing ramps
     */
    public function testMissingRamps()
    {
        $this->assertEquals(0.0, ScoreHelpers::missingRamps(0));
        $this->assertEquals(25.0, ScoreHelpers::missingRamps(1));
        $this->assertEquals(50.0, ScoreHelpers::missingRamps(3));
        $this->assertEquals(100.0, ScoreHelpers::missingRamps(5));
    }

    /**
     * Test missing signage
     */
    public function testMissingSignage()
    {
        $this->assertEquals(0.0, ScoreHelpers::missingSignage(0));
        $this->assertEquals(50.0, ScoreHelpers::missingSignage(1));
        $this->assertEquals(75.0, ScoreHelpers::missingSignage(2));
        $this->assertEquals(100.0, ScoreHelpers::missingSignage(3));
    }

    /**
     * Test surface material scoring
     */
    public function testSurface()
    {
        $this->assertEquals(0.0, ScoreHelpers::surface('Concrete'));
        $this->assertEquals(0.0, ScoreHelpers::surface('Asphalt'));
        $this->assertEquals(100.0, ScoreHelpers::surface('Interlock Blocks'));
        $this->assertEquals(100.0, ScoreHelpers::surface('Interblocks'));
    }

    /**
     * Test shade scoring
     */
    public function testShade()
    {
        $this->assertEquals(0.0, ScoreHelpers::shade('Yes'));
        $this->assertEquals(0.0, ScoreHelpers::shade(null));
        $this->assertEquals(50.0, ScoreHelpers::shade('Partial'));
        $this->assertEquals(100.0, ScoreHelpers::shade('No'));
    }

    /**
     * Test cyclist slowed scoring
     */
    public function testCyclistSlowed()
    {
        $this->assertEquals(0.0, ScoreHelpers::cyclistSlowed(3));
        $this->assertEquals(50.0, ScoreHelpers::cyclistSlowed(7));
        $this->assertEquals(75.0, ScoreHelpers::cyclistSlowed(15));
        $this->assertEquals(100.0, ScoreHelpers::cyclistSlowed(25));
    }

    /**
     * Test score to condition conversion
     */
    public function testScoreToCondition()
    {
        $this->assertEquals('Good', ScoreHelpers::scoreToCondition(15));
        $this->assertEquals('OK', ScoreHelpers::scoreToCondition(30));
        $this->assertEquals('Poor', ScoreHelpers::scoreToCondition(50));
        $this->assertEquals('Bad', ScoreHelpers::scoreToCondition(75));
        $this->assertEquals('Very Bad', ScoreHelpers::scoreToCondition(90));
    }

    /**
     * Test condition to color mapping
     */
    public function testConditionColor()
    {
        $this->assertEquals('#27ae60', ScoreHelpers::conditionColor('Good'));
        $this->assertEquals('#f1c40f', ScoreHelpers::conditionColor('OK'));
        $this->assertEquals('#e67e22', ScoreHelpers::conditionColor('Poor'));
        $this->assertEquals('#e74c3c', ScoreHelpers::conditionColor('Bad'));
        $this->assertEquals('#8e1010', ScoreHelpers::conditionColor('Very Bad'));
    }

    /**
     * Test missing length adjustment
     */
    public function testApplyMissingLength()
    {
        // No missing section
        $result = ScoreHelpers::applyMissingLength(50.0, 0.0, 500.0, 500.0);
        $this->assertEquals(50.0, $result);

        // Partial missing (50% present)
        $result = ScoreHelpers::applyMissingLength(50.0, 250.0, 250.0, 500.0);
        $this->assertEquals(75.0, $result);

        // All missing
        $result = ScoreHelpers::applyMissingLength(50.0, 500.0, 0.0, 500.0);
        $this->assertEquals(100.0, $result);
    }
}
