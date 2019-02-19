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
        $fixture = new StabilityFlags;
        $got     = $fixture->extractAll([
            'test' => $this->makeLink($version)->reveal(),
        ]);

        if (isset($got['test'])) {
            static::assertEquals($expect, $got['test']);
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

        $got = $fixture->extractAll([
            'test' => $this->makeLink('@rc')->reveal(),
        ]);

        static::assertEquals(BasePackage::STABILITY_ALPHA, $got['test']);
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
        /** @var Link $link */
        $link = $this->prophesize('Composer\Package\Link');
        $link->getPrettyConstraint()->willReturn($version)->shouldBeCalled();

        return $link;
    }
}
