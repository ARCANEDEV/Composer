<?php namespace Arcanedev\Composer\Tests;

use Composer\Package\BasePackage;
use PHPUnit_Framework_TestCase;
use Prophecy\Argument;
use Prophecy\Prophecy\ObjectProphecy;

/**
 * Class     TestCase
 *
 * @package  Arcanedev\Composer\Tests
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
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
     * @return mixed
     */
    protected function rootFromJson($file)
    {
        $that = $this;
        $json = json_decode(file_get_contents($file), true);
        $data = array_merge(
            [
                'repositories'      => [],
                'require'           => [],
                'require-dev'       => [],
                'conflict'          => [],
                'replace'           => [],
                'provide'           => [],
                'suggest'           => [],
                'extra'             => [],
                'autoload'          => [],
                'autoload-dev'      => [],
                'minimum-stability' => 'stable',
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
        $root->getMinimumStability()->willReturn($data['minimum-stability']);
        $root->setStabilityFlags(Argument::type('array'))->will(
            function ($args) use ($that) {
                foreach ($args[0] as $key => $value) {
                    $that->assertContains($value, BasePackage::$stabilities);
                }
            }
        );


        return $root;
    }

    /**
     * Wrap a package in a mocked alias.
     *
     * @param  object  $root
     *
     * @return ObjectProphecy
     */
    protected function makeAliasFor($root)
    {
        /** @var mixed $alias */
        $alias = $this->prophesize('Composer\Package\RootAliasPackage');

        $alias->getAliasOf()->willReturn($root);

        $alias->getAliases()
            ->will(function() use ($root) { return $root->getAliases(); });

        $alias->getAutoload()
            ->will(function() use ($root) { return $root->getAutoload(); });

        $alias->getConflicts()
            ->will(function() use ($root) { return $root->getConflicts(); });

        $alias->getDevAutoload()
            ->will(function() use ($root) { return $root->getDevAutoload(); });

        $alias->getDevRequires()
            ->will(function() use ($root) { return $root->getDevRequires(); });

        $alias->getExtra()
            ->will(function() use ($root) { return $root->getExtra(); });

        $alias->getProvides()
            ->will(function() use ($root) { return $root->getProvides(); });

        $alias->getReferences()
            ->will(function() use ($root) { return $root->getReferences(); });

        $alias->getReplaces()
            ->will(function() use ($root) { return $root->getReplaces(); });

        $alias->getRepositories()
            ->will(function() use ($root) { return $root->getRepositories(); });

        $alias->getRequires()
            ->will(function() use ($root) { return $root->getRequires(); });

        $alias->getStabilityFlags()
            ->will(function() use ($root) { return $root->getStabilityFlags(); });

        $alias->getMinimumStability()
            ->will(function() use ($root) { return $root->getMinimumStability(); });

        $alias->getSuggests()
            ->will(function() use ($root) { return $root->getSuggests(); });

        return $alias;
    }
}
