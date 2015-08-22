<?php namespace Arcanedev\Composer\Tests\Utilities;

use Arcanedev\Composer\Utilities\Logger;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTestCase;

/**
 * Class LoggerTest
 * @package Arcanedev\Composer\Tests\Utilities
 */
class LoggerTest extends ProphecyTestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var \Composer\IO\IOInterface
     */
    private $io;

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    public function setUp()
    {
        parent::setUp();

        $this->io = $this->prophesize('Composer\IO\IOInterface');

        $this->io->write(Argument::type('string'))->shouldNotBeCalled();
    }

    public function tearDown()
    {
        parent::tearDown();

        unset($this->io);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Test Functions
     | ------------------------------------------------------------------------------------------------
     */
    /** @test */
    public function it_can_run_on_verbose_debug()
    {
        $output = [];
        $this->io->isVerbose()->willReturn(true)->shouldBeCalled();
        $this->io->writeError(Argument::type('string'))
            ->will(function ($args) use (&$output) {
                $output[] = $args[0];
            })
            ->shouldBeCalled();

        $logger = new Logger('test', $this->io->reveal());
        $logger->debug('foo');
        $this->assertEquals(1, count($output));
        $this->assertContains('<info>[test]</info>', $output[0]);
    }

    /** @test */
    public function it_can_not_run_on_verbose_debug()
    {
        $this->io->isVerbose()->willReturn(false)->shouldBeCalled();
        $this->io->writeError(Argument::type('string'))->shouldNotBeCalled();

        $logger = new Logger('test', $this->io->reveal());
        $logger->debug('foo');
    }
}
