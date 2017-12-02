<?php

namespace PacificaSearchBundle\Model;

/**
 * Class FilterItemCollection
 *
 * Represents a collection of filter items of a single type - this is basically a container for an array of
 * ElasticSearchType instances of the same type, which exists to abstract some of the logic of rendering those
 * collections out of the template.
 */
class ElasticSearchTypeCollection implements \Iterator, \Countable
{
    /**
     * @var ElasticSearchType[]
     */
    private $instances = [];

    /**
     * @param ElasticSearchType[] $instances
     */
    public function __construct(array $instances = [])
    {
        foreach ($instances as $instance) {
            $this->add($instance);
        }
    }

    /**
     * @param ElasticSearchType $item
     * @return ElasticSearchTypeCollection
     */
    public function add(ElasticSearchType $item)
    {
        // Enforce that all items in a collection have to be of the same class
        if (!empty($this->instances) && !($item instanceof $this->instances[0])) {
            throw new \InvalidArgumentException('All items in a FilterItemCollection must be of the same class');
        }

        $this->instances[] = $item;

        return $this;
    }

    /**
     * @return ElasticSearchType[]
     */
    public function getInstances()
    {
        return $this->instances;
    }

    /**
     * Reorders the wrapped instances to be alphabetical by display name
     * @return ElasticSearchTypeCollection
     */
    public function sortByDisplayName()
    {
        usort($this->instances, function ($a, $b) {
            /** @var $a ElasticSearchType */
            /** @var $b ElasticSearchType */
            return strcmp($a->getDisplayName(), $b->getDisplayName());
        });

        return $this;
    }

    /**
     * Returns the string that represents the contained Type in the GUI
     * @return string|NULL NULL if no items have been added
     */
    public function getTypeDisplayName()
    {
        if (empty($this->instances)) {
            return null;
        }

        return $this->instances[0]::getTypeDisplayName();
    }

    /**
     * Returns the string that represents the contained Type in the REST API
     * @return string
     */
    public function getMachineName()
    {
        return $this->instances[0]::getMachineName();
    }

    public function current()
    {
        return current($this->instances);
    }

    public function next()
    {
        return next($this->instances);
    }

    public function key()
    {
        return key($this->instances);
    }

    public function valid()
    {
        return isset($this->instances);
    }

    public function rewind()
    {
        return reset($this->instances);
    }

    public function count()
    {
        return count($this->instances);
    }
}
