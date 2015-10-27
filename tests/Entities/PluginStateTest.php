<?php namespace Arcanedev\Composer\Tests\Entities;

use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Tests\TestCase;

/**
 * Class PluginStateTest
 * @package Arcanedev\Composer\Tests\Entities
 */
class PluginStateTest extends TestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /** @var PluginState */
    private $pState;

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    public function setUp()
    {
        parent::setUp();

        /** @var \Composer\Composer $composer */
        $composer     = $this->prophesize('Composer\Composer')->reveal();
        $this->pState = new PluginState($composer);
    }

    public function tearDown()
    {
        parent::tearDown();

        unset($this->pState);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Test Functions
     | ------------------------------------------------------------------------------------------------
     */
    /** @test */
    public function it_can_state_locked()
    {
        $this->assertFalse($this->pState->isLocked());
        $this->assertTrue($this->pState->forceUpdate());

        $this->pState->setLocked(true);

        $this->assertTrue($this->pState->isLocked());
        $this->assertFalse($this->pState->forceUpdate());
    }

    /** @test */
    public function it_can_state_dump_autoloader()
    {
        $this->assertFalse($this->pState->shouldDumpAutoloader());

        $this->pState->setDumpAutoloader(true);

        $this->assertTrue($this->pState->shouldDumpAutoloader());
    }

    /** @test */
    public function it_can_state_optimize_autoloader()
    {
        $this->assertFalse($this->pState->shouldOptimizeAutoloader());

        $this->pState->setOptimizeAutoloader(true);

        $this->assertTrue($this->pState->shouldOptimizeAutoloader());
    }
}
