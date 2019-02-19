<?php namespace Arcanedev\Composer\Tests\Entities;

use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Tests\TestCase;

/**
 * Class     PluginStateTest
 *
 * @package  Arcanedev\Composer\Tests\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class PluginStateTest extends TestCase
{
    /* -----------------------------------------------------------------
     |  Properties
     | -----------------------------------------------------------------
     */

    /** @var \Arcanedev\Composer\Entities\PluginState */
    private $pState;

    /* -----------------------------------------------------------------
     |  Main Methods
     | -----------------------------------------------------------------
     */

    public function setUp()
    {
        parent::setUp();

        $this->pState = new PluginState(
            $this->prophesize('Composer\Composer')->reveal()
        );
    }

    public function tearDown()
    {
        unset($this->pState);

        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     |  Tests
     | -----------------------------------------------------------------
     */

    /** @test */
    public function it_can_state_locked()
    {
        static::assertFalse($this->pState->isLocked());
        static::assertTrue($this->pState->forceUpdate());

        $this->pState->setLocked(true);

        static::assertTrue($this->pState->isLocked());
        static::assertFalse($this->pState->forceUpdate());
    }

    /** @test */
    public function it_can_state_dump_autoloader()
    {
        static::assertFalse($this->pState->shouldDumpAutoloader());

        $this->pState->setDumpAutoloader(true);

        static::assertTrue($this->pState->shouldDumpAutoloader());
    }

    /** @test */
    public function it_can_state_optimize_autoloader()
    {
        static::assertFalse($this->pState->shouldOptimizeAutoloader());

        $this->pState->setOptimizeAutoloader(true);

        static::assertTrue($this->pState->shouldOptimizeAutoloader());
    }
}
