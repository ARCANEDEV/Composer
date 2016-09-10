<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Composer\Package\BasePackage;
use Composer\Package\RootPackageInterface;

/**
 * Trait     LinksTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait LinksTrait
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
     * Merge package links of the given type into a RootPackageInterface
     *
     * @param  string                                  $type  'conflict', 'replace' or 'provide'
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    protected function mergePackageLinks($type, RootPackageInterface $root)
    {
        $linkType = BasePackage::$supportedLinkTypes[$type];
        $getter   = 'get' . ucfirst($linkType['method']);
        $setter   = 'set' . ucfirst($linkType['method']);
        $links    = $this->getPackage()->{$getter}();

        if ( ! empty($links)) {
            $unwrapped = static::unwrapIfNeeded($root, $setter);

            // @codeCoverageIgnoreStart
            if ($root !== $unwrapped) {
                $this->getLogger()->warning(
                    'This Composer version does not support ' .
                    "'{$type}' merging for aliased packages."
                );
            }
            // @codeCoverageIgnoreEnd

            $unwrapped->{$setter}(array_merge(
                $root->{$getter}(),
                $this->replaceSelfVersionDependencies($type, $links, $root)
            ));
        }
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
