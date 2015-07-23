<?php namespace Arcanedev\Composer;

use Arcanedev\Composer\Helpers\Log;
use Composer\Composer;
use Arcanedev\Composer\Helpers\Config;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Factory;
use Composer\Installer;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\AliasPackage;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use UnexpectedValueException;

/**
 * Class ComposerPlugin
 * @package Arcanedev\Composer
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
     * @var ArrayLoader $loader
     */
    protected $loader;

    /**
     * @var array $duplicateLinks
     */
    protected $duplicateLinks;

    /**
     * Dev mode
     *
     * @var bool $devMode
     */
    protected $devMode = false;

    /**
     * Whether to recursively include dependencies
     *
     * @var bool $recurse
     */
    protected $recurse = true;

    /**
     * Files that have already been processed
     *
     * @var string[] $loadedFiles
     */
    protected $loadedFiles = [];

    /**
     * Is this the first time that our plugin has been installed?
     *
     * @var bool $pluginFirstInstall
     */
    protected $pluginFirstInstall = false;

    /**
     * Is the autoloader file supposed to be written out?
     *
     * @var bool $dumpAutoloader
     */
    protected $dumpAutoloader;

    /**
     * Is the autoloader file supposed to be optimized?
     *
     * @var bool $optimizeAutoloader
     */
    protected $optimizeAutoloader;

    /**
     * @var Log
     */
    private $log;

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get the root package
     *
     * @return RootPackage
     */
    protected function getRootPackage()
    {
        $root = $this->composer->getPackage();

        if ($root instanceof AliasPackage) {
            $root = $root->getAliasOf();
        }

        if ($root instanceof RootPackage) {
            return $root;
        }

        throw new UnexpectedValueException(
            'Expected instance of Composer\\Package\\RootPackage, got ' . get_class($root)
        );
    }

    /**
     * Is this the first time that the plugin has been installed?
     *
     * @return bool
     */
    public function isFirstInstall()
    {
        return $this->pluginFirstInstall;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Apply plugin modifications to composer
     *
     * @param Composer    $composer
     * @param IOInterface $io
     */
    public function activate(Composer $composer, IOInterface $io)
    {
        $this->composer           = $composer;
        $this->log                = new Log($io);
        $this->pluginFirstInstall = false;
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
     * @param InstallerEvent $event
     */
    public function onDependencySolve(InstallerEvent $event)
    {
        if (empty($this->duplicateLinks)) {
            // @codeCoverageIgnoreStart
            // We shouldn't really ever be able to get here as this event is triggered
            // inside Composer\Installer and should have been preceded by a pre-install
            // or pre-update event but better to have an unneeded check than to break
            // with some future change in the event system.
            return;
            // @codeCoverageIgnoreEnd
        }

        $request = $event->getRequest();

        /** @var \Composer\Package\Link $link */
        foreach ($this->duplicateLinks['require'] as $link) {
            $this->log->debug("Adding dependency <comment>{$link}</comment>");
            $request->install($link->getTarget(), $link->getConstraint());
        }

        if ($this->devMode) {
            foreach ($this->duplicateLinks['require-dev'] as $link) {
                $this->log->debug("Adding dev dependency <comment>{$link}</comment>");
                $request->install($link->getTarget(), $link->getConstraint());
            }
        }
    }

    /**
     * Handle an event callback for an install or update or dump-autoload command by checking
     * for "merge-patterns" in the "extra" data and merging package contents if found.
     *
     * @param Event $event
     */
    public function onInstallUpdateOrDump(Event $event)
    {
        $config = Config::read($this->getRootPackage(), self::PLUGIN_KEY);

        if (isset($config['recurse'])) {
            $this->recurse = (bool) $config['recurse'];
        }

        if ($config['include']) {
            $this->loader         = new ArrayLoader;
            $this->duplicateLinks = [
                'require'       => [],
                'require-dev'   => [],
            ];
            $this->devMode = $event->isDevMode();
            $this->mergePackages($config);
        }

        if ($event->getName() === ScriptEvents::PRE_AUTOLOAD_DUMP) {
            $this->dumpAutoloader = true;
            $flags = $event->getFlags();

            if (isset($flags['optimize'])) {
                $this->optimizeAutoloader = $flags['optimize'];
            }
        }
    }

    /**
     * Handle an event callback following installation of a new package by
     * checking to see if the package that was installed was our plugin.
     *
     * @param PackageEvent $event
     */
    public function onPostPackageInstall(PackageEvent $event)
    {
        $op = $event->getOperation();

        if ($op instanceof InstallOperation) {
            $package = $op->getPackage()->getName();

            if ($package === self::PACKAGE_NAME) {
                $this->log->debug(self::PLUGIN_KEY . ' installed');
                $this->pluginFirstInstall = true;
            }
         }
    }

    /**
     * Handle an event callback following an install or update command. If our
     * plugin was installed during the run then trigger an update command to
     * process any merge-patterns in the current config.
     *
     * @param Event $event
     */
    public function onPostInstallOrUpdate(Event $event)
    {
        if ( ! $this->isFirstInstall()) {
            return;
        }

        $this->pluginFirstInstall = false;
        $this->log->debug(
            '<comment>Running additional update to apply merge settings</comment>'
        );

        $config       = $this->composer->getConfig();
        $preferSource = $config->get('preferred-install') == 'source';
        $preferDist   = $config->get('preferred-install') == 'dist';

        $installer = Installer::create(
            $event->getIO(),
            // Create a new Composer instance to ensure full processing of the merged files.
            Factory::create($event->getIO(), null, false)
        );

        $installer->setPreferSource($preferSource);
        $installer->setPreferDist($preferDist);
        $installer->setDevMode($event->isDevMode());
        $installer->setDumpAutoloader($this->dumpAutoloader);
        $installer->setOptimizeAutoloader($this->optimizeAutoloader);

        // Force update mode so that new packages are processed rather than just telling the
        // user that composer.json and composer.lock don't match.
        $installer->setUpdate(true);
        $installer->setDevMode($event->isDevMode());
        // TODO: can we set more flags to match the current run?

        $installer->run();
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Find configuration files matching the configured glob patterns and
     * merge their contents with the master package.
     *
     * @param array $config
     */
    private function mergePackages(array $config)
    {
        $paths  = array_reduce(
            array_map('glob', $config['include']),
            'array_merge',
            []
        );

        foreach ($paths as $path) {
            $this->loadFile($this->getRootPackage(), $path);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param RootPackage $root
     * @param string      $path
     */
    private function loadFile(RootPackage $root, $path)
    {
        if (in_array($path, $this->loadedFiles)) {
            $this->log->debug("Skipping duplicate <comment>{$path}</comment>...");

            return;
        }

        $this->log->debug("Loading <comment>{$path}</comment>...");
        $this->loadedFiles[] = $path;
        $json                = $this->readPackageJson($path);
        $package             = $this->jsonToPackage($json);

        $this->mergeRequires($root, $package);
        $this->mergeDevRequires($root, $package);
        $this->mergeAutoload($root, $package, $path);
        $this->mergeDevAutoload($root, $package, $path);
        $this->mergeSuggests($root, $package);
        $this->addRepositories($root, $json);
        $this->addExtras($json);
    }

    /**
     * Read the contents of a composer.json style file into an array.
     *
     * @param  string $path
     *
     * @return array
     */
    private function readPackageJson($path)
    {
        $json = (new JsonFile($path))->read();

        if ( ! isset($json['name'])) {
            $json['name'] = self::PLUGIN_KEY . '/' . strtr($path, DIRECTORY_SEPARATOR, '-');
        }

        if ( ! isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return $json;
    }

    /**
     * Merge required packages
     *
     * @param RootPackage     $root
     * @param CompletePackage $package
     */
    private function mergeRequires(RootPackage $root, CompletePackage $package)
    {
        $requires = $package->getRequires();

        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $root->setRequires($this->mergeLinks(
            $root->getRequires(),
            $requires,
            $this->duplicateLinks['require']
        ));
    }

    /**
     * Merge required dev packages
     *
     * @param RootPackage     $root
     * @param CompletePackage $package
     */
    private function mergeDevRequires(RootPackage $root, CompletePackage $package)
    {
        $requires = $package->getDevRequires();

        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $root->setDevRequires($this->mergeLinks(
            $root->getDevRequires(),
            $requires,
            $this->duplicateLinks['require-dev']
        ));
    }

    /**
     * Extract and merge stability flags from the given collection of requires.
     *
     * @param RootPackage $root
     * @param array       $requires
     */
    private function mergeStabilityFlags(RootPackage $root, array $requires)
    {
        $flags = $root->getStabilityFlags();

        foreach ($requires as $name => $link) {
            /** @var \Composer\Package\Link $link */
            $name         = strtolower($name);
            $version      = $link->getPrettyConstraint();
            $stability    = VersionParser::parseStability($version);
            $flags[$name] = BasePackage::$stabilities[$stability];
        }

        $root->setStabilityFlags($flags);
    }

    /**
     * Merge autoload
     *
     * @param RootPackage     $root
     * @param CompletePackage $package
     * @param string          $path
     */
    private function mergeAutoload(RootPackage $root, CompletePackage $package, $path)
    {
        if (empty($autoload = $package->getAutoload())) {
            return;
        }

        $this->prependPath($path, $autoload);

        $root->setAutoload(array_merge_recursive(
            $root->getAutoload(),
            $autoload
        ));
    }

    /**
     * Merge dev autoload
     *
     * @param RootPackage     $root
     * @param CompletePackage $package
     * @param string          $path
     */
    private function mergeDevAutoload(RootPackage $root, CompletePackage $package, $path)
    {
        if (empty($devAutoload = $package->getDevAutoload())) {
            return;
        }

        $this->prependPath($path, $devAutoload);

        $root->setDevAutoload(array_merge_recursive(
            $root->getDevAutoload() ?: [],
            $devAutoload
        ));
    }

    /**
     * Merge package suggests
     *
     * @param RootPackage     $root
     * @param CompletePackage $package
     */
    private function mergeSuggests(RootPackage $root, CompletePackage $package)
    {
        if ($package->getSuggests()) {
            $root->setSuggests(
                array_merge($root->getSuggests(), $package->getSuggests())
            );
        }
    }

    /**
     * Add package extras
     *
     * @param array $json
     */
    private function addExtras(array $json)
    {
        if ($this->recurse && isset($json['extra'][self::PLUGIN_KEY])) {
            $this->mergePackages($json['extra'][self::PLUGIN_KEY]);
        }
    }

    /**
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param RootPackage $root
     * @param array       $json
     */
    private function addRepositories(RootPackage $root, array $json)
    {
        if ( ! isset($json['repositories'])) {
            return;
        }

        $repoManager     = $this->composer->getRepositoryManager();
        $newRepositories = array_map(function($repoJson) use ($repoManager) {
            return $this->createRepository($repoManager, $repoJson);
        }, $json['repositories']);

        $root->setRepositories(array_merge(
            array_filter($newRepositories),
            $root->getRepositories()
        ));
    }

    /**
     * Create repository
     *
     * @param  RepositoryManager $repoManager
     * @param  array             $json
     *
     * @return \Composer\Repository\RepositoryInterface
     */
    private function createRepository(RepositoryManager &$repoManager, array $json)
    {
        if ( ! isset($json['type'])) {
            return null;
        }

        $this->log->debug("Adding {$json['type']} repository");
        $repo = $repoManager->createRepository($json['type'], $json);
        $repoManager->addRepository($repo);

        return $repo;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge two collections of package links and collect duplicates for
     * subsequent processing.
     *
     * @param  array $origin Primary collection
     * @param  array $merge  Additional collection
     * @param  array &$dups  Duplicate storage
     *
     * @return array
     */
    private function mergeLinks(array $origin, array $merge, array &$dups)
    {
        /** @var \Composer\Package\Link $link */
        foreach ($merge as $name => $link) {
            if ( ! isset($origin[$name])) {
                $this->log->debug("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            }
            else {
                // Defer to solver.
                $this->log->debug("Deferring duplicate <comment>{$name}</comment>");
                $dups[] = $link;
            }
        }

        return $origin;
    }

    /**
     * Convert the json array to package object
     *
     * @param  array $json
     *
     * @return CompletePackage
     */
    private function jsonToPackage($json)
    {
        $package = $this->loader->load($json);

        if ( ! $package instanceof CompletePackage) {
            throw new UnexpectedValueException(
                'Expected instance of CompletePackage, got ' . get_class($package)
            );
        }

        return $package;
    }

    /**
     * Prepend a path to a collection of autoloads.
     *
     * @param string $basePath
     * @param array  $autoloads
     */
    public function prependPath($basePath, array &$autoloads)
    {
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        array_walk_recursive(
            $autoloads,
            function(&$path) use ($basePath) {
                $path = $basePath . $path;
            }
        );
    }
}
