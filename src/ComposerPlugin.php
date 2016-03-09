<?php namespace Arcanedev\Composer;

use Arcanedev\Composer\Entities\Package;
use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Exceptions\MissingFileException;
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
use Composer\Package\RootPackageInterface;
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
    /** @var \Composer\Composer */
    protected $composer;

    /** @var \Arcanedev\Composer\Entities\PluginState */
    protected $state;

    /** @var \Arcanedev\Composer\Utilities\Logger */
    protected $logger;

    /**
     * Files that have already been processed
     *
     * @var array
     */
    protected $loadedFiles = [];

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Apply plugin modifications to composer
     *
     * @param  \Composer\Composer        $composer
     * @param  \Composer\IO\IOInterface  $io
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
     * @param  \Composer\Installer\InstallerEvent  $event
     */
    public function onDependencySolve(InstallerEvent $event)
    {
        $request = $event->getRequest();

        $this->installRequires(
            $request, $this->state->getDuplicateLinks('require')
        );

        if ($this->state->isDevMode()) {
            $this->installRequires(
                $request, $this->state->getDuplicateLinks('require-dev'), true
            );
        }
    }

    /**
     * Install requirements.
     *
     * @param  \Composer\DependencyResolver\Request  $request
     * @param  \Composer\Package\Link[]              $links
     * @param  bool                                  $dev
     */
    private function installRequires(Request $request, array $links, $dev = false)
    {
        foreach ($links as $link) {
            $this->logger->info($dev
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
     * @param  \Composer\Script\Event  $event
     */
    public function onInstallUpdateOrDump(Event $event)
    {
        $this->state->loadSettings();
        $this->state->setDevMode($event->isDevMode());
        $this->mergeFiles($this->state->getIncludes());
        $this->mergeFiles($this->state->getRequires(), true);

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
     * @param  array  $patterns  List of files/glob patterns
     * @param  bool   $required  Are the patterns required to match files?
     *
     * @throws \Arcanedev\Composer\Exceptions\MissingFileException
     */
    protected function mergeFiles(array $patterns, $required = false)
    {
        $root  = $this->composer->getPackage();
        $files = array_map(function ($files, $pattern) use ($required) {
            if ($required && ! $files) {
                throw new MissingFileException(
                    "merge-plugin: No files matched required '{$pattern}'"
                );
            }

            return $files;
        }, array_map('glob', $patterns), $patterns);

        foreach (array_reduce($files, 'array_merge', []) as $path) {
            $this->mergeFile($root, $path);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     * @param  string                                  $path
     */
    private function mergeFile(RootPackageInterface $root, $path)
    {
        if (isset($this->loadedFiles[$path])) {
            $this->logger->debug("Skipping duplicate <comment>$path</comment>");

            return;
        }

        $this->loadedFiles[$path] = true;
        $this->logger->info("Loading <comment>{$path}</comment>...");
        $package = new Package($path, $this->composer, $this->logger);
        $package->mergeInto($root, $this->state);

        if ($this->state->recurseIncludes()) {
            $this->mergeFiles($package->getIncludes());
            $this->mergeFiles($package->getRequires(), true);
        }
    }

    /**
     * Handle an event callback following installation of a new package by
     * checking to see if the package that was installed was our plugin.
     *
     * @param  \Composer\Installer\PackageEvent  $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $op = $event->getOperation();

        if ($op instanceof InstallOperation) {
            $package = $op->getPackage()->getName();

            if ($package === self::PACKAGE_NAME) {
                $this->logger->debug('Composer merge-plugin installed');
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
     * @param  \Composer\Script\Event  $event
     *
     * @codeCoverageIgnore
     */
    public function onPostInstallOrUpdate(Event $event)
    {
        if ($this->state->isFirstInstall()) {
            $this->state->setFirstInstall(false);
            $this->logger->info(
                '<comment>Running additional update to apply merge settings</comment>'
            );
            $this->runFirstInstall($event);
        }
    }

    /**
     * Run first install.
     *
     * @param  \Composer\Script\Event  $event
     *
     * @throws \Exception
     *
     * @codeCoverageIgnore
     */
    private function runFirstInstall(Event $event)
    {
        $installer    = Installer::create(
            $event->getIO(),
            // Create a new Composer instance to ensure full processing of the merged files.
            Factory::create($event->getIO(), null, false)
        );

        $installer->setPreferSource($this->isPreferredInstall('source'));
        $installer->setPreferDist($this->isPreferredInstall('dist'));
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

    /* ------------------------------------------------------------------------------------------------
     |  Check Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Check the preferred install (source or dist).
     *
     * @param  string  $preferred
     *
     * @return bool
     *
     * @codeCoverageIgnore
     */
    private function isPreferredInstall($preferred)
    {
        return $this->composer->getConfig()->get('preferred-install') === $preferred;
    }
}
