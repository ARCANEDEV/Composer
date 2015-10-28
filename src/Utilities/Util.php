<?php namespace Arcanedev\Composer\Utilities;

/**
 * Class     Util
 *
 * @package  Arcanedev\Composer\Utilities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class Util
{
    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Fix a collection of paths that are relative to this package to be
     * relative to the base package.
     *
     * @param  string  $base
     * @param  array   $paths
     *
     * @return array
     */
    public static function fixRelativePaths($base, array $paths)
    {
        $base = dirname($base);
        $base = ($base === '.') ? '' : "{$base}/";

        array_walk_recursive($paths, function (&$path) use ($base) {
            $path = "{$base}{$path}";
        });

        return $paths;
    }
}
