<?php namespace Arcanedev\Composer\Tests;

use Arcanedev\Composer\ComposerPlugin;
use Composer\Composer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Prophecy\Argument;

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

        $this->composer = $this->prophesize('Composer\Composer');
        $this->io       = $this->prophesize('Composer\IO\IOInterface');
        $this->plugin   = new ComposerPlugin;

        $this->plugin->activate(
            $this->composer->reveal(),
            $this->io->reveal()
        );
    }

    public function tearDown()
    {
        parent::tearDown();
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
        ];

        $this->assertEquals(4, count($subscriptions));


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

    /** @ test */
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
                    'wikimedia/composer-merge-plugin',
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
        $this->assertEquals(1, count($extraInstalls));
        $this->assertEquals('monolog/monolog', $extraInstalls[0][0]);
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
                    'wikimedia/composer-merge-plugin',
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
        $root->getRequires()->shouldNotBeCalled();
        $root->setAutoload(Argument::type('array'))
            ->will(function ($args) use ($that) {
                $that->assertEquals(
                    [
                        'psr-4'     => [
                            'Arcanedev\\'           => 'arcanedev/',
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

            });

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Trigger the composer plugin
     *
     * @param  RootPackage $package
     * @param  string      $directory  Working directory for composer run
     *
     * @return array
     */
    protected function triggerPlugin($package, $directory)
    {
        chdir($directory);
        $this->composer->getPackage()->willReturn($package);
        $event = new Event(
            ScriptEvents::PRE_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            [],
            []
        );
        $this->plugin->onInstallOrUpdateOrDump($event);
        $requestInstalls = [];

        $request = $this->prophesize('Composer\\DependencyResolver\\Request');
        $request->install(Argument::any(), Argument::any())
            ->will(function ($args) use (&$requestInstalls) {
                $requestInstalls[] = $args;
            });

        $event = new InstallerEvent(
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            $this->composer->reveal(),
            $this->io->reveal(),
            true, //dev mode
            $this->prophesize('Composer\DependencyResolver\PolicyInterface')->reveal(),
            $this->prophesize('Composer\DependencyResolver\Pool')->reveal(),
            $this->prophesize('Composer\Repository\CompositeRepository')->reveal(),
            $request->reveal(),
            []
        );

        $this->plugin->onDependencySolve($event);

        return $requestInstalls;
    }
}
