<?php namespace Arcanedev\Composer\Tests;

use Composer\Package\BasePackage;
use PHPUnit_Framework_TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

abstract class TestCase extends PHPUnit_Framework_TestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Common Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get fixture directory
     *
     * @param  string $directory
     *
     * @return string
     */
    protected function fixtureDir($directory)
    {
        return __DIR__ . "/fixtures/{$directory}";
    }

    /**
     * @param  string $file
     *
     * @return ObjectProphecy
     */
    protected function rootFromJson($file)
    {
        $that = $this;
        $json = json_decode(file_get_contents($file), true);
        $data = array_merge(
            [
                'repositories'  => [],
                'require'       => [],
                'require-dev'   => [],
                'suggest'       => [],
                'extra'         => [],
                'autoload'      => [],
            ],
            $json
        );

        /** @var ObjectProphecy $root */
        $root = $this->prophesize('Composer\Package\RootPackage');
        $root->getRequires()->willReturn($data['require'])->shouldBeCalled();
        $root->getDevRequires()->willReturn($data['require-dev']);
        $root->getRepositories()->willReturn($data['repositories']);
        $root->getSuggests()->willReturn($data['suggest']);
        $root->getExtra()->willReturn($data['extra'])->shouldBeCalled();
        $root->getAutoload()->willReturn($data['autoload']);
        $root->getStabilityFlags()->willReturn([]);
        $root->setStabilityFlags(Argument::type('array'))->will(
            function ($args) use ($that) {
                foreach ($args[0] as $key => $value) {
                    $that->assertContains($value, BasePackage::$stabilities);
                }
            }
        );

        return $root;
    }
}
