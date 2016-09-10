<?php namespace Arcanedev\Composer\Entities\PackageTraits;

use Arcanedev\Composer\Entities\PluginState;
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
     * @param  \Arcanedev\Composer\Entities\PluginState  $state
     */
    private function addRepositories(RootPackageInterface $root, PluginState $state)
    {
        $json = $this->getJson();

        if (isset($json['repositories'])) {
            $prepend      = $state->shouldPrependRepositories();
            $repoManager  = $this->getComposer()->getRepositoryManager();
            $repositories = [];

            foreach ($json['repositories'] as $repoJson) {
                $this->addRepository($repoManager, $repositories, $repoJson, $prepend);
            }

            /** @var \Composer\Package\RootPackageInterface $unwrapped */
            $unwrapped   = self::unwrapIfNeeded($root, 'setRepositories');
            $mergedRepos = $prepend
                ? array_merge($repositories, $root->getRepositories())
                : array_merge($root->getRepositories(), $repositories);

            $unwrapped->setRepositories($mergedRepos);
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
     * @param  bool                                    $prepend
     */
    private function addRepository(
        RepositoryManager $repoManager, array &$repositories, $repoJson, $prepend
    ) {
        if (isset($repoJson['type'])) {
            $this->getLogger()->info(
                $prepend ? "Prepending {$repoJson['type']} repository" : "Adding {$repoJson['type']} repository"
            );

            $repository = $repoManager->createRepository($repoJson['type'], $repoJson);

            $prepend
                ? $repoManager->prependRepository($repository)
                : $repoManager->addRepository($repository);

            $repositories[] = $repository;
        }
    }
}
