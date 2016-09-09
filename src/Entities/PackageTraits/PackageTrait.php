<?php namespace Arcanedev\Composer\Entities\PackageTraits;
use Composer\Package\RootPackageInterface;

/**
 * Trait     PackageTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait PackageTrait
{
    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get composer.
     *
     * @return \Composer\Composer
     */
    abstract public function getComposer();

    /**
     * Get the Logger.
     *
     * @return \Arcanedev\Composer\Utilities\Logger
     */
    abstract public function getLogger();

    /**
     * Get the json.
     *
     * @return array
     */
    abstract public function getJson();

    /**
     * Get the package.
     *
     * @return \Composer\Package\CompletePackage $package
     */
    abstract public function getPackage();

    /**
     * Get the path.
     *
     * @return string
     */
    abstract public function getPath();

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get a full featured Package from a RootPackageInterface.
     *
     * @param  \Composer\Package\RootPackageInterface|\Composer\Package\RootPackage  $root
     * @param  string                                                                $method
     *
     * @return \Composer\Package\RootPackageInterface|\Composer\Package\RootPackage
     */
    abstract protected function unwrapIfNeeded(
        RootPackageInterface $root, $method = 'setExtra'
    );
}
