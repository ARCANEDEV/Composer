<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Entities\PluginState;
use Arcanedev\Composer\Entities\StabilityFlags;
use Composer\Package\RootPackageInterface;

/**
 * Trait     RequiresTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait RequiresTrait
{
    /* ------------------------------------------------------------------------------------------------
     |  Traits
     | ------------------------------------------------------------------------------------------------
     */
    use PackageTrait;

    /* ------------------------------------------------------------------------------------------------
     |  Main Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Merge require into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    protected function mergeRequires(RootPackageInterface $root, PluginState $state)
    {
        if ( ! empty($requires = $this->getPackage()->getRequires())) {
            $this->mergeStabilityFlags($root, $requires);

            $duplicateLinks = [];
            $requires       = $this->replaceSelfVersionDependencies(
                'require', $requires, $root
            );

            $root->setRequires($this->mergeLinks(
                $root->getRequires(), $requires, $state, $duplicateLinks
            ));

            $state->addDuplicateLinks('require', $duplicateLinks);
        }
    }

    /**
     * Merge require-dev into RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    protected function mergeDevRequires(RootPackageInterface $root, PluginState $state)
    {
        if ( ! empty($requires = $this->getPackage()->getDevRequires())) {
            $this->mergeStabilityFlags($root, $requires);

            $duplicateLinks = [];
            $requires       = $this->replaceSelfVersionDependencies(
                'require-dev', $requires, $root
            );

            $root->setDevRequires($this->mergeLinks(
                $root->getDevRequires(), $requires, $state, $duplicateLinks
            ));

            $state->addDuplicateLinks('require-dev', $duplicateLinks);
        }
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Extract and merge stability flags from the given collection of
     * requires and merge them into a RootPackage.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     * @param  \Composer\Package\Link[]                $requires
     */
    protected function mergeStabilityFlags(RootPackageInterface $root, array $requires)
    {
        $flags = StabilityFlags::extract(
            $root->getStabilityFlags(),
            $root->getMinimumStability(),
            $requires
        );

        self::unwrapIfNeeded($root, 'setStabilityFlags')
            ->setStabilityFlags($flags);
    }

    /**
     * Merge two collections of package links and collect duplicates for subsequent processing.
     *
     * @param  \Composer\Package\Link[]                  $origin          Primary collection
     * @param  array                                     $merge           Additional collection
     * @param  \Arcanedev\Composer\Entities\PluginState  $state           Plugin state
     * @param  array                                     $duplicateLinks  Duplicate storage
     *
     * @return \Composer\Package\Link[]                   Merged collection
     */
    private function mergeLinks(array $origin, array $merge, PluginState $state, array &$duplicateLinks)
    {
        if ($state->ignoreDuplicateLinks() && $state->replaceDuplicateLinks()) {
            $this->getLogger()->warning('Both replace and ignore-duplicates are true. These are mutually exclusive.');
            $this->getLogger()->warning('Duplicate packages will be ignored.');
        }

        foreach ($merge as $name => $link) {
            if (isset($origin[$name]) && $state->ignoreDuplicateLinks()) {
                $this->getLogger()->info("Ignoring duplicate <comment>{$name}</comment>");
            }
            elseif ( ! isset($origin[$name]) || $state->replaceDuplicateLinks()) {
                $this->getLogger()->info("Merging <comment>{$name}</comment>");
                $origin[$name] = $link;
            }
            else {
                // Defer to solver.
                $this->getLogger()->info("Deferring duplicate <comment>{$name}</comment>");
                $duplicateLinks[] = $link;
            }
        }

        return $origin;
    }

    /**
     * Update Links with a 'self.version' constraint with the root package's version.
     *
     * @param  string                                  $type
     * @param  array                                   $links
     * @param  \Composer\Package\RootPackageInterface  $root
     *
     * @return array
     */
    abstract protected function replaceSelfVersionDependencies(
        $type, array $links, RootPackageInterface $root
    );
}
