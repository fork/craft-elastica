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
     * Returns the section handles.
     *
     * @return string[]
     */
    public function getSectionHandles(): array
    {
        return $this->sectionHandles;
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
}
