<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Utilities\NestedArray;
use Composer\Package\RootPackageInterface;

/**
 * Trait     ExtraTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait ExtraTrait
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
     * Merge extra config into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    protected function mergeExtra(RootPackageInterface $root, PluginState $state)
    {
        $extra = $this->getPackage()->getExtra();
        unset($extra['merge-plugin']);

        if ($state->shouldMergeExtra() && ! empty($extra)) {
            $unwrapped = static::unwrapIfNeeded($root, 'setExtra');
            $unwrapped->setExtra(
                $this->getExtra($unwrapped, $state, $extra)
            );
        }
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

        if ($state->replaceDuplicateLinks()) {
            return self::mergeExtraArray($state->shouldMergeExtraDeep(), $rootExtra, $extra);
        }

        if ( ! $state->shouldMergeExtraDeep()) {
            foreach (array_intersect(array_keys($extra), array_keys($rootExtra)) as $key) {
                $this->getLogger()->info(
                    "Ignoring duplicate <comment>{$key}</comment> in ".
                    "<comment>{$this->getPath()}</comment> extra config."
                );
            }
        }

        return static::mergeExtraArray($state->shouldMergeExtraDeep(), $extra, $rootExtra);
    }

    /**
     * Merges two arrays either via arrayMergeDeep or via array_merge.
     *
     * @param  bool  $mergeDeep
     * @param  array  $array1
     * @param  array  $array2
     *
     * @return array
     */
    private static function mergeExtraArray($mergeDeep, $array1, $array2)
    {
        return $mergeDeep
            ? NestedArray::mergeDeep($array1, $array2)
            : array_merge($array1, $array2);
    }
}
