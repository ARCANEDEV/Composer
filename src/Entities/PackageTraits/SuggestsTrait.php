<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Composer\Package\RootPackageInterface;

/**
 * Trait     SuggestsTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait SuggestsTrait
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
     * Merge suggested packages into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    protected function mergeSuggests(RootPackageInterface $root)
    {
        if ( ! empty($suggests = $this->getPackage()->getSuggests())) {
            static::unwrapIfNeeded($root, 'setSuggests')
                ->setSuggests(array_merge($root->getSuggests(), $suggests));
        }
    }
}
