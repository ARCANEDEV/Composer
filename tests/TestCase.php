<?php namespace Arcanedev\Composer\Tests;

use Composer\Package\BasePackage;
use Prophecy\Argument;

/**
 * Class TestCase
 * @package Arcanedev\Composer\Tests
 */
abstract class TestCase extends \PHPUnit_Framework_TestCase
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
     * @return mixed
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
                'conflict'      => [],
                'replace'       => [],
                'provide'       => [],
                'suggest'       => [],
                'extra'         => [],
                'autoload'      => [],
                'autoload-dev'  => [],
            ],
            $json
        );

        /** @var mixed $root */
        $root = $this->prophesize('Composer\\Package\\RootPackage');
        $root->getRequires()->willReturn($data['require'])->shouldBeCalled();
        $root->getDevRequires()->willReturn($data['require-dev']);
        $root->getRepositories()->willReturn($data['repositories']);
        $root->getConflicts()->willReturn($data['conflict']);
        $root->getReplaces()->willReturn($data['replace']);
        $root->getProvides()->willReturn($data['provide']);
        $root->getSuggests()->willReturn($data['suggest']);
        $root->getExtra()->willReturn($data['extra'])->shouldBeCalled();
        $root->getAutoload()->willReturn($data['autoload']);
        $root->getDevAutoload()->willReturn($data['autoload-dev']);
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
