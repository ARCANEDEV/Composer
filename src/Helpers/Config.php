<?php namespace Arcanedev\Composer\Helpers;

use Composer\Package\RootPackage;

/**
 * Class Config
 * @package Arcanedev\Composer\Helpers
 */
class Config
{
    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    public static function read(RootPackage $package, $key = 'merge-plugin')
    {
        $config = [
            'include' => [],
        ];

        $extra = $package->getExtra();

        if (isset($extra[$key])) {
            $config = array_merge($config, $extra[$key]);

            if ( ! is_array($config['include'])) {
                $config['include'] = [
                    $config['include']
                ];
            }
        }

        return $config;
    }
}
