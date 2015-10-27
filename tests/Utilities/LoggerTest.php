<?php namespace Arcanedev\Composer\Tests\Utilities;

use Arcanedev\Composer\Tests\TestCase;
use Arcanedev\Composer\Utilities\Logger;
use Prophecy\Argument;

/**
 * Class     LoggerTest
 *
 * @package  Arcanedev\Composer\Tests\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class LoggerTest extends TestCase
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
    public function it_can_run_on_very_verbose_debug()
    {
        $output = [];
        $this->io->isVeryVerbose()->willReturn(true)->shouldBeCalled();
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
    public function it_can_not_run_on_very_verbose_debug()
    {
        $this->io->isVeryVerbose()->willReturn(false)->shouldBeCalled();
        $this->io->writeError(Argument::type('string'))->shouldNotBeCalled();

        $logger = new Logger('test', $this->io->reveal());
        $logger->debug('foo');
    }

    /** @test */
    public function it_can_run_on_verbose_info()
    {
        $output = [];
        $io     = $this->prophesize('Composer\IO\IOInterface');

        $io->isVerbose()->willReturn(true)->shouldBeCalled();
        $io->writeError(Argument::type('string'))->will(
            function ($args) use (&$output) {
                $output[] = $args[0];
            }
        )->shouldBeCalled();

        $io->write(Argument::type('string'))->shouldNotBeCalled();

        $fixture = new Logger('test', $io->reveal());
        $fixture->info('foo');

        $this->assertEquals(1, count($output));
        $this->assertContains('<info>[test]</info>', $output[0]);
    }

    /** @test */
    public function it_can_not_run_on_verbose_info()
    {
        $output = [];
        $io     = $this->prophesize('Composer\IO\IOInterface');

        $io->isVerbose()->willReturn(false)->shouldBeCalled();
        $io->writeError(Argument::type('string'))->shouldNotBeCalled();
        $io->write(Argument::type('string'))->shouldNotBeCalled();

        $fixture = new Logger('test', $io->reveal());
        $fixture->info('foo');
    }

    /** @test */
    public function it_can_run_on_warning()
    {
        $output = array();
        $io = $this->prophesize('Composer\IO\IOInterface');

        $io->writeError(Argument::type('string'))->will(
            function ($args) use (&$output) {
                $output[] = $args[0];
            }
        )->shouldBeCalled();

        $io->write(Argument::type('string'))->shouldNotBeCalled();

        $fixture = new Logger('test', $io->reveal());
        $fixture->warning('foo');

        $this->assertEquals(1, count($output));
        $this->assertContains('<error>[test]</error>', $output[0]);
    }
}
