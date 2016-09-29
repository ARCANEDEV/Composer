<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Utilities\Logger;
use Composer\Composer;
use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\RootAliasPackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * Class     Package
 *
 * @package  Arcanedev\Composer\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class Package
{
    /* ------------------------------------------------------------------------------------------------
     |  Traits
     | ------------------------------------------------------------------------------------------------
     */
    use PackageTraits\RepositoriesTrait,
        PackageTraits\RequiresTrait,
        PackageTraits\AutoloadTrait,
        PackageTraits\LinksTrait,
        PackageTraits\SuggestsTrait,
        PackageTraits\ExtraTrait,
        PackageTraits\DevTrait,
        PackageTraits\ReferencesTrait;

    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /** @var \Composer\Composer $composer */
    protected $composer;

    /** @var \Arcanedev\Composer\Utilities\Logger $logger */
    protected $logger;

    /** @var \Composer\Package\CompletePackage $package */
    protected $package;

    /** @var string $path */
    protected $path;

    /** @var \Composer\Package\Version\VersionParser $versionParser */
    protected $versionParser;

    /** @var array $json */
    protected $json;

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
        $this->path          = $path;
        $this->composer      = $composer;
        $this->logger        = $logger;
        $this->json          = PackageJson::read($path);
        $this->package       = PackageJson::convert($this->json);
        $this->versionParser = new VersionParser;
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
        return isset($this->getJson()['extra']['merge-plugin']['require'])
            ? $this->getJson()['extra']['merge-plugin']['require']
            : [];
    }

    /**
     * Get list of additional packages to include if precessing recursively.
     *
     * @return array
     */
    public function getIncludes()
    {
        return isset($this->getJson()['extra']['merge-plugin']['include'])
            ? $this->getJson()['extra']['merge-plugin']['include']
            : [];
    }

    /**
     * Get composer.
     *
     * @return \Composer\Composer
     */
    public function getComposer()
    {
        return $this->composer;
    }

    /**
     * Get the Logger.
     *
     * @return \Arcanedev\Composer\Utilities\Logger
     */
    public function getLogger()
    {
        return $this->logger;
    }

    /**
     * Get the json.
     *
     * @return array
     */
    public function getJson()
    {
        return $this->json;
    }

    /**
     * Get the package.
     *
     * @return \Composer\Package\CompletePackage $package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Get the path.
     *
     * @return string
     */
    public function getPath()
    {
        return $this->path;
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
        $this->prependRepositories($root);
        $this->mergeRequires($root, $state);
        $this->mergeAutoload($root);
        $this->mergePackageLinks('conflict', $root);
        $this->mergePackageLinks('replace',  $root);
        $this->mergePackageLinks('provide',  $root);
        $this->mergeSuggests($root);
        $this->mergeExtra($root, $state);

        if ($state->isDevMode())
            $this->mergeDevInto($root, $state);
        else
            $this->mergeReferences($root);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
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
        $vp            = $this->versionParser;
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
    protected static function unwrapIfNeeded(
        RootPackageInterface $root, $method = 'setExtra'
    ) {
        return ($root instanceof RootAliasPackage && ! method_exists($root, $method))
            ? $root->getAliasOf()
            : $root;
    }
}
