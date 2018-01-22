<?php

namespace PacificaSearchBundle\Repository;

use PacificaSearchBundle\Filter;
use PacificaSearchBundle\Model\ElasticSearchTypeCollection;
use PacificaSearchBundle\Service\ElasticSearchQueryBuilder;
use PacificaSearchBundle\Service\RepositoryManagerInterface;
use PacificaSearchBundle\Service\SearchServiceInterface;

/**
 * Class Repository
 *
 * Base class for Pacifica Search repositories
 */
abstract class Repository
{
    const DEFAULT_PAGE_SIZE = 10;

    /** @var SearchServiceInterface */
    protected $searchService;

    /** @var RepositoryManagerInterface */
    protected $repositoryManager;

    final public function __construct(SearchServiceInterface $searchService, RepositoryManagerInterface $repositoryManager)
    {
        $this->searchService = $searchService;
        $this->repositoryManager = $repositoryManager;
    }

    /**
     * Gets the name of the model class of the type that this repository is responsible for. Will attempt to find the
     * class by the convention that repository classes are named <ModelClass>Repository, override this method if that's
     * not the case.
     */
    public function getModelClass()
    {
        // Remove the "Repository" suffix from the repo's class name
        $modelClassName = preg_replace('/Repository$/', '', static::class);

        // Change the namespace from "Repository" to "Model"
        $modelClassName = str_replace('\\Repository\\', '\\Model\\', $modelClassName);

        if (!class_exists($modelClassName)) {
            throw new \Exception(
                "No class $modelClassName could be found. If your Model and/or Repository don't follow the " .
                "expected naming convention, your repository must implement its own version of getModelClass()"
            );
        }

        return $modelClassName;
    }

    /**
     * @param int|int[] $ids
     * @return ElasticSearchTypeCollection
     */
    public function getById($ids)
    {
        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $response = $this->searchService->getResults($this->getQueryBuilder()->whereIn('id', $ids));
        return $this->resultsToTypeCollection($response);
    }

    /**
     * Retrieves a page of model objects that fit the passed filter.
     *
     * @param Filter $filter
     * @param int $pageNumber 1-based page number
     * @return ElasticSearchTypeCollection
     */
    public function getFilteredPage(Filter $filter, $pageNumber)
    {
        $qb = $this->getQueryBuilder();
        $qb->paginate($pageNumber, self::DEFAULT_PAGE_SIZE);

        $filteredIds = $this->getIdsThatMayBeAddedToFilter($filter);
        if (!empty($filteredIds)) {
            $qb->byId($filteredIds);
        }

        $response = $this->searchService->getResults($qb);
        return $this->resultsToTypeCollection($response);
    }

    /**
     * Retrieve the IDs of all instances that could be added to the passed filter without resulting in an empty result.
     * It is important to note that each repository must ignore filters of its own type. That is to say, if an
     * Institution is selected, that should not prohibit the addition of further Institutions to the Filter - only
     * adding Filter items of other types should impact the options of a given type.
     *
     * @param Filter $filter
     * @return array|NULL NULL indicates that no filtering was performed because the filter was empty (possibly after
     *   removing all of the Repository's own model class's items)
     */
    protected function getIdsThatMayBeAddedToFilter(Filter $filter)
    {
        // Clone the filter before making any changes so that the caller's filter doesn't get changed
        $filter = clone $filter;

        // Remove filters of own type if applicable - you can't restrict Users by picking a User
        if ($this->isFilterRepository()) {
            $filter->setIdsByType($this->getModelClass(), []);
        }

        // We don't do any filtering if the filter contains no values
        if ($filter->isEmpty()) {
            return null;
        }

        $transactionIds = $this->repositoryManager->getTransactionRepository()->getIdsByFilter($filter);
        $ownIds = $this->getIdsByTransactionIds($transactionIds);

        return $ownIds;
    }

    /**
     * Returns true for repositories that store objects that can be a part of a filter. Override for repositories that
     * cannot be included in the filter to avoid triggering behavior that is specific to filter repositories.
     *
     * @return bool
     */
    protected function isFilterRepository()
    {
        return true;
    }

    /**
     * Gets IDs of this type that are associated with a set of transaction IDs
     *
     * TODO: Figure out how to make the query here unique by the required field so that we don't have to process a
     * large number of redundant results.
     *
     * @param array $transactionIds
     * @return int[]
     */
    protected function getIdsByTransactionIds(array $transactionIds)
    {
        $qb = $this->searchService->getQueryBuilder(ElasticSearchQueryBuilder::TYPE_TRANSACTION)->byId($transactionIds);
        $results = $this->searchService->getResults($qb);
        $ids = $this->getOwnIdsFromTransactionResults($results);
        $ids = array_values(array_unique($ids)); // array_unique is only necessary because the query builder doesn't support unique queries yet. array_values() is to give the resulting array nice indices
        return $ids;
    }

    /**
     * @param array $transactionResults An array returned by SearchService when given a query for TYPE_TRANSACTION
     * @return int[] Array containing all IDs of this Repository's type that correspond to the returned transaction
     * records
     */
    abstract protected function getOwnIdsFromTransactionResults(array $transactionResults);

    protected function resultsToTypeCollection(array $results)
    {
        $instances = new ElasticSearchTypeCollection();
        foreach ($results as $curHit) {
            $modelClass = $this->getModelClass();
            $instance = new $modelClass($curHit['_id'], static::getNameFromSearchResult($curHit));
            $instances->add($instance);
        }

        return $instances;
    }

    /**
     * Gets a query builder that, when submitted to the SearchService, will return all records of the type this
     * repository is responsible for. Further filters can then be added by calling additional methods on the query
     * builder.
     *
     * @return \PacificaSearchBundle\Service\ElasticSearchQueryBuilder
     */
    protected function getQueryBuilder()
    {
        return $this->searchService->getQueryBuilder($this->getType());
    }

    /**
     * Gets the type (one of the ElasticSearchQueryBuilder::TYPE_* constants) that this repository is responsible for
     * @return string
     */
    abstract protected function getType();

    /**
     * Given a single result (an entry from the "hits" field of an Elastic Search results object), returns a string
     * representing the display name of the result. The most common case is implemented here - for other cases, override
     * this function.
     *
     * @param array $result
     * @return string
     */
    protected static function getNameFromSearchResult(array $result)
    {
        return $result['_source']['name'];
    }
}
