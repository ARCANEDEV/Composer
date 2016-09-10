<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Composer\Package\BasePackage;
use Composer\Package\RootPackageInterface;
use Composer\Package\Version\VersionParser;

/**
 * Trait     ReferencesTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait ReferencesTrait
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
     * Update the root packages reference information.
     *
     * @param  \Composer\Package\RootPackageInterface  $root
     */
    protected function mergeReferences(RootPackageInterface $root)
    {
        // Merge source reference information for merged packages.
        // @see \Composer\Package\Loader\RootPackageLoader::load
        $references = [];
        $unwrapped  = static::unwrapIfNeeded($root, 'setReferences');

        foreach (['require', 'require-dev'] as $linkType) {
            $linkInfo = BasePackage::$supportedLinkTypes[$linkType];
            $method   = 'get'.ucfirst($linkInfo['method']);
            $links    = [];

            /** @var  \Composer\Package\Link  $link */
            foreach ($unwrapped->$method() as $link) {
                $links[$link->getTarget()] = $link->getConstraint()->getPrettyString();
            }

            $references = $this->extractReferences($links, $references);
        }

        $unwrapped->setReferences($references);
    }

    /**
     * Extract vcs revision from version constraint (dev-master#abc123).
     * @see \Composer\Package\Loader\RootPackageLoader::extractReferences()
     *
     * @param  array  $requires
     * @param  array  $references
     *
     * @return array
     */
    private function extractReferences(array $requires, array $references)
    {
        foreach ($requires as $reqName => $reqVersion) {
            $reqVersion = preg_replace('{^([^,\s@]+) as .+$}', '$1', $reqVersion);
            if (
                preg_match('{^[^,\s@]+?#([a-f0-9]+)$}', $reqVersion, $match) &&
                VersionParser::parseStability($reqVersion) === 'dev'
            ) {
                $references[strtolower($reqName)] = $match[1];
            }
        }

        return $references;
    }
}
