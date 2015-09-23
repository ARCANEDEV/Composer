<?php namespace Arcanedev\Composer\Utilities;

use Composer\Package\BasePackage;
use Composer\Package\Link;
use Composer\Package\Version\VersionParser;

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

    /**
     * Load stability flags.
     *
     * @param  array  $flags
     * @param  array  $requires
     *
     * @return array
     */
    public static function loadFlags(array $flags, array $requires)
    {
        foreach ($requires as $name => $link) {
            /** @var Link $link */
            $name         = strtolower($name);
            $version      = $link->getPrettyConstraint();
            $stability    = VersionParser::parseStability($version);
            $flags[$name] = BasePackage::$stabilities[$stability];
        }

        return $flags;
    }
}
