<?php namespace Arcanedev\Composer\Entities;
use Composer\Json\JsonFile;

/**
 * Class Package
 * @package Arcanedev\Composer\Entities
 */
class Package extends JsonFile
{
    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    public function __construct($path)
    {
        parent::__construct($path);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    public function read()
    {
        $json = parent::read();


        if ( ! isset($json['name'])) {
            $json['name'] = 'merge-plugin/' . strtr($this->getPath(), DIRECTORY_SEPARATOR, '-');
        }

        if ( ! isset($json['version'])) {
            $json['version'] = '1.0.0';
        }

        return $json;
    }
}
