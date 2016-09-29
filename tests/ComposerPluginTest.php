<?php namespace Arcanedev\Composer\Tests;

use Arcanedev\Composer\ComposerPlugin;
use Arcanedev\Composer\Entities\PluginState;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvents;
use Composer\Package\BasePackage;
use Composer\Package\Package;
use Composer\Package\RootPackage;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Prophecy\Argument;
use ReflectionProperty;

/**
 * Class     ComposerPluginTest
 *
 * @package  Arcanedev\Composer\Tests
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class ComposerPluginTest extends TestCase
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /** @var \Composer\Composer */
    protected $composer;

    /** @var \Composer\IO\IOInterface */
    protected $io;

    /** @var \Arcanedev\Composer\ComposerPlugin */
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
        unset($this->plugin);

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
            ComposerPlugin::COMPAT_PLUGINEVENTS_INIT,
            ScriptEvents::PRE_INSTALL_CMD,
            ScriptEvents::PRE_UPDATE_CMD,
            ScriptEvents::PRE_AUTOLOAD_DUMP,
            InstallerEvents::PRE_DEPENDENCIES_SOLVING,
            PackageEvents::POST_PACKAGE_INSTALL,
            ScriptEvents::POST_INSTALL_CMD,
            ScriptEvents::POST_UPDATE_CMD,
        ];

        $this->assertCount(count($events), $subscriptions);


        foreach ($events as $event) {
            $this->assertArrayHasKey($event, $subscriptions);
        }
    }

    /**
     * @test
     *
     * @expectedException         \Arcanedev\Composer\Exceptions\MissingFileException
     * @expectedExceptionMessage  merge-plugin: No files matched required 'glob/*.json'
     */
    public function it_must_throw_missing_file_exception_on_require()
    {
        $dir  = $this->fixtureDir('missing-file-exception-on-require');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->getRequires()->shouldNotBeCalled();
        $root->getDevRequires()->shouldNotBeCalled();
        $root->setReferences(Argument::type('array'))->shouldNotBeCalled();
        $this->triggerPlugin($root->reveal(), $dir);
    }

    /** @test */
    public function it_can_require()
    {
        $that = $this;
        $dir  = $this->fixtureDir('require');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertEquals(1, count($requires));
            $that->assertArrayHasKey('monolog/monolog', $requires);
        });

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /** @test */
    public function it_can_one_merge_no_conflicts()
    {
        $that = $this;
        $dir  = $this->fixtureDir('one-merge-no-conflicts');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertEquals(1, count($requires));
            $that->assertArrayHasKey('monolog/monolog', $requires);
        });

        $root->setConflicts(Argument::type('array'))->will(function ($args) use ($that) {
            $suggest = $args[0];

            $that->assertEquals(1, count($suggest));
            $that->assertArrayHasKey('conflict/conflict', $suggest);
        });

        $root->setReplaces(Argument::type('array'))->will(function ($args) use ($that) {
            $suggest = $args[0];

            $that->assertEquals(1, count($suggest));
            $that->assertArrayHasKey('replace/replace', $suggest);
        });

        $root->setProvides(Argument::type('array'))->will(function ($args) use ($that) {
            $suggest = $args[0];

            $that->assertEquals(1, count($suggest));
            $that->assertArrayHasKey('provide/provide', $suggest);
        });

        $root->setSuggests(Argument::type('array'))->will(function ($args) use ($that) {
            $suggest = $args[0];

            $that->assertEquals(1, count($suggest));
            $that->assertArrayHasKey('ext-apc', $suggest);
        });

        $root->getRepositories()->shouldNotBeCalled();
        $root->getProvides()->shouldBeCalled();
        $root->getReplaces()->shouldBeCalled();
        $root->getConflicts()->shouldBeCalled();
        $root->getSuggests()->shouldBeCalled();

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

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];
            $that->assertEquals(2, count($requires));
            $that->assertArrayHasKey('monolog/monolog', $requires);
            $that->assertEquals('1.10.0', $requires['monolog/monolog']->getPrettyConstraint());
        });

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
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

        $root->setRequires(Argument::type('array'))->will(function ($args) use (&$packages) {
            $packages = array_merge($packages, $args[0]);
        });

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
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

        $root->setRequires(Argument::type('array'))->will(function ($args) use (&$packages) {
            $packages = array_merge($packages, $args[0]);
        });

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertArrayHasKey('foo', $packages);
        $this->assertArrayNotHasKey('monolog/monolog', $packages);
        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_can_one_merge_with_conflicts($fireInit)
    {
        $that   = $this;
        $dir    = $this->fixtureDir('one-merge-with-conflicts');
        $root   = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertEquals(2, count($requires));
            $that->assertArrayHasKey('arcanedev/composer', $requires);
            $that->assertArrayHasKey('monolog/monolog',    $requires);
        });

        $root->setDevRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];
            $that->assertEquals(2, count($requires));
            $that->assertArrayHasKey('foo', $requires);
            $that->assertArrayHasKey('xyzzy', $requires);
        })->shouldBeCalled();

        $root->getDevRequires()->shouldBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir, $fireInit);

        $this->assertCount(2,                  $extraInstalls);
        $this->assertEquals('monolog/monolog', $extraInstalls[0][0]);
        $this->assertEquals('foo',             $extraInstalls[1][0]);
    }

    /** @test */
    public function it_can_merged_repositories()
    {
        $that        = $this;
        $io          = $this->io;
        $dir         = $this->fixtureDir('merged-repositories');
        $repoManager = $this->prophesize('Composer\\Repository\\RepositoryManager');

        $repoManager
            ->createRepository(Argument::type('string'), Argument::type('array'))
            ->will(function ($args) use ($that, $io) {
                $that->assertEquals('vcs', $args[0]);
                $that->assertEquals(
                    'https://github.com/arcanedev-maroc/Composer.git',
                    $args[1]['url']
                );

                return new \Composer\Repository\VcsRepository(
                    $args[1],
                    $io->reveal(),
                    new \Composer\Config()
                );
            });

        $repoManager->prependRepository(Argument::any())->will(function ($args) use ($that) {
            $that->assertInstanceOf('Composer\Repository\VcsRepository', $args[0]);
        });

        $this->composer->getRepositoryManager()->will(function () use ($repoManager) {
            return $repoManager->reveal();
        });

        $root = $this->rootFromJson("{$dir}/composer.json");
        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertEquals(1, count($requires));
            $that->assertArrayHasKey('arcanedev/composer', $requires);
        });

        $root->setRepositories(Argument::type('array'))->will(function ($args) use ($that) {
            $repos = $args[0];

            $that->assertEquals(1, count($repos));
        });

        $root->setDevRequires()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_can_update_stability_flags($fireInit)
    {
        $that = $this;
        $dir  = $this->fixtureDir('update-stability-flags');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertEquals(7, count($requires));
            $that->assertArrayHasKey('test/foo', $requires);
            $that->assertArrayHasKey('test/bar', $requires);
            $that->assertArrayHasKey('test/baz', $requires);
            $that->assertArrayHasKey('test/xyzzy', $requires);
            $that->assertArrayHasKey('test/plugh', $requires);
            $that->assertArrayHasKey('test/plover', $requires);
            $that->assertArrayHasKey('test/bedquilt', $requires);
        });

        $root->setStabilityFlags(Argument::type('array'))->will(function ($args) use ($that, &$expects) {
            $expected = [
                'test/foo'   => BasePackage::STABILITY_DEV,
                'test/bar'   => BasePackage::STABILITY_BETA,
                'test/baz'   => BasePackage::STABILITY_ALPHA,
                'test/xyzzy' => BasePackage::STABILITY_RC,
                'test/plugh' => BasePackage::STABILITY_STABLE,
            ];

            $that->assertEquals($expected, $args[0]);
        });

        $root->setDevRequires(Argument::any())->shouldNotBeCalled();
        $root->getRepositories()->shouldNotBeCalled();
        $root->setRepositories(Argument::any())->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();
        $root->setSuggests(Argument::any())->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir, $fireInit);

        $this->assertCount(0, $extraInstalls);
    }

    /**
     * @test
     *
     * Given a root package with minimum-stability=beta
     *   and a required stable package
     *   and an include with a stability=dev require for the same package
     *   and a stability=stable require for another package
     * When the plugin is run
     * Then the first package should require stability=dev
     *   amd the second package should not specify a minimum stability.
     */
    public function it_can_merge_stability_flags_respects_minimum_stability()
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-stability-flags-respects-minimum-stability');
        $root = $this->rootFromJson("{$dir}/composer.json");

        // The root package declares a stable package
        $root->getStabilityFlags()->willReturn([
            'arcanedev/composer' => BasePackage::STABILITY_STABLE,
        ])->shouldBeCalled();

        $root->setRequires(Argument::type('array'))->will(function ($args) use ($that) {
            $requires = $args[0];

            $that->assertCount(2, $requires);
            $that->assertArrayHasKey('arcanedev/composer', $requires);
            $that->assertArrayHasKey('arcanedev/arcanesoft', $requires);
        });

        $root->setStabilityFlags(Argument::type('array'))->will(function ($args) use ($that, &$expects) {
            $expected = [
                'arcanedev/composer' => BasePackage::STABILITY_DEV,
            ];

            $that->assertEquals($expected, $args[0]);
        });

        $this->triggerPlugin($root->reveal(), $dir);
    }

    /** @test */
    public function it_can_merged_autoload()
    {
        $that   = $this;
        $dir    = $this->fixtureDir('merged-autoload');
        $root   = $this->rootFromJson("{$dir}/composer.json");

        $autoload = [];

        $root->getAutoload()->shouldBeCalled();
        $root->getDevAutoload()->shouldBeCalled();

        $root->setAutoload(Argument::type('array'))->will(function ($args, $root) use (&$autoload) {
            // Can't easily assert directly since there will be multiple
            // calls to this setter to create our final expected state
            $autoload = $args[0];

            // Return the new data for the next call to getAutoLoad()
            $root->getAutoload()->willReturn($args[0]);
        })->shouldBeCalledTimes(2);

        $root->setDevAutoload(Argument::type('array'))->will(function ($args) use ($that) {
            $that->assertEquals(
                [
                    'psr-4'     => [
                        'Arcanedev\\Tests\\'         => 'tests/',
                        'Arcanedev\\Module\\Tests\\' => 'modules/Package/module/tests/',
                    ],
                ],
                $args[0]
            );
        });

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
        $this->assertEquals([
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
                'modules/Package/helpers.php',
                'private/bootstrap.php'
            ],
            'classmap'  => [
                'modules/Package/init.php',
                'modules/Package/includes/'
            ],
        ], $autoload);
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with no conflicting keys
     * When the plugin is run
     * Then the root package extra section should be extended with content from the local config.
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_can_merge_extra($fireInit)
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-extra');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(function ($args) use ($that) {
            $extra = $args[0];
            $that->assertEquals(2, count($extra));
            $that->assertArrayHasKey('merge-plugin', $extra);
            $that->assertEquals(2, count($extra['merge-plugin']));
            $that->assertArrayHasKey('foo', $extra);
        })->shouldBeCalled();

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir, $fireInit);

        $this->assertCount(0, $extraInstalls);
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

        $root->setExtra(Argument::type('array'))->will(function ($args) use ($that) {
            $extra = $args[0];
            $that->assertEquals(2, count($extra));
            $that->assertArrayHasKey('merge-plugin', $extra);
            $that->assertArrayHasKey('foo', $extra);
            $that->assertEquals('bar', $extra['foo']);
        })->shouldBeCalled();

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
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

        $root->setExtra(Argument::type('array'))->will(function ($args) use ($that) {
            $extra = $args[0];

            $that->assertEquals(2, count($extra));
            $that->assertArrayHasKey('merge-plugin', $extra);
            $that->assertArrayHasKey('foo', $extra);
            $that->assertEquals('baz', $extra['foo']);
        })->shouldBeCalled();

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and a composer.local.json with an extra section with conflicting keys that are arrays
     *   and the 'merge-extra-deep' option being activated
     * When the plugin is run
     * Then the root package extra section should be extended with content from the base config
     *   and deep keys should be merged together, but root config wins on key conflicts.
     *
     * @dataProvider provideDeepMerge
     *
     * @param  string  $suffix
     * @param  bool    $replace
     */
    public function it_can_merge_extra_deep($suffix, $replace)
    {
        $that = $this;
        $dir  = $this->fixtureDir('merge-extra-deep' . $suffix);
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setExtra(Argument::type('array'))->will(function ($args) use ($that, $replace) {
            $extra = $args[0];

            $that->assertCount(3, $extra);
            $that->assertArrayHasKey('merge-plugin', $extra);
            $that->assertCount(4, $extra['merge-plugin']);
            $that->assertArrayHasKey('patches', $extra);
            $that->assertArrayHasKey('arcanedev/composer', $extra['patches']);

            $patches = $extra['patches']['arcanedev/composer'];
            $key = 'Allow merging of sections in a deep way';
            $that->assertEquals('patches/add-merge-extra-deep-option.diff', $patches[$key]);

            $key = 'Add tests for merge-extra-deep option';
            $that->assertEquals('patches/add-tests-for-merge-extra-deep-option.diff', $patches[$key]);
            $that->assertArrayHasKey('somevendor/some-project', $extra['patches']);
            $that->assertArrayHasKey('some-patch', $extra['patches']['somevendor/some-project']);

            $value = $extra['patches']['somevendor/some-project']['some-patch'];
            $that->assertEquals('patches/overridden-patch.diff', $value);

            if ( ! $replace) {
                $that->assertArrayHasKey('base-patch', $extra['patches']['somevendor/some-project']);
                $value = $extra['patches']['somevendor/some-project']['base-patch'];
                $that->assertEquals('patches/always-patch.diff', $value);
            }

            $that->assertArrayHasKey('anothervendor/some-project', $extra['patches']);
            $that->assertArrayHasKey('another-patch', $extra['patches']['anothervendor/some-project']);
            $that->assertEquals(['first', 'second', 'third', 'fourth'], $extra['list']);
        })->shouldBeCalled();

        $root->getRepositories()->shouldNotBeCalled();
        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertCount(0, $extraInstalls);
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
        $event->getOperation()->willReturn($operation)->shouldBeCalled();

        if ($first) {
            $locker = $this->prophesize('Composer\\Package\\Locker');

            $locker->isLocked()->willReturn($locked)->shouldBeCalled();
            $this->composer->getLocker()->willReturn($locker->reveal())->shouldBeCalled();
            $event->getComposer()->willReturn($this->composer->reveal())->shouldBeCalled();
        }

        $this->plugin->onPostPackageInstall($event->reveal());

        $this->assertEquals($first,  $this->getState()->isFirstInstall());
        $this->assertEquals($locked, $this->getState()->isLocked());
    }

    /**
     * Given a root package with merge-dev=false
     *   and an include with require-dev and autoload-dev sections
     * When the plugin is run
     * Then the -dev sections are not merged
     */
    public function it_can_skip_merge_dev_if_false()
    {
        $that = $this;
        $dir  = $this->fixtureDir('skip-merge-dev-if-false');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))
            ->will(function ($args) use ($that) {
                $requires = $args[0];

                $that->assertEquals(2, count($requires));
                $that->assertArrayHasKey('arcanedev/composer', $requires);
                $that->assertArrayHasKey('arcanedev/foo', $requires);
            })->shouldBeCalled();

        $root->setDevRequires(Argument::type('array'))->shouldNotBeCalled();
        $root->setRepositories(Argument::type('array'))->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertCount(0, $extraInstalls);
    }

    /**
     * @test
     *
     * Given a root package with a branch alias
     * When the plugin is run
     * Then the root package will be unwrapped from the alias.
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_has_branch_alias($fireInit)
    {
        $that = $this;
        $io   = $this->io;
        $dir  = $this->fixtureDir('has-branch-alias');
        // RootAliasPackage was updated in 06c44ce to include more setters
        // that we take advantage of if available
        $withCompleteRootAlias = method_exists(
            'Composer\Package\RootPackageInterface',
            'setRepositories'
        );

        /** @var mixed $repoManager */
        $repoManager = $this->prophesize('Composer\Repository\RepositoryManager');

        $repoManager
            ->createRepository(Argument::type('string'), Argument::type('array'))
            ->will(function ($args) use ($that, $io) {
                return new \Composer\Repository\VcsRepository(
                    $args[1], $io->reveal(), new \Composer\Config
                );
            });

        $repoManager->prependRepository(Argument::any())->shouldBeCalled();

        $this->composer->getRepositoryManager()->will(function () use ($repoManager) {
            return $repoManager->reveal();
        });

        $root = $this->rootFromJson("{$dir}/composer.json");

        // Always handled by alias
        $root->setDevRequires(Argument::type('array'))->shouldNotBeCalled();
        $root->setRequires(Argument::type('array'))->shouldNotBeCalled();

        /** @var mixed $alias */
        $alias = $this->makeAliasFor($root->reveal());

        $alias->getExtra()->shouldBeCalled();
        $alias->setDevRequires(Argument::type('array'))->shouldBeCalled();
        $alias->setRequires(Argument::type('array'))->shouldBeCalled();

        if ($withCompleteRootAlias) {
            // When Composer supports it we will apply our changes directly to
            // the RootAliasPackage
            $alias->setAutoload(Argument::type('array'))->shouldBeCalled();
            $alias->setConflicts(Argument::type('array'))->shouldBeCalled();
            $alias->setDevAutoload(Argument::type('array'))->shouldBeCalled();
            $alias->setProvides(Argument::type('array'))->shouldBeCalled();
            $alias->setReplaces(Argument::type('array'))->shouldBeCalled();
            $alias->setRepositories(Argument::type('array'))->shouldBeCalled();
            $alias->setStabilityFlags(Argument::type('array'))->shouldBeCalled();
            $alias->setSuggests(Argument::type('array'))->shouldBeCalled();
        }
        else {
            // With older versions of Composer we will fall back to unwrapping
            // the aliased RootPackage and make calls to it
            $root->setAutoload(Argument::type('array'))->shouldBeCalled();
            $root->setConflicts(Argument::type('array'))->shouldBeCalled();
            $root->setDevAutoload(Argument::type('array'))->shouldBeCalled();
            $root->setProvides(Argument::type('array'))->shouldBeCalled();
            $root->setReplaces(Argument::type('array'))->shouldBeCalled();
            $root->setRepositories(Argument::type('array'))->shouldBeCalled();
            $root->setSuggests(Argument::type('array'))->shouldBeCalled();
            $alias->getAliasOf()->shouldBeCalled();
        }

        $this->triggerPlugin($alias->reveal(), $dir, $fireInit);
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
            'merge-plugin/glob-a-glob2.json',
            'merge-plugin/glob-b-glob1.json'
        ];

        $root->setRequires(Argument::type('array'))->will(
            function ($args) use ($that, &$expects) {
                $expectedSource = array_shift($expects);

                /** @var \Composer\Package\Link $link */
                $link         = $args[0]['arcanedev/workbench'];

                // @FIXME: This fix is for windows machines
                $actualSource = str_replace('/glob/', '/glob-', $link->getSource());

                $that->assertEquals($expectedSource, $actualSource);
            }
        );

        $this->triggerPlugin($root->reveal(), $dir);
    }

    /** @test */
    public function it_can_replace_link_with_self_version_as_constraint()
    {
        $that = $this;
        $dir  = $this->fixtureDir('replace-link-with-self-version-as-constraint');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setReplaces(Argument::type('array'))
             ->will(function ($args) use ($that) {
                 $replace = $args[0];

                 $that->assertEquals(3, count($replace));

                 $that->assertArrayHasKey('foo/bar', $replace);
                 $that->assertArrayHasKey('foo/baz', $replace);
                 $that->assertArrayHasKey('foo/xyzzy', $replace);

                 $that->assertInstanceOf('Composer\Package\Link', $replace['foo/bar']);
                 $that->assertInstanceOf('Composer\Package\Link', $replace['foo/baz']);
                 $that->assertInstanceOf('Composer\Package\Link', $replace['foo/xyzzy']);

                 $that->assertEquals(
                     '1.2.3.4', $replace['foo/bar']->getPrettyConstraint()
                 );

                 $that->assertEquals(
                     '1.2.3.4', $replace['foo/baz']->getPrettyConstraint()
                 );

                 $that->assertEquals(
                     '~1.0', $replace['foo/xyzzy']->getPrettyConstraint()
                 );
             });

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);
        $this->assertCount(0, $extraInstalls);
    }

    /**
     * @test
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_can_resolve_self_version_constraint_through_packages_from_the_root_package($fireInit)
    {
        $that = $this;
        $dir  = $this->fixtureDir('resolve-self-version-constraint-through-packages-from-the-root-package');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setReplaces(Argument::type('array'))->will(function ($args) use ($that) {
             $replace = $args[0];
             $that->assertEquals(3, count($replace));

             $that->assertArrayHasKey('foo/bar',   $replace);
             $that->assertArrayHasKey('foo/baz',   $replace);
             $that->assertArrayHasKey('foo/xyzzy', $replace);

             $that->assertInstanceOf('Composer\Package\Link', $replace['foo/bar']);
             $that->assertInstanceOf('Composer\Package\Link', $replace['foo/baz']);
             $that->assertInstanceOf('Composer\Package\Link', $replace['foo/xyzzy']);

             $that->assertEquals('~8.0', $replace['foo/bar']->getPrettyConstraint());
             $that->assertEquals('~8.0', $replace['foo/baz']->getPrettyConstraint());
             $that->assertEquals('~1.0', $replace['foo/xyzzy']->getPrettyConstraint());
        });

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir, $fireInit);
        $this->assertCount(0, $extraInstalls);
    }

    /**
     * @test
     *
     * @dataProvider provideFireInit
     *
     * @param  bool  $fireInit
     */
    public function it_can_resolve_version_constraint_with_revision($fireInit)
    {
        $that = $this;
        $dir  = $this->fixtureDir('resolve-version-constraint-with-revision');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires(Argument::type('array'))->will(function ($args, $root) {
            $root->getRequires()->willReturn($args[0]);
        })->shouldBeCalled();

        $root->setDevRequires(Argument::type('array'))->will(function ($args, $root) {
            $root->getDevRequires()->willReturn($args[0]);
        })->shouldBeCalled();

        $checkRefs = $checkRefsWithDev = function ($args) use ($that) {
            $references = $args[0];
            $that->assertEquals(3, count($references));

            $that->assertArrayHasKey('foo/bar', $references);
            $that->assertArrayHasKey('monolog/monolog', $references);
            $that->assertArrayHasKey('foo/baz', $references);

            $that->assertSame($references['foo/bar'], '1234567');
            $that->assertSame($references['monolog/monolog'], 'cb641a8');
            $that->assertSame($references['foo/baz'], 'abc1234');
        };

        if ($fireInit) {
            $checkRefs = function ($args) use ($that, $root, $checkRefsWithDev) {
                $references = $args[0];
                $that->assertEquals(2, count($references));

                $that->assertArrayHasKey('foo/bar', $references);
                $that->assertArrayHasKey('monolog/monolog', $references);

                $that->assertSame($references['foo/bar'], '1234567');
                $that->assertSame($references['monolog/monolog'], 'cb641a8');

                // onInit does parse without require-dev, so this is called a
                // second time when onInstallUpdateOrDump() fires with the dev
                // section parsed as well.
                $root->setReferences(Argument::type('array'))->will($checkRefsWithDev);
            };
        }

        $root->setReferences(Argument::type('array'))->will($checkRefs);

        $this->triggerPlugin($root->reveal(), $dir, $fireInit);
    }

    /**
     * @test
     *
     * Given a root package with an extra section
     *   and prepend-repositories mode is active
     *   and a composer.local.json with a repository
     * When the plugin is run
     * Then the repository from composer.local.json should be prepended to root package repository list
     */
    public function it_can_prepend_repositories()
    {
        $that        = $this;
        $io          = $this->io;
        $repoManager = $this->prophesize('Composer\Repository\RepositoryManager');

        $repoManager->createRepository(Argument::type('string'), Argument::type('array'))
            ->will(function ($args) use ($that, $io) {
                $that->assertEquals('vcs', $args[0]);
                $that->assertEquals('https://github.com/ARCANEDEV/Composer.git', $args[1]['url']);

                return new \Composer\Repository\VcsRepository($args[1], $io->reveal(), new \Composer\Config);
            });

        $repoManager->prependRepository(Argument::any())->will(function ($args) use ($that) {
            $that->assertInstanceOf('Composer\Repository\VcsRepository', $args[0]);
        });

        $this->composer->getRepositoryManager()->will(function () use ($repoManager) {
            return $repoManager->reveal();
        });

        $dir  = $this->fixtureDir('prepend-repositories');
        $root = $this->rootFromJson("{$dir}/composer.json");

        $root->setRequires()->shouldNotBeCalled();
        $root->setDevRequires()->shouldNotBeCalled();

        $root->setRepositories(Argument::type('array'))->will(function ($args) use ($that) {
            $repos = $args[0];
            $that->assertEquals(2, count($repos));
            $prependedRepo = $repos[0];
            $that->assertInstanceOf('Composer\Repository\VcsRepository', $prependedRepo);
            $that->assertAttributeEquals('https://github.com/ARCANEDEV/Composer.git', 'url', $prependedRepo);
        });

        $root->getConflicts()->shouldNotBeCalled();
        $root->getReplaces()->shouldNotBeCalled();
        $root->getProvides()->shouldNotBeCalled();
        $root->getSuggests()->shouldNotBeCalled();

        $extraInstalls = $this->triggerPlugin($root->reveal(), $dir);

        $this->assertEquals(0, count($extraInstalls));
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @return PluginState
     */
    protected function getState()
    {
        $state = new ReflectionProperty(get_class($this->plugin), 'state');
        $state->setAccessible(true);

        return $state->getValue($this->plugin);
    }

    /**
     * Trigger the composer plugin
     *
     * @param  RootPackage  $package
     * @param  string       $directory Working directory for composer run
     * @param  bool         $fireInit
     *
     * @return array
     */
    protected function triggerPlugin($package, $directory, $fireInit = false)
    {
        chdir($directory);
        $this->composer->getPackage()->willReturn($package);

        if ($fireInit) {
            $this->plugin->onInit(new \Composer\EventDispatcher\Event(
                ComposerPlugin::COMPAT_PLUGINEVENTS_INIT
            ));
        }

        $this->plugin->onInstallUpdateOrDump(new Event(
            ScriptEvents::PRE_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            true,
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
            true,
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
            true,
            [],
            ['optimize' => true]
        ));

        $this->plugin->onPostInstallOrUpdate(new Event(
            ScriptEvents::POST_INSTALL_CMD,
            $this->composer->reveal(),
            $this->io->reveal(),
            true,
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

    /**
     * @return array
     */
    public function provideDeepMerge()
    {
        return [
            ['-replace', true],
            ['', false],
        ];
    }

    /**
     * Generic provider for tests that should be tried with and without an INIT event.
     *
     * @return array
     */
    public function provideFireInit()
    {
        return [
            'with INIT event'    => [true],
            'without INIT event' => [false],
        ];
    }
}
