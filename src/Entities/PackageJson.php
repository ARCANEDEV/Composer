<?php namespace Arcanedev\Composer\Entities;

use Arcanedev\Composer\Exceptions\InvalidPackageException;
use Composer\Json\JsonFile;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;

/**
 * Class PackageJson
 * @package Arcanedev\Composer\Entities
 */
class PackageJson
{
    /* ------------------------------------------------------------------------------------------------
     |  Main Function
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Read the contents of a composer.json style file into an array.
     *
     * The package contents are fixed up to be usable to create a Package object
     * by providing dummy "name" and "version" values if they have not been provided in the file.
     * This is consistent with the default root package loading behavior of Composer.
     *
     * @param  string $path
     *
     * @return array
     */
    public static function read($path)
    {
        $file = new JsonFile($path);
        $json = $file->read();

        if ( ! isset($json['name'])) {
            $json['name'] = 'merge-plugin/' . strtr($path, DIRECTORY_SEPARATOR, '-');
        }

        if ( ! isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return $json;
    }

    /**
     * Convert json to Package
     *
     * @param  array $json
     *
     * @return CompletePackage
     *
     * @throws InvalidPackageException
     */
    public static function convert(array $json)
    {
        $loader  = new ArrayLoader;
        $package = $loader->load($json);


        if ($package instanceof CompletePackage) {
            return $package;
        }

        // @codeCoverageIgnoreStart
        throw new InvalidPackageException(
            'Expected instance of CompletePackage, got ' . get_class($package)
        );
        // @codeCoverageIgnoreEnd
    }
}
