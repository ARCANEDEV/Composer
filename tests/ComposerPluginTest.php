<?php namespace Arcanedev\Composer\Tests;

use Arcanedev\Composer\ComposerPlugin;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Prophecy\Argument;
use ReflectionMethod;

/**
 * Class ComposerPluginTest
 * @package Arcanedev\Composer\Tests
 */
class ComposerPluginTest extends TestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var Composer
     */
    protected $composer;

    /**
     * @var IOInterface
     */
    protected $io;

    /**
     * @var ComposerPlugin
     */
    protected $plugin;

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    public function setUp()
    {
        parent::setUp();

        $this->composer = $this->prophesize('Composer\\Composer');
        $this->io       = $this->prophesize('Composer\\IO\\IOInterface');
        $this->plugin   = new ComposerPlugin;

        $this->plugin->activate(
            $this->composer->reveal(),
            $this->io->reveal()
        );
    }

    public function tearDown()
    {
        parent::tearDown();

        unset($this->plugin);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Test Functions
     | ------------------------------------------------------------------------------------------------
     */
    /** @test */
    public function it_can_get_subscribed_events()
    {
        $subscriptions = ComposerPlugin::getSubscribedEvents();
        $events        = [
            ScriptEvents::PRE_INSTALL_CMD,
            ScriptEvents::PRE_UPDATE_CMD,
            ScriptEvents::PRE_AUTOLOAD_DUMP,
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            PackageEvents::POST_PACKAGE_INSTALL,
            ScriptEvents::POST_INSTALL_CMD,
            ScriptEvents::POST_UPDATE_CMD,
        ];

        $this->assertEquals(count($events), count($subscriptions));


        foreach ($events as $event) {
            $this->assertArrayHasKey($event, $subscriptions);
        }
    }

    /** @test */
    public function it_can_one_merge_no_conflicts()
    {
        $that = $this;
        $dir  = $this->fixtureDir('one-merge-no-conflicts');
        $root = $this->rootFromJson("{$dir}/composer.json");


        $root->setRequires(Argument::type('array'))
            ->will(function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(1, count($requires));
                $that->assertArrayHasKey('monolog/monolog', $requires);
            });

        $root->getSuggests()->shouldBeCalled();
        $root->setSuggests(Argument::type('array'))
            ->will(function ($args) use ($that) {
                $suggest = $args[0];
                $that->assertEquals(1, count($suggest));
                $that->assertArrayHasKey('ext-apc', $suggest);
            });

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * Given a root package with requires
     *   and a composer.local.json with requires
     *   and the same package is listed in multiple files
     * When the plugin is run
     * Then the root package should inherit the non-conflicting requires
     *   and conflicting requires should be resolved 'last defined wins'.
     */
    public function it_can_merge_with_replace()
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-with-replace');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('monolog/monolog', $requires);
                $that->assertEquals(
                    '1.10.0',
                    $requires['monolog/monolog']->getPrettyConstraint()
                );
            }
        );

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_recursive_includes()
    {
        $dir      = $this->fixtureDir('recursive-includes');
        $root     = $this->rootFromJson("{$dir}/composer.json");
        $packages = [];

        $root->setRequires(Argument::type('array'))
            ->will(function ($args) use (&$packages) {
                $packages = array_merge($packages, $args[0]);
            });

        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertArrayHasKey('foo', $packages);
        $this->assertArrayHasKey('monolog/monolog', $packages);
        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_recursive_includes_disabled()
    {
        $dir        = $this->fixtureDir('recursive-includes-disabled');
        $root       = $this->rootFromJson("{$dir}/composer.json");
        $packages   = [];

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use (&$packages) {
                $packages = array_merge($packages, $args[0]);
            }
        );
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertArrayHasKey('foo', $packages);
        $this->assertArrayNotHasKey('monolog/monolog', $packages);
        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_one_merge_with_conflicts()
    {
        $that   = $this;
        $dir    = $this->fixtureDir('one-merge-with-conflicts');
        $root   = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey(
                    'arcanedev/composer',
                    $requires
                );
                $that->assertArrayHasKey('monolog/monolog', $requires);
            }
        );
        $root->getDevRequires()->shouldBeCalled();
        $root->setDevRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('foo', $requires);
                $that->assertArrayHasKey('xyzzy', $requires);
            }
        );
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(2, count($extraInstalls));
        $this->assertEquals('monolog/monolog', $extraInstalls[0][0]);
        $this->assertEquals('foo', $extraInstalls[1][0]);
    }

    /** @test */
    public function it_can_merged_repositories()
    {
        $that        = $this;
        $io          = $this->io;
        $dir         = $this->fixtureDir('merged-repositories');
        $repoManager = $this->prophesize('Composer\Repository\RepositoryManager');

        $repoManager
            ->createRepository(Argument::type('string'), Argument::type('array'))
            ->will(function ($args) use ($that, $io) {
                $that->assertEquals('vcs', $args[0]);
                $that->assertEquals(
                    'https://github.com/bd808/composer-merge-plugin.git',
                    $args[1]['url']
                );
                return new \Composer\Repository\VcsRepository(
                    $args[1],
                    $io->reveal(),
                    new \Composer\Config()
                );
            });

        $repoManager->addRepository(Argument::any())
            ->will(function ($args) use ($that) {
                $that->assertInstanceOf(
                    'Composer\Repository\VcsRepository',
                    $args[0]
                );
            });

        $this->composer->getRepositoryManager()
            ->will(function () use ($repoManager) {
                return $repoManager->reveal();
            });

        $root = $this->rootFromJson("{$dir}/composer.json");
        $root->setRequires(Argument::type('array'))
            ->will(function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(1, count($requires));
                $that->assertArrayHasKey(
                    'arcanedev/composer',
                    $requires
                );
            });

        $root->getDevRequires()->shouldNotBeCalled();
        $root->setDevRequires()->shouldNotBeCalled();
        $root->setRepositories(Argument::type('array'))->will(
            function ($args) use ($that) {
                $repos = $args[0];
                $that->assertEquals(1, count($repos));
            }
        );
        $root->getSuggests()->shouldNotBeCalled();
        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_update_stability_flags()
    {
        $that = $this;
        $dir = $this->fixtureDir('update-stability-flags');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that) {
                $requires = $args[0];
                $that->assertEquals(4, count($requires));
                $that->assertArrayHasKey('test/foo', $requires);
                $that->assertArrayHasKey('test/bar', $requires);
                $that->assertArrayHasKey('test/baz', $requires);
                $that->assertArrayHasKey('test/xyzzy', $requires);
            }
        );
        $root->getDevRequires()->shouldNotBeCalled();
        $root->setDevRequires(Argument::any())->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->setRepositories(Argument::any())->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();
        $root->setSuggests(Argument::any())->shouldNotBeCalled();
        {}
        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_merged_autoload()
    {
        $that   = $this;
        $dir    = $this->fixtureDir('merged-autoload');
        $root   = $this->rootFromJson("{$dir}/composer.json");

        $root->getAutoload()->shouldBeCalled();
        $root->getDevAutoload()->shouldBeCalled();
        $root->getRequires()->shouldNotBeCalled();
        $root->setAutoload(Argument::type('array'))->will(
            function ($args) use ($that) {
                $that->assertEquals(
                    [
                        'psr-4'     => [
                            'Arcanedev\\'           => 'src/',
                            'Arcanedev\\Package\\'  => [
                                'modules/Package/package/',
                                'modules/Package/libs/'
                            ],
                            'Arcanedev\\Module\\'   => 'modules/Package/module/'
                        ],
                        'psr-0'     => [
                            'UniqueGlobalClass' => 'modules/Package/',
                            '' => 'modules/Package/fallback/'
                        ],
                        'files'     => [
                            'modules/Package/helpers.php'
                        ],
                        'classmap'  => [
                            'modules/Package/init.php',
                            'modules/Package/includes/'
                        ],
                    ],
                    $args[0]
                );
            }
        );
        $root->setDevAutoload(Argument::type('array'))->will(
            function ($args) use ($that) {
                $that->assertEquals(
                    [
                        'psr-4'     => [
                            'Arcanedev\\Module\\Tests\\'    => 'modules/Package/module/tests/',
                        ],
                    ],
                    $args[0]
                );
            }
        );

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with no conflicting keys
     * When the plugin is run
     * Then the root package extra section should be extended with content from the local config.
     */
    public function it_can_merge_extra()
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-extra');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertEquals(2, count($extra['merge-plugin']));
                $that->assertArrayHasKey('foo', $extra);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with a conflicting key
     * When the plugin is run
     * Then the version in the root package should win.
     */
    public function it_can_merge_extra_with_conflict()
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-extra-with-conflict');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertArrayHasKey('foo', $extra);
                $that->assertEquals('bar', $extra['foo']);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and replace mode is active
     *   and a composer.local.json with an extra section with a conflicting key
     * When the plugin is run
     * Then the version in the composer.local.json package should win.
     */
    public function it_can_merge_extra_conflict_replace()
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-extra-conflict-replace');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(
            function ($args) use ($that) {
                $extra = $args[0];
                $that->assertEquals(2, count($extra));
                $that->assertArrayHasKey('merge-plugin', $extra);
                $that->assertArrayHasKey('foo', $extra);
                $that->assertEquals('baz', $extra['foo']);
            }
        )->shouldBeCalled();

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     * @dataProvider provideOnPostPackageInstall
     *
     * @param string $package  Package installed
     * @param bool   $first    Expected isFirstInstall() value
     * @param bool   $locked   Expected wasLocked() value
     */
    public function it_can_merge_on_post_package_install($package, $first, $locked)
    {
        $operation = new InstallOperation(
            new Package($package, '1.2.3.4', '1.2.3')
        );
        $event = $this->prophesize('Composer\\Installer\\PackageEvent');
        $event->getOperation()
            ->willReturn($operation)
            ->shouldBeCalled();

        if ($first) {
            $locker = $this->prophesize('Composer\\Package\\Locker');
            $locker->isLocked()
                ->willReturn($locked)
                ->shouldBeCalled();

            $this->composer->getLocker()
                ->willReturn($locker->reveal())
                ->shouldBeCalled();

            $event->getComposer()
                ->willReturn($this->composer->reveal())
                ->shouldBeCalled();
        }

        $this->plugin->onPostPackageInstall($event->reveal());

        $this->assertEquals($first,  $this->plugin->isFirstInstall());
        $this->assertEquals($locked, $this->plugin->wasLocked());
    }

    /**
     * @test
     *
     * Given a root package with a branch alias
     * When the plugin is run
     * Then the root package will be unwrapped from the alias.
     */
    public function test_has_branch_alias()
    {
        $that = $this;
        $dir  = $this->fixtureDir('has-branch-alias');

        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))
             ->will(function ($args) use ($that) {
                 $requires = $args[0];
                 $that->assertEquals(2, count($requires));
                 $that->assertArrayHasKey('arcanedev/composer', $requires);
                 $that->assertArrayHasKey('php', $requires);
             });

        $root = $root->reveal();

        $that->assertEquals([
            "arcanedev/composer" => "dev-master"
        ], $root->getRequires());

        $that->assertEquals([
            "merge-plugin" => [
                "include"   => [
                    "composer.local.json"
                ]
            ],
            "branch-alias" => [
                "dev-master" => "5.0.x-dev"
            ]
        ], $root->getExtra());

        $alias = $this->prophesize('Composer\Package\RootAliasPackage');
        $alias->getAliasOf()->willReturn($root)->shouldBeCalled();

        $this->triggerPlugin($alias->reveal(), $dir);

        $getRootPackage = new ReflectionMethod(
            get_class($this->plugin),
            'getRootPackage'
        );
        $getRootPackage->setAccessible(true);

        $this->assertEquals($root, $getRootPackage->invoke($this->plugin));
    }

    /**
     * @test
     *
     * Given a root package with requires
     *   and a b.json with requires
     *   and an a.json with requires
     *   and a glob of json files with requires
     * When the plugin is run
     * Then the root package should inherit the requires
     *   in the correct order based on inclusion order
     *   for individual files and alpha-numeric sorting
     *   for files included via a glob.
     */
    public function it_can_correct_merge_order_of_specified_files_and_glob_files()
    {
        $that = $this;
        $dir  = $this->fixtureDir('correct-merge-order-of-specified-files-and-glob-files');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $expects = [
            'merge-plugin/b.json',
            'merge-plugin/a.json',
            'merge-plugin/glob/a-glob2.json',
            'merge-plugin/glob/b-glob1.json'
        ];

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that, &$expects) {
                $expectedSource = array_shift($expects);
                $that->assertEquals(
                    $expectedSource,
                    $args[0]['arcanedev/workbench']->getSource()
                );
            }
        );

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Trigger the composer plugin
     *
     * @param  RootPackage $package
     * @param  string      $directory Working directory for composer run
     * @param  bool        $devMode
     *
     * @return array
     */
    protected function triggerPlugin($package, $directory, $devMode = true)
    {
        chdir($directory);
        $this->composer->getPackage()->willReturn($package);

        $this->plugin->onInstallUpdateOrDump(new Event(
            ScriptEvents::PRE_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            $devMode,
            [],
            []
        ));

        $requestInstalls = [];

        $request = $this->prophesize('Composer\\DependencyResolver\\Request');
        $request->install(Argument::any(), Argument::any())
            ->will(function ($args) use (&$requestInstalls) {
                $requestInstalls[] = $args;
            });

        $this->plugin->onDependencySolve(new InstallerEvent(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            $this->composer->reveal(),
            $this->io->reveal(),
            $devMode,
            $this->prophesize('Composer\\DependencyResolver\\PolicyInterface')->reveal(),
            $this->prophesize('Composer\\DependencyResolver\\Pool')->reveal(),
            $this->prophesize('Composer\\Repository\\CompositeRepository')->reveal(),
            $request->reveal(),
            []
        ));

        $this->plugin->onInstallUpdateOrDump(new Event(
            ScriptEvents::PRE_AUTOLOAD_DUMP,
            $this->composer->reveal(),
            $this->io->reveal(),
            $devMode,
            [],
            ['optimize' => true]
        ));

        $this->plugin->onPostInstallOrUpdate(new Event(
            ScriptEvents::POST_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            $devMode,
            [],
            []
        ));

        return $requestInstalls;
    }

    /**
     * Provide data onPostPackageInstall
     *
     * @return array
     */
    public function provideOnPostPackageInstall()
    {
        return [
            [ComposerPlugin::PACKAGE_NAME, true, true],
            [ComposerPlugin::PACKAGE_NAME, true, false],
            ['foo/bar', false, false],
        ];
    }
}
