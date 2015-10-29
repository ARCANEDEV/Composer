<?php namespace Arcanedev\Composer\Entities;
use Composer\Package\BasePackage;
use Composer\Package\Link;
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
     * Current package name => stability mappings
     *
     * @var array
     */
    protected $flags;

    /**
     * Current default minimum stability
     *
     * @var int
     */
    protected $minimumStability;

    /**
     * Regex to extract an explicit stability flag (eg '@dev')
     *
     * @var string
     */
    protected $pattern;

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
        $this->flags               = $flags;
        $this->minimumStability    = $this->getStability($minimumStability);
        $this->pattern             =
            '/^[^@]*?@(' . implode('|', array_keys(BasePackage::$stabilities)) . ')$/i';
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
    protected function getStability($name)
    {
        $name = VersionParser::normalizeStability($name);

        return isset(BasePackage::$stabilities[$name]) ?
            BasePackage::$stabilities[$name] :
            BasePackage::STABILITY_STABLE;
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
     * @param array $requires New package name => link mappings
     * @return array Unified package name => stability mappings
     */
    public function extractAll(array $requires)
    {
        $flags = [];

        foreach ($requires as $name => $link) {
            /** @var Link $link */
            $version   = $link->getPrettyConstraint();
            $stability = $this->getExplicitStability($version);

            if ($stability === null) {
                // Drop aliasing if used
                $version = preg_replace('/^([^,\s@]) as .$/', '$1', $version);
                $stability = $this->getStability(
                    VersionParser::parseStability($version)
                );

                if (
                    $stability === BasePackage::STABILITY_STABLE ||
                    $this->minimumStability > $stability
                ) {
                    // Ignore if 'stable' or more stable than the global minimum
                    $stability = null;
                }
            }

            $name = strtolower($name);

            if (isset($this->flags[$name]) && $this->flags[$name] > $stability) {
                // Keep current if more unstable
                $stability = $this->flags[$name];
            }

            $flags[$name] = $stability;
        }

        return array_filter($flags, function($v) {
            return $v !== null;
        });
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
        $found = null;

        foreach ($this->splitConstraints($version) as $constraint) {
            if (preg_match($this->pattern, $constraint, $match)) {
                $stability = $this->getStability($match[1]);

                if ($found === null || $stability > $found) {
                    $found = $stability;
                }
            }
        }

        return $found;
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
        $found = [];

        foreach (preg_split('/\s*\|\|?\s*/', trim($version)) as $constraints) {
            $andConstraints = preg_split(
                '/(?<!^|as|[=>< ,]) *(?<!-)[, ](?!-) *(?!,|as|$)/',
                $constraints
            );

            foreach ($andConstraints as $constraint) {
                $found[] = $constraint;
            }
        }

        return $found;
    }
}