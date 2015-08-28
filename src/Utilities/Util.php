<?php namespace Arcanedev\Composer\Utilities;

class Util
{
    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Prepend a path to a collection of paths.
     *
     * @param  string  $basePath
     * @param  array   $paths
     *
     * @return array
     */
    public static function prependPaths($basePath, array $paths)
    {
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        array_walk_recursive($paths, function (&$localPath) use ($basePath) {
            $localPath = $basePath . $localPath;
        });

        return $paths;
    }
}
