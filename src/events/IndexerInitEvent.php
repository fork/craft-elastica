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
     * list of volume handles whose assets need to be (re-)indexed by the plugin
     *
     * @var string[]
     */
    protected $volumeHandles = [];

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

    /**
     * Returns the volume handles.
     *
     * @return string[]
     */
    public function getVolumeHandles(): array
    {
        return $this->volumeHandles;
    }

    /**
     * Adds a new set of section handles.
     *
     * @param string[] $handles
     */
    public function addSectionHandles(array $handles): void
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
    public function addSectionHandle(string $handle): void
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
    public function addCategoryGroupHandles(array $handles): void
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
    public function addCategoryGroupHandle(string $handle): void
    {
        // ensure the given handle is not empty and only added once
        if (!empty($handle) && !in_array($handle, $this->categoryGroupHandles)) {
            $this->categoryGroupHandles[] = $handle;
        }
    }

    /**
     * Adds a new set of volume handles.
     *
     * @param string[] $handles
     */
    public function addVolumeHandles(array $handles): void
    {
        foreach ($handles as $handle) {
            // ensure the handle is a non-empty string
            if (!empty($handle) && is_string($handle)) {
                $this->addVolumeHandle($handle);
            }
        }
    }

    /**
     * Adds a new volume handle.
     *
     * @param string $handle
     */
    public function addVolumeHandle(string $handle): void
    {
        // ensure the given handle is not empty and only added once
        if (!empty($handle) && !in_array($handle, $this->volumeHandles)) {
            $this->volumeHandles[] = $handle;
        }
    }
}
