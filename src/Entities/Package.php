<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Utilities\Logger;
use Composer\Composer;
use Composer\Json\JsonFile;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;
use UnexpectedValueException;

/**
 * Class Package
 * @package Arcanedev\Composer\Entities
 */
class Package
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var Logger $logger
     */
    protected $logger;

    /**
     * @var string $path
     */
    protected $path;

    /**
     * @var array $json
     */
    protected $json;

    /**
     * @var CompletePackage $package
     */
    protected $package;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * @param  string   $path     Path to composer.json file
     * @param  Composer $composer
     * @param  Logger   $logger
     */
    public function __construct($path, Composer $composer, Logger $logger)
    {
        $this->path     = $path;
        $this->composer = $composer;
        $this->logger   = $logger;
        $this->json     = $this->readPackageJson($path);
        $this->package  = $this->loadPackage($this->json);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get list of additional packages to include if precessing recursively.
     *
     * @return array
     */
    public function getIncludes()
    {
        return isset($this->json['extra']['merge-plugin']['include'])
            ? $this->json['extra']['merge-plugin']['include']
            : [];
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge this package into a RootPackage
     *
     * @param  RootPackage $root
     * @param  PluginState $state
     */
    public function mergeInto(RootPackage $root, PluginState $state)
    {
        $this->mergeRequires($root, $state);
        $this->mergeDevRequires($root, $state);

        $this->mergeAutoload($root);
        $this->mergeDevAutoload($root);

        $this->addRepositories($root);

        $this->mergeExtra($root, $state);
        $this->mergeSuggests($root);
        // TODO: provide, replace, conflict
    }

    /**
     * Merge require into a RootPackage
     *
     * @param  RootPackage $root
     * @param  PluginState $state
     */
    private function mergeRequires(RootPackage $root, PluginState $state)
    {
        $requires = $this->package->getRequires();

        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $duplicateLinks = [];

        $root->setRequires($this->mergeLinks(
            $root->getRequires(),
            $requires,
            $state->replaceDuplicateLinks(),
            $duplicateLinks
        ));

        $state->addDuplicateLinks('require', $duplicateLinks);
    }

    /**
     * Merge require-dev into RootPackage
     *
     * @param  RootPackage $root
     * @param  PluginState $state
     */
    private function mergeDevRequires(RootPackage $root, PluginState $state)
    {
        $requires = $this->package->getDevRequires();

        if (empty($requires)) {
            return;
        }

        $this->mergeStabilityFlags($root, $requires);

        $duplicateLinks = [];

        $root->setDevRequires($this->mergeLinks(
            $root->getDevRequires(),
            $requires,
            $state->replaceDuplicateLinks(),
            $duplicateLinks
        ));

        $state->addDuplicateLinks('require-dev', $duplicateLinks);
    }

    /**
     * Merge two collections of package links and collect duplicates for subsequent processing.
     *
     * @param  array $origin          Primary collection
     * @param  array $merge           Additional collection
     * @param  bool  $replace         Replace exising links?
     * @param  array $duplicateLinks  Duplicate storage
     *
     * @return array                  Merged collection
     */
    private function mergeLinks(array $origin, array $merge, $replace, array &$duplicateLinks)
    {
        foreach ($merge as $name => $link) {
            if ( ! isset($origin[$name]) || $replace) {
                $this->logger->debug("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            }
            else {
                // Defer to solver.
                $this->logger->debug("Deferring duplicate <comment>{$name}</comment>");
                $duplicateLinks[] = $link;
            }
        }

        return $origin;
    }

    /**
     * Merge autoload into a RootPackage
     *
     * @param  RootPackage $root
     */
    protected function mergeAutoload(RootPackage $root)
    {
        $autoload = $this->package->getAutoload();

        if (empty($autoload)) {
            return;
        }

        $this->prependPath($this->path, $autoload);

        $root->setAutoload(array_merge_recursive($root->getAutoload(), $autoload));
    }

    /**
     * Merge autoload-dev into a RootPackage
     *
     * @param  RootPackage $root
     */
    protected function mergeDevAutoload(RootPackage $root)
    {
        $autoload = $this->package->getDevAutoload();

        if (empty($autoload)) {
            return;
        }

        $this->prependPath($this->path, $autoload);

        $root->setDevAutoload(array_merge_recursive($root->getDevAutoload(), $autoload));
    }

    /**
     * Prepend a path to a collection of paths.
     *
     * @param  string $basePath
     * @param  array  $paths
     */
    protected function prependPath($basePath, array &$paths)
    {
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        array_walk_recursive($paths, function (&$localPath) use ($basePath) {
            $localPath = $basePath . $localPath;
        });
    }

    /**
     * Extract and merge stability flags from the given collection of
     * requires and merge them into a RootPackage
     *
     * @param  RootPackage $root
     * @param  array       $requires
     */
    protected function mergeStabilityFlags(RootPackage $root, array $requires)
    {
        $flags = $root->getStabilityFlags();

        foreach ($requires as $name => $link) {
            /** @var Link $link */
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
     * @param  RootPackage $root
     */
    protected function addRepositories(RootPackage $root)
    {
        if ( ! $this->hasRepositories()) {
            return;
        }

        $repoManager = $this->composer->getRepositoryManager();
        $newRepos    = [];

        foreach ($this->json['repositories'] as $repoJson) {
            $this->addRepository($newRepos, $repoManager, $repoJson);
        }

        $root->setRepositories(array_merge($newRepos, $root->getRepositories()));
    }

    /**
     * Add repository to collection
     *
     * @param  array             $newRepos
     * @param  RepositoryManager $repoManager
     * @param  array             $repoJson
     */
    private function addRepository(array &$newRepos, RepositoryManager $repoManager, array $repoJson)
    {
        if ( ! isset($repoJson['type'])) {
            return;
        }

        $this->logger->debug("Adding {$repoJson['type']} repository");

        $repository = $repoManager->createRepository(
            $repoJson['type'],
            $repoJson
        );
        $repoManager->addRepository($repository);

        $newRepos[] = $repository;
    }

    /**
     * Merge extra config into a RootPackage
     *
     * @param  RootPackage $root
     * @param  PluginState $state
     */
    public function mergeExtra(RootPackage $root, PluginState $state)
    {
        $extra = $this->package->getExtra();
        unset($extra['merge-plugin']);

        if ( ! $state->shouldMergeExtra() || empty($extra)) {
            return;
        }

        $rootExtra = $root->getExtra();
        $replace   = $state->replaceDuplicateLinks();

        if ( ! $replace) {
            $this->logDuplicatedExtras($rootExtra, $extra);
        }

        $root->setExtra(
            $replace ? array_merge($rootExtra, $extra) : array_merge($extra, $rootExtra)
        );
    }

    /**
     * Merge suggested packages into a RootPackage
     *
     * @param  RootPackage $root
     */
    protected function mergeSuggests(RootPackage $root)
    {
        $suggests = $this->package->getSuggests();

        if ( ! empty($suggests)) {
            $root->setSuggests(array_merge($root->getSuggests(), $suggests));
        }
    }

    /* ------------------------------------------------------------------------------------------------
     |  Check Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Check if package has repositories
     *
     * @return bool
     */
    private function hasRepositories()
    {
        return isset($this->json['repositories']);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package object
     * by providing dummy "name" and "version" values if they have not been provided in the file.
     * This is consistent with the default root package loading behavior of Composer.
     *
     * @param  string $path
     *
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
     * Load the package
     *
     * @return CompletePackage
     */
    protected function loadPackage($json)
    {
        $loader  = new ArrayLoader;
        $package = $loader->load($json);

        // @codeCoverageIgnoreStart
        if ( ! $package instanceof CompletePackage) {
            throw new UnexpectedValueException(
                'Expected instance of CompletePackage, got ' .
                get_class($package)
            );
        }
        // @codeCoverageIgnoreEnd

        return $package;
    }

    /**
     * Log the duplicated extras
     *
     * @param  array $rootExtra
     * @param  array $extra
     */
    private function logDuplicatedExtras(array $rootExtra, array $extra)
    {
        foreach ($extra as $key => $value) {
            if (isset($rootExtra[$key])) {
                $this->logger->debug(
                    "Ignoring duplicate <comment>{$key}</comment> in ".
                    "<comment>{$this->path}</comment> extra config."
                );
            }
        }
    }
}
