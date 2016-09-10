<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Utilities\Util;
use Composer\Package\RootPackageInterface;

/**
 * Trait     DevTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait DevTrait
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
     * Merge just the dev portion into a RootPackageInterface.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    public function mergeDevInto(RootPackageInterface $root, PluginState $state)
    {
        $this->mergeDevRequires($root, $state);
        $this->mergeDevAutoload($root);
        $this->mergeReferences($root);
    }

    /**
     * Merge require-dev into RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    abstract protected function mergeDevRequires(RootPackageInterface $root, PluginState $state);

    /**
     * Merge autoload-dev into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    private function mergeDevAutoload(RootPackageInterface $root)
    {
        if ( ! empty($autoload = $this->getPackage()->getDevAutoload())) {
            static::unwrapIfNeeded($root, 'setDevAutoload')
                ->setDevAutoload(array_merge_recursive(
                    $root->getDevAutoload(), Util::fixRelativePaths($this->getPath(), $autoload)
                ));
        }
    }

    /**
     * Update the root packages reference information.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    abstract protected function mergeReferences(RootPackageInterface $root);
}
