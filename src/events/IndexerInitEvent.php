<?php

namespace fork\elastica\events;

use yii\base\Event;

/**
 * The IndexerInitEvent class represents an event being fired at the Elastica plugin's Indexer service initialisation.
 *
 * @package fork\elastica\events
 */
class IndexerInitEvent extends Event
{

    /**
     * list of section handles whose entries need to be (re-)indexed by the plugin
     *
     * @var string[]
     */
    protected $sectionHandles = [];

    /**
     * list of group handles whose categories need to be (re-)indexed by the plugin
     *
     * @var string[]
     */
    protected $categoryGroupHandles = [];

    /**
     * Returns the section handles.
     *
     * @return string[]
     */
    public function getSectionHandles(): array
    {
        return $this->sectionHandles;
    }

    /**
     * Returns the category group handles.
     *
     * @return string[]
     */
    public function getCategoryGroupHandles(): array
    {
        return $this->categoryGroupHandles;
    }

    ///**
    // * Sets the section handles.
    // *
    // * @param string[] $sectionHandles
    // */
    //public function setSectionHandles(array $sectionHandles)
    //{
    //    // ensure we have no duplicates and no non-empty strings
    //    $this->sectionHandles = array_filter(array_unique($this->sectionHandles), function ($handle) {
    //        return !empty($handle) && is_string($handle);
    //    });
    //}

    /**
     * Adds a new set of section handles.
     *
     * @param string[] $handles
     */
    public function addSectionHandles(array $handles)
    {
        foreach ($handles as $handle) {
            // ensure the handle is a non-empty string
            if (!empty($handle) && is_string($handle)) {
                $this->addSectionHandle($handle);
            }
        }
    }

    /**
     * Adds a new entry type handle.
     *
     * @param string $handle
     */
    public function addSectionHandle(string $handle)
    {
        // ensure the given handle is not empty and only added once
        if (!empty($handle) && !in_array($handle, $this->sectionHandles)) {
            $this->sectionHandles[] = $handle;
        }
    }

    /**
     * Adds a new set of category group handles.
     *
     * @param string[] $handles
     */
    public function addCategoryGroupHandles(array $handles)
    {
        foreach ($handles as $handle) {
            // ensure the handle is a non-empty string
            if (!empty($handle) && is_string($handle)) {
                $this->addCategoryGroupHandle($handle);
            }
        }
    }

    /**
     * Adds a new category group handle.
     *
     * @param string $handle
     */
    public function addCategoryGroupHandle(string $handle)
    {
        // ensure the given handle is not empty and only added once
        if (!empty($handle) && !in_array($handle, $this->categoryGroupHandles)) {
            $this->categoryGroupHandles[] = $handle;
        }
    }
}
