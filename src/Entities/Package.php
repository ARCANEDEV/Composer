<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Utilities\Logger;
use Arcanedev\Composer\Utilities\Util;
use Composer\Composer;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\RootPackage;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;

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
     * @param  string    $path     Path to composer.json file
     * @param  Composer  $composer
     * @param  Logger    $logger
     */
    public function __construct($path, Composer $composer, Logger $logger)
    {
        $this->path     = $path;
        $this->composer = $composer;
        $this->logger   = $logger;
        $this->json     = PackageJson::read($path);
        $this->package  = PackageJson::convert($this->json);
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
        if (isset($this->json['extra']['merge-plugin']['include'])) {
            return $this->json['extra']['merge-plugin']['include'];
        }

        return [];
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge this package into a RootPackage
     *
     * @param  RootPackage  $root
     * @param  PluginState  $state
     */
    public function mergeInto(RootPackage $root, PluginState $state)
    {
        $this->addRepositories($root);

        $this->mergeRequires($root, $state);
        $this->mergeDevRequires($root, $state);

        $this->mergeConflicts($root);
        $this->mergeReplaces($root);
        $this->mergeProvides($root);
        $this->mergeSuggests($root);

        $this->mergeAutoload($root);
        $this->mergeDevAutoload($root);

        $this->mergeExtra($root, $state);
    }

    /**
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param  RootPackage  $root
     */
    private function addRepositories(RootPackage $root)
    {
        if ( ! isset($this->json['repositories'])) return;

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
     * @param  array              $newRepos
     * @param  RepositoryManager  $repoManager
     * @param  array              $repoJson
     */
    private function addRepository(array &$newRepos, RepositoryManager $repoManager, array $repoJson)
    {
        if ( ! isset($repoJson['type'])) return;

        $this->logger->debug("Adding {$repoJson['type']} repository");

        $repository = $repoManager->createRepository(
            $repoJson['type'],
            $repoJson
        );
        $repoManager->addRepository($repository);

        $newRepos[] = $repository;
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

        if (empty($requires)) return;

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
     * @param  RootPackage  $root
     * @param  PluginState  $state
     */
    private function mergeDevRequires(RootPackage $root, PluginState $state)
    {
        $requires = $this->package->getDevRequires();

        if (empty($requires)) return;

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
     * @param  array  $origin          Primary collection
     * @param  array  $merge           Additional collection
     * @param  bool   $replace         Replace exising links?
     * @param  array  $duplicateLinks  Duplicate storage
     *
     * @return array                   Merged collection
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
     * @param  RootPackage  $root
     */
    private function mergeAutoload(RootPackage $root)
    {
        $autoload = $this->package->getAutoload();

        if (empty($autoload)) return;

        $root->setAutoload(array_merge_recursive(
            $root->getAutoload(),
            Util::prependPaths($this->path, $autoload)
        ));
    }

    /**
     * Merge autoload-dev into a RootPackage
     *
     * @param  RootPackage  $root
     */
    private function mergeDevAutoload(RootPackage $root)
    {
        $autoload = $this->package->getDevAutoload();

        if (empty($autoload)) return;

        $root->setDevAutoload(array_merge_recursive(
            $root->getDevAutoload(),
            Util::prependPaths($this->path, $autoload)
        ));
    }

    /**
     * Extract and merge stability flags from the given collection of
     * requires and merge them into a RootPackage
     *
     * @param  RootPackage  $root
     * @param  array        $requires
     */
    private function mergeStabilityFlags(RootPackage $root, array $requires)
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
     * Merge conflicting packages into a RootPackage
     *
     * @param  RootPackage  $root
     */
    protected function mergeConflicts(RootPackage $root)
    {
        $conflicts = $this->package->getConflicts();

        if (empty($conflicts)) return;

        $root->setconflicts(array_merge($root->getConflicts(), $conflicts));
    }

    /**
     * Merge replaced packages into a RootPackage
     *
     * @param  RootPackage  $root
     */
    protected function mergeReplaces(RootPackage $root)
    {
        $replaces = $this->package->getReplaces();

        if (empty($replaces)) return;

        $root->setReplaces(array_merge($root->getReplaces(), $replaces));
    }

    /**
     * Merge provided virtual packages into a RootPackage
     *
     * @param  RootPackage  $root
     */
    protected function mergeProvides(RootPackage $root)
    {
        $provides = $this->package->getProvides();

        if (empty($provides)) return;

        $root->setProvides(array_merge($root->getProvides(), $provides));
    }

    /**
     * Merge suggested packages into a RootPackage
     *
     * @param  RootPackage  $root
     */
    private function mergeSuggests(RootPackage $root)
    {
        $suggests = $this->package->getSuggests();

        if (empty($suggests)) return;

        $root->setSuggests(array_merge($root->getSuggests(), $suggests));
    }

    /**
     * Merge extra config into a RootPackage
     *
     * @param  RootPackage  $root
     * @param  PluginState  $state
     */
    private function mergeExtra(RootPackage $root, PluginState $state)
    {
        $extra = $this->package->getExtra();
        unset($extra['merge-plugin']);

        if ( ! $state->shouldMergeExtra() || empty($extra)) {
            return;
        }

        $rootExtra = $root->getExtra();
        $replace   = $state->replaceDuplicateLinks();


        if ($replace) {
            $root->setExtra(array_merge($rootExtra, $extra));
        }
        else {
            $this->logDuplicatedExtras($rootExtra, $extra);
            $root->setExtra(array_merge($extra, $rootExtra));
        }
    }

    /**
     * Log the duplicated extras
     *
     * @param  array  $rootExtra
     * @param  array  $extra
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
