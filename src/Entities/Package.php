<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Utilities\Logger;
use Arcanedev\Composer\Utilities\Util;
use Composer\Composer;
use Composer\Package\BasePackage;
use Composer\Package\CompletePackage;
use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\RepositoryManager;

/**
 * Class     Package
 *
 * @package  Arcanedev\Composer\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
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
     * Make a Package instance.
     *
     * @param  string    $path      Path to composer.json file
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
     * @param  RootPackageInterface  $root
     * @param  PluginState           $state
     */
    public function mergeInto(RootPackageInterface $root, PluginState $state)
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
     * @param  RootPackageInterface  $root
     */
    private function addRepositories(RootPackageInterface $root)
    {
        if ( ! isset($this->json['repositories'])) return;

        $repoManager  = $this->composer->getRepositoryManager();
        $repositories = [];

        foreach ($this->json['repositories'] as $repoJson) {
            $this->addRepository($repoManager, $repositories, $repoJson);
        }

        self::unwrapIfNeeded($root, 'setRepositories')
            ->setRepositories(array_merge($repositories, $root->getRepositories()));
    }

    /**
     * Add a repository to collection of repositories.
     *
     * @param  RepositoryManager  $repoManager
     * @param  array              $repositories
     * @param  array              $repoJson
     */
    private function addRepository(
        RepositoryManager $repoManager,
        array &$repositories,
        $repoJson
    ) {
        if ( ! isset($repoJson['type'])) return;

        $this->logger->info("Adding {$repoJson['type']} repository");

        $repository = $repoManager->createRepository(
            $repoJson['type'], $repoJson
        );

        $repoManager->addRepository($repository);
        $repositories[] = $repository;
    }

    /**
     * Merge require into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     * @param  PluginState           $state
     */
    private function mergeRequires(RootPackageInterface $root, PluginState $state)
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
     * Merge require-dev into RootPackage.
     *
     * @param  RootPackageInterface  $root
     * @param  PluginState           $state
     */
    private function mergeDevRequires(RootPackageInterface $root, PluginState $state)
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
                $this->logger->info("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            }
            else {
                // Defer to solver.
                $this->logger->info("Deferring duplicate <comment>{$name}</comment>");
                $duplicateLinks[] = $link;
            }
        }

        return $origin;
    }

    /**
     * Merge autoload into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    private function mergeAutoload(RootPackageInterface $root)
    {
        $autoload = $this->package->getAutoload();

        if (empty($autoload)) return;

        self::unwrapIfNeeded($root, 'setAutoload')
            ->setAutoload(array_merge_recursive(
                $root->getAutoload(), Util::fixRelativePaths($this->path, $autoload)
            ));
    }

    /**
     * Merge autoload-dev into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    private function mergeDevAutoload(RootPackageInterface $root)
    {
        $autoload = $this->package->getDevAutoload();

        if (empty($autoload)) return;

        self::unwrapIfNeeded($root, 'setDevAutoload')
            ->setDevAutoload(array_merge_recursive(
                $root->getDevAutoload(), Util::fixRelativePaths($this->path, $autoload)
            ));
    }

    /**
     * Extract and merge stability flags from the given collection of
     * requires and merge them into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     * @param  array        $requires
     */
    private function mergeStabilityFlags(RootPackageInterface $root, array $requires)
    {
        $flags = $this->loadFlags($root, $requires);

        self::unwrapIfNeeded($root, 'setStabilityFlags')
            ->setStabilityFlags($flags);
    }

    /**
     * Load stability flags.
     *
     * @param  RootPackageInterface  $root
     * @param  array                 $requires
     *
     * @return array
     */
    private function loadFlags(RootPackageInterface $root, array $requires)
    {
        $flags = $root->getStabilityFlags();

        // Adapted from RootPackageLoader::extractStabilityFlags
        $rootMin = BasePackage::$stabilities[$root->getMinimumStability()];
        $pattern = '/^[^@]*?@(' . implode('|', array_keys(BasePackage::$stabilities)) .')$/i';

        foreach ($requires as $name => $link) {
            /** @var Link $link */
            $name      = strtolower($name);
            $version   = $link->getPrettyConstraint();
            $stability = $this->extractStability($pattern, $version, $rootMin);

            if (
                $stability !== null &&
                ! (isset($flags[$name]) && $flags[$name] > $stability)
            ) {
                // Store if less stable than current stability for package
                $flags[$name] = $stability;
            }
        }

        return $flags;
    }

    /**
     * Extract stability.
     *
     * @param  string  $pattern
     * @param  string  $version
     * @param  string  $rootMin
     *
     * @return mixed
     */
    private function extractStability($pattern, $version, $rootMin)
    {
        $stability = null;
        $unAliased = preg_replace('/^([^,\s@]+) as .+$/', '$1', $version);

        if (preg_match($pattern, $version, $match)) {
            // Extract explicit '@stability'
            return BasePackage::$stabilities[
                VersionParser::normalizeStability($match[1])
            ];
        }

        if (preg_match('/^[^,\s@]+$/', $unAliased)) {
            // Extract explicit '-stability'
            $stability = BasePackage::$stabilities[
                VersionParser::parseStability($unAliased)
            ];

            if (
                $stability === BasePackage::STABILITY_STABLE || $rootMin > $stability
            ) {
                // Ignore if 'stable' or more stable than the global minimum
                return null;
            }
        }

        return $stability;
    }

    /**
     * Merge conflicting packages into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    protected function mergeConflicts(RootPackageInterface $root)
    {
        $conflicts = $this->package->getConflicts();

        if (empty($conflicts)) return;

        $unwrapped = self::unwrapIfNeeded($root, 'setConflicts');

        if ($root !== $unwrapped) {
            $this->logger->warning(
                'This Composer version does not support ' .
                "'conflicts' merging for aliased packages."
            );
        }

        $unwrapped->setConflicts(array_merge($root->getConflicts(), $conflicts));
    }

    /**
     * Merge replaced packages into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    protected function mergeReplaces(RootPackageInterface $root)
    {
        $replaces = $this->package->getReplaces();

        if (empty($replaces)) return;

        $unwrapped = self::unwrapIfNeeded($root, 'setReplaces');

        if ($root !== $unwrapped) {
            $this->logger->warning(
                'This Composer version does not support ' .
                "'replaces' merging for aliased packages."
            );
        }

        $unwrapped->setReplaces(array_merge($root->getReplaces(), $replaces));
    }

    /**
     * Merge provided virtual packages into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    protected function mergeProvides(RootPackageInterface $root)
    {
        $provides  = $this->package->getProvides();

        if (empty($provides)) return;

        $unwrapped = self::unwrapIfNeeded($root, 'setProvides');

        if ($root !== $unwrapped) {
            $this->logger->warning(
                'This Composer version does not support ' .
                "'provides' merging for aliased packages."
            );
        }

        $unwrapped->setProvides(array_merge($root->getProvides(), $provides));
    }

    /**
     * Merge suggested packages into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     */
    private function mergeSuggests(RootPackageInterface $root)
    {
        $suggests = $this->package->getSuggests();

        if (empty($suggests)) return;

        self::unwrapIfNeeded($root, 'setSuggests')
            ->setSuggests(array_merge($root->getSuggests(), $suggests));
    }

    /**
     * Merge extra config into a RootPackage.
     *
     * @param  RootPackageInterface  $root
     * @param  PluginState           $state
     */
    private function mergeExtra(RootPackageInterface $root, PluginState $state)
    {
        $extra     = $this->package->getExtra();
        $unwrapped = self::unwrapIfNeeded($root, 'setExtra');

        unset($extra['merge-plugin']);

        if ( ! $state->shouldMergeExtra() || empty($extra)) {
            return;
        }

        $mergedExtra = $this->getExtra($unwrapped, $state, $extra);

        $unwrapped->setExtra($mergedExtra);
    }

    /**
     * Get extra config.
     *
     * @param  RootPackageInterface  $root
     * @param  PluginState           $state
     * @param  array                 $extra
     *
     * @return array
     */
    private function getExtra(
        RootPackageInterface $root, PluginState $state, $extra
    ) {
        $rootExtra   = $root->getExtra();
        $mergedExtra = array_merge($rootExtra, $extra);

        if ($state->replaceDuplicateLinks()) {
            return $mergedExtra;
        }

        foreach ($extra as $key => $value) {
            if ( ! isset($rootExtra[$key])) continue;

            $this->logger->info(
                "Ignoring duplicate <comment>{$key}</comment> in <comment>{$this->path}</comment> extra config."
            );
        }

        return array_merge($extra, $rootExtra);
    }

    /**
     * Get a full featured Package from a RootPackageInterface.
     *
     * @param  RootPackageInterface  $root
     * @param  string                $method
     *
     * @return RootPackageInterface|RootPackage
     */
    private static function unwrapIfNeeded(
        RootPackageInterface $root, $method = 'setExtra'
    ) {
        if (
            $root instanceof RootAliasPackage &&
            ! method_exists($root, $method)
        ) {
            // Unwrap and return the aliased RootPackage.
            $root = $root->getAliasOf();
        }

        return $root;
    }
}
