<?php namespace Arcanedev\Composer\Tests\Entities;

use Arcanedev\Composer\Entities\StabilityFlags;
use Arcanedev\Composer\Tests\TestCase;
use Composer\Package\BasePackage;
use Composer\Package\Link;

/**
 * Class     StabilityFlagsTest
 *
 * @package  Arcanedev\Composer\Tests\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class StabilityFlagsTest extends TestCase
{
    /* -----------------------------------------------------------------
     |  Tests
     | -----------------------------------------------------------------
     */

    /**
     * @test
     *
     * @dataProvider provideExplicitStability
     *
     * @param  string  $version
     * @param  string  $expect
     */
    public function it_can_extract_explicit_stability($version, $expect)
    {
        $actual = (new StabilityFlags)->extractAll([
            'test' => $this->makeLink($version)->reveal(),
        ]);

        if (isset($actual['test'])) {
            static::assertEquals($expect, $actual['test']);
        }
    }

    /**
     * Provide explicit stability array.
     *
     * @return array
     */
    public function provideExplicitStability()
    {
        return [
            '@dev'    => ['1.0@dev', BasePackage::STABILITY_DEV],
            'dev-'    => ['dev-master', BasePackage::STABILITY_DEV],
            '-dev'    => ['dev-master#2eb0c09', BasePackage::STABILITY_DEV],
            '@alpha'  => ['1.0@alpha', BasePackage::STABILITY_ALPHA],
            '@beta'   => ['1.0@beta', BasePackage::STABILITY_BETA],
            '@RC'     => ['1.0@RC', BasePackage::STABILITY_RC],
            '@stable' => ['1.0@stable', BasePackage::STABILITY_STABLE],
            '-dev & @stable' => [
                '1.0-dev as 1.0.0, 2.0', BasePackage::STABILITY_DEV
            ],
            '@dev | @stable' => [
                '1.0@dev || 2.0', BasePackage::STABILITY_DEV
            ],
            '@rc | @beta'   => [
                '1.0@rc || 2.0@beta', BasePackage::STABILITY_BETA
            ],
        ];
    }

    /** @test */
    public function it_can_extract_lowest_wins()
    {
        $fixture = new StabilityFlags([
            'test' => BasePackage::STABILITY_ALPHA,
        ]);

        $actual = $fixture->extractAll([
            'test' => $this->makeLink('@rc')->reveal(),
        ]);

        static::assertEquals(BasePackage::STABILITY_ALPHA, $actual['test']);
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Mock Link.
     *
     * @param  string  $version
     *
     * @return \Composer\Package\Link
     */
    protected function makeLink($version)
    {
        /** @var  \Composer\Package\Link  $link */
        $link = $this->prophesize(Link::class);
        $link->getPrettyConstraint()->willReturn($version)->shouldBeCalled();

        return $link;
    }
}
