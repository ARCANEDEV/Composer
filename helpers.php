<?php

if ( ! function_exists('paths_prepend')) {
    /**
     * Prepend a path to a collection of paths.
     *
     * @param  string $basePath
     * @param  array  $paths
     *
     * @return array
     */
    function paths_prepend($basePath, array $paths) {
        $basePath = substr($basePath, 0, strrpos($basePath, '/') + 1);

        array_walk_recursive($paths, function (&$localPath) use ($basePath) {
            $localPath = $basePath . $localPath;
        });

        return $paths;
    }
}
