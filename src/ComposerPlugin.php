<?php namespace Arcanedev\Composer;

use Composer\Composer;
use Composer\Config;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\InstallerEvent;
use Composer\Installer\InstallerEvents;
use Composer\IO\IOInterface;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Plugin\PluginInterface;
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
     * @var IOInterface $inputOutput
     */
    protected $inputOutput;

    /**
     * @var ArrayLoader $loader
     */
    protected $loader;

    /**
     * @var array $duplicateLinks
     */
    protected $duplicateLinks;

    /**
     * @var bool $devMode
     */
    protected $devMode;

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

        if ( ! $root instanceof RootPackage) {
            throw new UnexpectedValueException(
                'Expected instance of RootPackage, got ' . get_class($root)
            );
        }

        return $root;
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
        $this->composer    = $composer;
        $this->inputOutput = $io;
    }

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            ScriptEvents::PRE_INSTALL_CMD               => 'onInstall',
            ScriptEvents::PRE_UPDATE_CMD                => 'onUpdate',
            ScriptEvents::PRE_AUTOLOAD_DUMP             => 'onAutoloadDump',
            InstallerEvents::PRE_DEPENDENCIES_SOLVING   => 'onDependencySolve',
        ];
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    public function onInstall(Event $event)
    {
        $this->onInstallOrUpdateOrDump($event);
    }

    public function onUpdate(Event $event)
    {
        $this->onInstallOrUpdateOrDump($event);
    }

    public function onAutoloadDump(Event $event)
    {
        $this->onInstallOrUpdateOrDump($event);
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
            return;
        }

        $request = $event->getRequest();

        /** @var \Composer\Package\Link $link */
        foreach ($this->duplicateLinks['require'] as $link) {
            $this->debug("Adding dependency <comment>{$link}</comment>");
            $request->install($link->getTarget(), $link->getConstraint());
        }

        if ($this->devMode) {
            foreach ($this->duplicateLinks['require-dev'] as $link) {
                $this->debug("Adding dev dependency <comment>{$link}</comment>");
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
    public function onInstallOrUpdateOrDump(Event $event)
    {
        $config = $this->readConfig($this->getRootPackage());

        if (isset($config['recurse'])) {
            $this->recurse = (bool)$config['recurse'];
        }

        if ($config['include']) {
            $this->loader = new ArrayLoader;
            $this->duplicateLinks = [
                'require'       => [],
                'require-dev'   => [],
            ];
            $this->devMode = $event->isDevMode();
            $this->mergePackages($config);
        }
    }

    /**
     * @param  RootPackage $package
     *
     * @return array
     */
    protected function readConfig(RootPackage $package)
    {
        $config = [
            'include' => [],
        ];

        $extra = $package->getExtra();

        if (isset($extra[self::PLUGIN_KEY])) {
            $config = array_merge($config, $extra[self::PLUGIN_KEY]);

            if ( ! is_array($config['include'])) {
                $config['include'] = [$config['include']];
            }
        }

        return $config;
    }

    /**
     * Find configuration files matching the configured glob patterns and
     * merge their contents with the master package.
     *
     * @param array $config
     */
    protected function mergePackages(array $config)
    {
        $root   = $this->getRootPackage();
        $paths  = array_reduce(
            array_map('glob', $config['include']),
            'array_merge',
            []
        );

        foreach ($paths as $path) {
            $this->loadFile($root, $path);
        }
    }

    /**
     * Read a JSON file and merge its contents
     *
     * @param RootPackage $root
     * @param string $path
     */
    protected function loadFile($root, $path)
    {
        if (in_array($path, $this->loadedFiles)) {
            $this->debug("Skipping duplicate <comment>$path</comment>...");

            return;
        }
        else {
            $this->loadedFiles[] = $path;
        }

        $this->debug("Loading <comment>{$path}</comment>...");
        $json       = $this->readPackageJson($path);
        $package    = $this->jsonToPackage($json);

        $this->mergeRequires($root, $package);
        $this->mergeDevRequires($root, $package);
        $this->mergeAutoload($root, $package, $path);

        if (isset($json['repositories'])) {
            $this->addRepositories($json['repositories'], $root);
        }

        if ($package->getSuggests()) {
            $root->setSuggests(array_merge(
                $root->getSuggests(),
                $package->getSuggests()
            ));
        }

        if (
            $this->recurse &&
            isset($json['extra'][self::PLUGIN_KEY])
        ) {
            $this->mergePackages($json['extra'][self::PLUGIN_KEY]);
        }
    }

    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package
     * object by providing dummy "name" and "version" values if they have not
     * been provided in the file. This is consistent with the default root
     * package loading behavior of Composer.
     *
     * @param string $path
     * @return array
     */
    protected function readPackageJson($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();

        if ( ! isset($json['name'])) {
            $json['name'] = 'merge-plugin/' . strtr($path, DIRECTORY_SEPARATOR, '-');
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
    protected function mergeRequires(RootPackage $root, CompletePackage $package)
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
    protected function mergeDevRequires(RootPackage $root, CompletePackage $package)
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
     * @param RootPackage     $root
     * @param CompletePackage $package
     * @param string          $path
     */
    protected function mergeAutoload(RootPackage $root, CompletePackage $package, $path)
    {
        $autoload = $package->getAutoload();

        if (empty($autoload)) {
            return;
        }

        $packagePath = substr($path, 0, strrpos($path, '/') + 1);

        array_walk_recursive($autoload, function(&$path) use ($packagePath) {
            $path = $packagePath . $path;
        });

        $root->setAutoload(array_merge_recursive(
            $root->getAutoload(),
            $autoload
        ));
    }

    /**
     * Extract and merge stability flags from the given collection of requires.
     *
     * @param RootPackage $root
     * @param array       $requires
     */
    protected function mergeStabilityFlags(RootPackage $root, array $requires)
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
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param array       $repositories
     * @param RootPackage $root
     */
    protected function addRepositories(array $repositories, RootPackage $root)
    {
        $repoManager = $this->composer->getRepositoryManager();
        $newRepos    = [];

        foreach ($repositories as $repoJson) {
            if ( ! isset($repoJson['type'])) {
                continue;
            }

            $this->debug("Adding {$repoJson['type']} repository");
            $repo = $repoManager->createRepository(
                $repoJson['type'],
                $repoJson
            );
            $repoManager->addRepository($repo);
            $newRepos[] = $repo;
        }

        $root->setRepositories(array_merge(
            $newRepos,
            $root->getRepositories()
        ));
    }

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
    protected function mergeLinks(array $origin, array $merge, array &$dups)
    {
        /** @var \Composer\Package\Link $link */
        foreach ($merge as $name => $link) {
            if ( ! isset($origin[$name])) {
                $this->debug("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            }
            else {
                // Defer to solver.
                $this->debug("Deferring duplicate <comment>{$name}</comment>");
                $dups[] = $link;
            }
        }

        return $origin;
    }

    /**
     * @param  $json
     *
     * @return CompletePackage
     */
    protected function jsonToPackage($json)
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
     * Log a debug message
     *
     * Messages will be output at the "verbose" logging level (eg `-v` needed
     * on the Composer command).
     *
     * @param string $message
     */
    protected function debug($message)
    {
        if ($this->inputOutput->isVerbose()) {
            $message = "  <info>[merge]</info> {$message}";

            if (method_exists($this->inputOutput, 'writeError')) {
                $this->inputOutput->writeError($message);
            }
            else {
                // Backwards compatiblity for Composer before cb336a5
                $this->inputOutput->write($message);
            }
        }
    }
}
