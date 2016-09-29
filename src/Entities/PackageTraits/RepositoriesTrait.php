<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Composer\Package\RootPackageInterface;
use Composer\Repository\RepositoryManager;

/**
 * Trait     RepositoriesTrait
 *
 * @package  Arcanedev\Composer\Entities\PackageTraits
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
trait RepositoriesTrait
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
     * Add a collection of repositories described by the given configuration
     * to the given package and the global repository manager.
     *
     * @param  \Composer\Package\RootPackageInterface    $root
     */
    private function prependRepositories(RootPackageInterface $root)
    {
        $json = $this->getJson();

        if (isset($json['repositories'])) {
            $repoManager  = $this->getComposer()->getRepositoryManager();
            $repositories = [];

            foreach ($json['repositories'] as $repoJson) {
                $this->addRepository($repoManager, $repositories, $repoJson);
            }

            /** @var \Composer\Package\RootPackageInterface $unwrapped */
            self::unwrapIfNeeded($root, 'setRepositories')
                ->setRepositories(array_merge($repositories, $root->getRepositories()));
        }
    }

    /* ------------------------------------------------------------------------------------------------
     |  Other Functions
     | ------------------------------------------------------------------------------------------------
     */
    /**
     * Add a repository to collection of repositories.
     *
     * @param  \Composer\Repository\RepositoryManager  $repoManager
     * @param  array                                   $repositories
     * @param  array                                   $repoJson
     */
    private function addRepository(
        RepositoryManager $repoManager, array &$repositories, $repoJson
    ) {
        if (isset($repoJson['type'])) {
            $this->getLogger()->info("Prepending {$repoJson['type']} repository");

            $repository = $repoManager->createRepository($repoJson['type'], $repoJson);

            $repoManager->prependRepository($repository);
            $repositories[] = $repository;
        }
    }
}
