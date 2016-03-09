<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Utilities\Logger;
use Arcanedev\Composer\Utilities\Util;
use Composer\Composer;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
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
    /** @var \Composer\Composer $composer */
    protected $composer;

    /** @var \Arcanedev\Composer\Utilities\Logger $logger */
    protected $logger;

    /** @var string $path */
    protected $path;

    /** @var array $json */
    protected $json;

    /** @var \Composer\Package\CompletePackage $package */
    protected $package;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Make a Package instance.
     *
     * @param  string                                $path
     * @param  \Composer\Composer                    $composer
     * @param  \Arcanedev\Composer\Utilities\Logger  $logger
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
     * Get list of additional packages to require if precessing recursively.
     *
     * @return array
     */
    public function getRequires()
    {
        return isset($this->json['extra']['merge-plugin']['require'])
            ? $this->json['extra']['merge-plugin']['require']
            : [];
    }

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
     * Merge this package into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    public function mergeInto(RootPackageInterface $root, PluginState $state)
    {
        $this->addRepositories($root);

        $this->mergeRequires($root, $state);
        $this->mergeAutoload($root);

        if ($state->isDevMode()) {
            $this->mergeDevRequires($root, $state);
            $this->mergeDevAutoload($root);
        }

        $this->mergePackageLinks('conflict', $root);
        $this->mergePackageLinks('replace',  $root);
        $this->mergePackageLinks('provide',  $root);

        $this->mergeSuggests($root);
        $this->mergeExtra($root, $state);
    }

    /**
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
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
     * @param  \Composer\Repository\RepositoryManager  $repoManager
     * @param  array                                   $repositories
     * @param  array                                   $repoJson
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
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    private function mergeRequires(RootPackageInterface $root, PluginState $state)
    {
        $requires = $this->package->getRequires();

        if (empty($requires)) return;

        $this->mergeStabilityFlags($root, $requires);

        $duplicateLinks = [];
        $requires       = $this->replaceSelfVersionDependencies(
            'require', $requires, $root
        );

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
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    private function mergeDevRequires(RootPackageInterface $root, PluginState $state)
    {
        $requires = $this->package->getDevRequires();

        if (empty($requires)) return;

        $this->mergeStabilityFlags($root, $requires);

        $duplicateLinks = [];
        $requires       = $this->replaceSelfVersionDependencies(
            'require-dev', $requires, $root
        );

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
     * @param  \Composer\Package\Link[]  $origin          Primary collection
     * @param  array                     $merge           Additional collection
     * @param  bool                      $replace         Replace existing links ?
     * @param  array                     $duplicateLinks  Duplicate storage
     *
     * @return \Composer\Package\Link[]                   Merged collection
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
     * @param  \Composer\Package\RootPackageInterface  $root
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
     * @param  \Composer\Package\RootPackageInterface  $root
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
     * @param  \Composer\Package\RootPackageInterface  $root
     * @param  \Composer\Package\Link[]                $requires
     */
    private function mergeStabilityFlags(RootPackageInterface $root, array $requires)
    {
        $flags = StabilityFlags::extract(
            $root->getStabilityFlags(),
            $root->getMinimumStability(),
            $requires
        );

        self::unwrapIfNeeded($root, 'setStabilityFlags')
            ->setStabilityFlags($flags);
    }

    /**
     * Merge package links of the given type into a RootPackageInterface
     *
     * @param  string                                  $type  'conflict', 'replace' or 'provide'
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    protected function mergePackageLinks($type, RootPackageInterface $root)
    {
        $linkType = BasePackage::$supportedLinkTypes[$type];
        $getter   = 'get' . ucfirst($linkType['method']);
        $setter   = 'set' . ucfirst($linkType['method']);

        $links = $this->package->{$getter}();

        if (empty($links)) return;

        $unwrapped = self::unwrapIfNeeded($root, $setter);

        // @codeCoverageIgnoreStart
        if ($root !== $unwrapped) {
            $this->logger->warning(
                'This Composer version does not support ' .
                "'{$type}' merging for aliased packages."
            );
        }
        // @codeCoverageIgnoreEnd

        $unwrapped->{$setter}(array_merge(
            $root->{$getter}(),
            $this->replaceSelfVersionDependencies($type, $links, $root)
        ));
    }

    /**
     * Merge suggested packages into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
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
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
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
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     * @param  array                                     $extra
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

        foreach (array_intersect(array_keys($extra), array_keys($rootExtra)) as $key) {
            $this->logger->info(
                "Ignoring duplicate <comment>{$key}</comment> in <comment>{$this->path}</comment> extra config."
            );
        }

        return array_merge($extra, $rootExtra);
    }

    /**
     * Update Links with a 'self.version' constraint with the root package's version.
     *
     * @param  string                                  $type
     * @param  array                                   $links
     * @param  \Composer\Package\RootPackageInterface  $root
     *
     * @return array
     */
    protected function replaceSelfVersionDependencies(
        $type, array $links, RootPackageInterface $root
    ) {
        $linkType      = BasePackage::$supportedLinkTypes[$type];
        $version       = $root->getVersion();
        $prettyVersion = $root->getPrettyVersion();
        $vp            = new VersionParser;
        $packages      = $root->{'get' . ucfirst($linkType['method'])}();

        return array_map(function (Link $link) use ($linkType, $version, $prettyVersion, $vp, $packages) {
            if ($link->getPrettyConstraint() !== 'self.version') {
                return $link;
            }

            if (isset($packages[$link->getSource()])) {
                /** @var  \Composer\Package\Link  $package */
                $package       = $packages[$link->getSource()];
                $version       = $package->getConstraint()->getPrettyString();
                $prettyVersion = $package->getPrettyConstraint();
            }

            return new Link(
                $link->getSource(),
                $link->getTarget(),
                $vp->parseConstraints($version),
                $linkType['description'],
                $prettyVersion
            );
        }, $links);
    }

    /**
     * Get a full featured Package from a RootPackageInterface.
     *
     * @param  \Composer\Package\RootPackageInterface|\Composer\Package\RootPackage  $root
     * @param  string                                                                $method
     *
     * @return \Composer\Package\RootPackageInterface|\Composer\Package\RootPackage
     */
    private static function unwrapIfNeeded(
        RootPackageInterface $root, $method = 'setExtra'
    ) {
        return ($root instanceof RootAliasPackage && ! method_exists($root, $method))
            ? $root->getAliasOf()
            : $root;
    }
}
