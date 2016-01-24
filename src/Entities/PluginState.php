<?php namespace Arcanedev\Composer\Entities;

use Composer\Composer;

/**
 * Class     PluginState
 *
 * @package  Arcanedev\Composer\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class PluginState
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /** @var \Composer\Composer */
    protected $composer;

    /** @var array */
    protected $includes = [];

    /** @var array */
    protected $requires = [];

    /** @var array */
    protected $duplicateLinks = [];

    /** @var bool */
    protected $devMode = false;

    /** @var bool */
    protected $recurse = true;

    /** @var bool */
    protected $replace = false;

    /**
     * Whether to merge the -dev sections.
     *
     * @var bool
     */
    protected $mergeDev = true;

    /**
     * Whether to merge the extra section.
     *
     * By default, the extra section is not merged and there will be many cases where
     * the merge of the extra section is performed too late to be of use to other plugins.
     * When enabled, merging uses one of two strategies - either 'first wins' or 'last wins'.
     * When enabled, 'first wins' is the default behaviour. If Replace mode is activated
     * then 'last wins' is used.
     *
     * @var bool
     */
    protected $mergeExtra = false;

    /** @var bool */
    protected $firstInstall = false;

    /** @var bool */
    protected $locked = false;

    /** @var bool */
    protected $dumpAutoloader = false;

    /** @var bool */
    protected $optimizeAutoloader = false;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Make PluginState instance.
     *
     * @param  \Composer\Composer  $composer
     */
    public function __construct(Composer $composer)
    {
        $this->composer = $composer;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get list of filenames and/or glob patterns to include.
     *
     * @return array
     */
    public function getIncludes()
    {
        return $this->includes;
    }

    /**
     * Set the first install flag.
     *
     * @param  bool  $flag
     *
     * @return self
     */
    public function setFirstInstall($flag)
    {
        $this->firstInstall = (bool)$flag;

        return $this;
    }

    /**
     * Is this the first time that the plugin has been installed ?
     *
     * @return bool
     */
    public function isFirstInstall()
    {
        return $this->firstInstall;
    }

    /**
     * Set the locked flag.
     *
     * @param  bool  $flag
     *
     * @return self
     */
    public function setLocked($flag)
    {
        $this->locked = (bool) $flag;

        return $this;
    }

    /**
     * Was a lockfile present when the plugin was installed ?
     *
     * @return bool
     */
    public function isLocked()
    {
        return $this->locked;
    }

    /**
     * Should an update be forced ?
     *
     * @return bool
     */
    public function forceUpdate()
    {
        return ! $this->isLocked();
    }

    /**
     * Set the devMode flag.
     *
     * @param  bool  $flag
     *
     * @return self
     */
    public function setDevMode($flag)
    {
        $this->devMode = (bool) $flag;

        return $this;
    }

    /**
     * Should devMode settings be processed ?
     *
     * @return bool
     */
    public function isDevMode()
    {
        return $this->mergeDev && $this->devMode;
    }

    /**
     * Set the dumpAutoloader flag.
     *
     * @param  bool  $flag
     *
     * @return self
     */
    public function setDumpAutoloader($flag)
    {
        $this->dumpAutoloader = (bool) $flag;

        return $this;
    }

    /**
     * Is the autoloader file supposed to be written out ?
     *
     * @return bool
     */
    public function shouldDumpAutoloader()
    {
        return $this->dumpAutoloader;
    }

    /**
     * Set the optimizeAutoloader flag.
     *
     * @param  bool  $flag
     *
     * @return self
     */
    public function setOptimizeAutoloader($flag)
    {
        $this->optimizeAutoloader = (bool) $flag;

        return $this;
    }

    /**
     * Should the autoloader be optimized ?
     *
     * @return bool
     */
    public function shouldOptimizeAutoloader()
    {
        return $this->optimizeAutoloader;
    }

    /**
     * Add duplicate packages.
     *
     * @param  string  $type
     * @param  array   $packages
     *
     * @return self
     */
    public function addDuplicateLinks($type, array $packages)
    {
        if ( ! isset($this->duplicateLinks[$type])) {
            $this->duplicateLinks[$type] = [];
        }

        $this->duplicateLinks[$type] = array_merge(
            $this->duplicateLinks[$type],
            $packages
        );

        return $this;
    }

    /**
     * Should includes be recursively processed ?
     *
     * @return bool
     */
    public function recurseIncludes()
    {
        return $this->recurse;
    }

    /**
     * Get list of filenames and/or glob patterns to require
     *
     * @return array
     */
    public function getRequires()
    {
        return $this->requires;
    }

    /**
     * Get duplicate packages.
     *
     * @param  string  $type
     *
     * @return array
     */
    public function getDuplicateLinks($type)
    {
        return isset($this->duplicateLinks[$type])
            ? $this->duplicateLinks[$type]
            : [];
    }

    /**
     * Should duplicate links be replaced in a 'last definition wins' order ?
     *
     * @return bool
     */
    public function replaceDuplicateLinks()
    {
        return $this->replace;
    }

    /**
     * Should the extra section be merged ?
     *
     * By default, the extra section is not merged and there will be many cases where
     * the merge of the extra section is performed too late to be of use to other plugins.
     * When enabled, merging uses one of two strategies - either 'first wins' or 'last wins'.
     * When enabled, 'first wins' is the default behaviour. If Replace mode is activated
     * then 'last wins' is used.
     *
     * @return bool
     */
    public function shouldMergeExtra()
    {
        return $this->mergeExtra;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Load plugin settings.
     */
    public function loadSettings()
    {
        $extra            = $this->composer->getPackage()->getExtra();
        $config           = $this->mergeConfig($extra);
        $this->includes   = is_array($config['include']) ? $config['include'] : [$config['include']];
        $this->requires   = is_array($config['require']) ? $config['require'] : [$config['require']];
        $this->recurse    = (bool) $config['recurse'];
        $this->replace    = (bool) $config['replace'];
        $this->mergeDev   = (bool) $config['merge-dev'];
        $this->mergeExtra = (bool) $config['merge-extra'];
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge config.
     *
     * @param  array  $extra
     *
     * @return array
     */
    private function mergeConfig(array $extra)
    {
        return array_merge(
            [
                'include'     => [],
                'require'     => [],
                'recurse'     => true,
                'replace'     => false,
                'merge-dev'   => true,
                'merge-extra' => false,
            ],
            isset($extra['merge-plugin']) ? $extra['merge-plugin'] : []
        );
    }
}
