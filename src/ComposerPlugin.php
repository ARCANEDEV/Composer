<?php namespace Arcanedev\Composer;

use Arcanedev\Composer\Entities\Package;
use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Utilities\Logger;
use Composer\Composer;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Request;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\RootPackage;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

/**
 * Class     ComposerPlugin
 *
 * @package  Arcanedev\Composer
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class ComposerPlugin implements PluginInterface, EventSubscriberInterface
{
    /* ------------------------------------------------------------------------------------------------
     |  Constants
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Package name
     */
    const PACKAGE_NAME = 'arcanedev/composer';

    /**
     * Plugin key
     */
    const PLUGIN_KEY = 'merge-plugin';

    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var PluginState $state
     */
    protected $state;

    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * Files that have already been processed
     *
     * @var string[] $loadedFiles
     */
    protected $loadedFiles = [];

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Apply plugin modifications to composer
     *
     * @param  Composer     $composer
     * @param  IOInterface  $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->state    = new PluginState($this->composer);
        $this->logger   = new Logger('merge-plugin', $io);
}

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            InstallerEvents::PRE_DEPENDENCIES_SOLVING => 'onDependencySolve',
            ScriptEvents::PRE_INSTALL_CMD             => 'onInstallUpdateOrDump',
            ScriptEvents::PRE_UPDATE_CMD              => 'onInstallUpdateOrDump',
            ScriptEvents::PRE_AUTOLOAD_DUMP           => 'onInstallUpdateOrDump',
            PackageEvents::POST_PACKAGE_INSTALL       => 'onPostPackageInstall',
            ScriptEvents::POST_INSTALL_CMD            => 'onPostInstallOrUpdate',
            ScriptEvents::POST_UPDATE_CMD             => 'onPostInstallOrUpdate',
        ];
    }

    /**
     * Handle an event callback for pre-dependency solving phase of an install
     * or update by adding any duplicate package dependencies found during
     * initial merge processing to the request that will be processed by the
     * dependency solver.
     *
     * @param  InstallerEvent  $event
     */
    public function onDependencySolve(InstallerEvent $event)
    {
        $request = $event->getRequest();

        $this->installRequires(
            $request,
            $this->state->getDuplicateLinks('require')
        );

        if ($this->state->isDevMode()) {
            $this->installRequires(
                $request,
                $this->state->getDuplicateLinks('require-dev'),
                true
            );
        }
    }

    /**
     * Install requirements
     *
     * @param  Request  $request
     * @param  Link[]   $links
     * @param  bool     $dev
     */
    private function installRequires(Request $request, array $links, $dev = false)
    {
        foreach ($links as $link) {
            $this->logger->debug($dev
                ? "Adding dev dependency <comment>{$link}</comment>"
                : "Adding dependency <comment>{$link}</comment>"
            );
            $request->install($link->getTarget(), $link->getConstraint());
        }
    }

    /**
     * Handle an event callback for an install or update or dump-autoload command by checking
     * for "merge-patterns" in the "extra" data and merging package contents if found.
     *
     * @param  Event  $event
     */
    public function onInstallUpdateOrDump(Event $event)
    {
        $this->state->loadSettings();
        $this->state->setDevMode($event->isDevMode());
        $this->mergeIncludes($this->state->getIncludes());

        if ($event->getName() === ScriptEvents::PRE_AUTOLOAD_DUMP) {
            $this->state->setDumpAutoloader(true);
            $flags = $event->getFlags();

            if (isset($flags['optimize'])) {
                $this->state->setOptimizeAutoloader($flags['optimize']);
            }
        }
    }

    /**
     * Find configuration files matching the configured glob patterns and
     * merge their contents with the master package.
     *
     * @param  array  $includes  List of files/glob patterns
     */
    private function mergeIncludes(array $includes)
    {
        $root  = $this->state->getRootPackage();
        $paths = array_reduce(array_map('glob', $includes), 'array_merge', []);

        foreach ($paths as $path) {
            $this->mergeFile($root, $path);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param  RootPackage  $root
     * @param  string       $path
     */
    private function mergeFile(RootPackage $root, $path)
    {
        if (isset($this->loadedFiles[$path])) {
            $this->logger->debug("Skipping duplicate <comment>$path</comment>...");

            return;
        }

        $this->loadedFiles[$path] = true;
        $this->logger->debug("Loading <comment>{$path}</comment>...");
        $package = new Package($path, $this->composer, $this->logger);
        $package->mergeInto($root, $this->state);

        if ($this->state->recurseIncludes()) {
            $this->mergeIncludes($package->getIncludes());
        }
    }

    /**
     * Handle an event callback following installation of a new package by
     * checking to see if the package that was installed was our plugin.
     *
     * @param  PackageEvent  $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $op = $event->getOperation();

        if ($op instanceof InstallOperation) {
            $package = $op->getPackage()->getName();

            if ($package === self::PACKAGE_NAME) {
                $this->logger->debug('Arcanedev composer merge-plugin installed');
                $this->state->setFirstInstall(true);
                $this->state->setLocked(
                    $event->getComposer()->getLocker()->isLocked()
                );
            }
        }
    }

    /**
     * Handle an event callback following an install or update command. If our
     * plugin was installed during the run then trigger an update command to
     * process any merge-patterns in the current config.
     *
     * @param  Event  $event
     *
     * @codeCoverageIgnore
     */
    public function onPostInstallOrUpdate(Event $event)
    {
        if ($this->state->isFirstInstall()) {
            $this->state->setFirstInstall(false);
            $this->logger->debug(
                '<comment>Running additional update to apply merge settings</comment>'
            );
            $this->runFirstInstall($event);
        }
    }

    /**
     * Run first install
     *
     * @param  Event  $event
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    private function runFirstInstall(Event $event)
    {
        $config       = $this->composer->getConfig();
        $preferSource = $config->get('preferred-install') == 'source';
        $preferDist   = $config->get('preferred-install') == 'dist';
        $installer    = Installer::create(
            $event->getIO(),
            // Create a new Composer instance to ensure full processing of
            // the merged files.
            Factory::create($event->getIO(), null, false)
        );

        $installer->setPreferSource($preferSource);
        $installer->setPreferDist($preferDist);
        $installer->setDevMode($event->isDevMode());
        $installer->setDumpAutoloader($this->state->shouldDumpAutoloader());
        $installer->setOptimizeAutoloader($this->state->shouldOptimizeAutoloader());

        if ($this->state->forceUpdate()) {
            // Force update mode so that new packages are processed rather than just telling
            // the user that composer.json and composer.lock don't match.
            $installer->setUpdate(true);
        }

        $installer->run();
    }
}
