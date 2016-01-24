<?php namespace Arcanedev\Composer\Entities;

use Composer\Package\BasePackage;
use Composer\Package\Version\VersionParser;

/**
 * Class     StabilityFlags
 *
 * @package  Arcanedev\Composer\Entities
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class StabilityFlags
{
    /* ------------------------------------------------------------------------------------------------
     |  Properties
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Current package name => stability mappings.
     *
     * @var array
     */
    protected $flags;

    /**
     * Current default minimum stability.
     *
     * @var int
     */
    protected $minimumStability;

    /* ------------------------------------------------------------------------------------------------
     |  Constructor
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Make StabilityFlags instance.
     *
     * @param  array  $flags
     * @param  int    $minimumStability
     */
    public function __construct(
        array $flags = [],
        $minimumStability = BasePackage::STABILITY_STABLE
    ) {
        $this->flags            = $flags;
        $this->minimumStability = $this->getStabilityInteger($minimumStability);
    }

    /* ------------------------------------------------------------------------------------------------
     |  Getters & Setters
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Get the stability value for a given string.
     *
     * @param  string  $name
     *
     * @return int
     */
    private function getStabilityInteger($name)
    {
        $name = VersionParser::normalizeStability($name);

        return isset(BasePackage::$stabilities[$name]) ?
            BasePackage::$stabilities[$name] :
            BasePackage::STABILITY_STABLE;
    }

    /**
     * Get regex pattern.
     *
     * @return string
     */
    private function getPattern()
    {
        $stabilities = BasePackage::$stabilities;

        return '/^[^@]*?@(' . implode('|', array_keys($stabilities)) . ')$/i';
    }

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Make StabilityFlags and extract stability flags.
     *
     * @param  array  $flags
     * @param  int    $minimumStability
     * @param  array  $requires
     *
     * @return array
     */
    public static function extract(array $flags, $minimumStability, array $requires)
    {
        return (new self($flags, $minimumStability))->extractAll($requires);
    }

    /**
     * Extract and merge stability flags from the given collection of
     * requires with another collection of stability flags.
     *
     * @param  array  $requires  New package name => link mappings
     *
     * @return array             Unified package name => stability mappings
     */
    public function extractAll(array $requires)
    {
        $flags = [];

        foreach ($requires as $name => $link) {
            /** @var \Composer\Package\Link $link */
            $version      = $link->getPrettyConstraint();
            $stability    = $this->extractStability($version);
            $name         = strtolower($name);
            $flags[$name] = max($stability, $this->getCurrentStability($name));
        }

        return array_filter($flags, function($v) {
            return $v !== null;
        });
    }

    /**
     * Extract stability.
     *
     * @param  string  $version
     *
     * @return mixed
     */
    private function extractStability($version)
    {
        $stability = $this->getExplicitStability($version);

        if ($stability === null) {
            $stability = $this->getParsedStability($version);
        }

        return $stability;
    }

    /**
     * Extract the most unstable explicit stability (eg '@dev') from a version specification.
     *
     * @param  string  $version
     *
     * @return int|null
     */
    protected function getExplicitStability($version)
    {
        $found   = null;
        $pattern = $this->getPattern();

        foreach ($this->splitConstraints($version) as $constraint) {
            if ( ! preg_match($pattern, $constraint, $match)) {
                continue;
            }

            $stability = $this->getStabilityInteger($match[1]);
            $found     = max($stability, $found);
        }

        return $found;
    }

    /**
     * Get the stability of a version
     *
     * @param  string  $version
     *
     * @return int|null
     */
    private function getParsedStability($version)
    {
        // Drop aliasing if used
        $version   = preg_replace('/^([^,\s@]+) as .+$/', '$1', $version);
        $stability = $this->getStabilityInteger(VersionParser::parseStability($version));

        if ($stability === BasePackage::STABILITY_STABLE || $this->minimumStability > $stability) {
            // Ignore if 'stable' or more stable than the global minimum
            $stability = null;
        }

        return $stability;
    }

    /**
     * Get the current stability of a given package.
     *
     * @param  string  $name
     *
     * @return int|null
     */
    protected function getCurrentStability($name)
    {
        return isset($this->flags[$name]) ? $this->flags[$name] : null;
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Split a version specification into a list of version constraints.
     *
     * @param  string  $version
     *
     * @return array
     */
    protected function splitConstraints($version)
    {
        $found   = [];
        $pattern = '/(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)/';

        foreach (preg_split('/\s*\|\|?\s*/', trim($version)) as $constraints) {
            $andConstraints = preg_split($pattern, $constraints);

            foreach ($andConstraints as $constraint) {
                $found[] = $constraint;
            }
        }

        return $found;
    }
}
