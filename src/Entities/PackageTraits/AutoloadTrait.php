<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Utilities\Util;
use Composer\Package\RootPackageInterface;

/**
 * Trait     AutoloadTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait AutoloadTrait
{
    /* ------------------------------------------------------------------------------------------------
     |  Traits
     | ------------------------------------------------------------------------------------------------
     */
    use PackageTrait;

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge autoload into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    private function mergeAutoload(RootPackageInterface $root)
    {
        if ( ! empty($autoload = $this->getPackage()->getAutoload())) {
            static::unwrapIfNeeded($root, 'setAutoload')
                ->setAutoload(array_merge_recursive(
                    $root->getAutoload(), Util::fixRelativePaths($this->getPath(), $autoload)
                ));
        }
    }
}
