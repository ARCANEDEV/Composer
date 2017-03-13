<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Entities\PluginState;
use Composer\Package\RootPackageInterface;

/**
 * Class     ScriptsTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait ScriptsTrait
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
     * Merge scripts config into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    protected function mergeScripts(RootPackageInterface $root, PluginState $state)
    {
        $scripts = $this->getPackage()->getScripts();

        if ( ! $state->shouldMergeScripts() || empty($scripts))
            return;

        $rootScripts = $root->getScripts();

        $scripts = $state->replaceDuplicateLinks()
            ? array_merge($rootScripts, $scripts)
            : array_merge($scripts, $rootScripts);

        self::unwrapIfNeeded($root, 'setScripts')
            ->setScripts($scripts);
    }
}
