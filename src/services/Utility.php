<?php

namespace fork\elastica\services;

use Craft;
use craft\base\Component;
use craft\errors\MissingComponentException;
use craft\helpers\Json;
use fork\elastica\Elastica;
use fork\elastica\queue\ReindexJob;

/**
 * The Utility service triggers utility-tasks such as re-indexing of Elasticsearch entries.
 *
 * @package fork\elastica\services
 *
 * @see Indexer
 */
class Utility extends Component
{
    /**
     * Handles a utility form submit (e.g. for re-indexing entries in Elasticsearch) and returns the triggered task's name
     * or an empty string if nothing could be triggered at all.
     *
     * @return string
     *
     * @throws MissingComponentException
     */
    public function handleUtilityFormSubmit(): string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsPost() && $request->getBodyParam('task') == 're-index') {
            return $this->triggerReindex($request->getBodyParam('deleteAll'));
        }

        if ($request->getIsPost() && $request->getBodyParam('task') == 'index-template' && Craft::$app->user->checkPermission('elasticaIndexTemplates')) {
            return $this->saveIndexTemplate();
        }

        if ($request->getIsPost() && $request->getBodyParam('task') == 'search-templates' && Craft::$app->user->checkPermission('elasticaSearchTemplates')) {
            return $this->saveSearchTemplates();
        }

        return '';
    }

    /**
     * Triggers the re-indexing of entries in Elasticsearch and returns the triggered task's name or an empty string if this didn't work out.
     *
     * @param bool $deleteAll delete all including settings and mappings
     * @return string
     *
     * @throws MissingComponentException
     */
    protected function triggerReindex($deleteAll = false): string
    {
        $jobId = Craft::$app->queue->push(new ReindexJob(['deleteAll' => $deleteAll]));

        if (!empty($jobId)) {
            $this->setNotice('Re-indexing triggered.');

            return 're-index-triggered';
        }

        $this->setError('Error on triggering re-indexing.');

        return '';
    }

    /**
     * Saves index template to plugin settings and elasticsearch
     *
     * @return string
     *
     * @throws MissingComponentException
     */
    protected function saveIndexTemplate(): string
    {
        $elastica = Elastica::$plugin;
        $settings = $elastica->getSettings();

        try {
            $templateArray = Json::decode($settings->indexTemplate);
            $elastica->indexer->saveIndexTemplate($settings->indexTemplateName, $templateArray);
            $this->setNotice('Index Template saved!');
        } catch (\Exception $exception) {
            $this->setError($exception->getMessage());
        }

        return '';
    }

    /**
     * Saves index template to plugin settings and elasticsearch
     *
     * @return string
     *
     * @throws MissingComponentException
     */
    protected function saveSearchTemplates(): string
    {
        $elastica = Elastica::$plugin;
        $settings = $elastica->getSettings();

        try {
            foreach ($settings->searchTemplates as $row) {
                $templateHandle = $row[0];
                $templateSource = Json::decode($row[1]);
                $templateParams = !empty($row[2]) ? Json::decode($row[2]) : null;
                $elastica->indexer->saveSearchTemplate($templateHandle, $templateSource, $templateParams);
            }

            $this->setNotice('Search Templates saved!');
        } catch (\Exception $exception) {
            $this->setError($exception->getMessage());
        }

        return '';
    }

    /**
     * Sets the given message to be displayed for user.
     *
     * @param string $message
     *
     * @throws MissingComponentException
     */
    protected function setNotice(string $message)
    {
        Craft::$app->getSession()->setNotice($message);
    }

    /**
     * Sets the given error message to be displayed for user.
     *
     * @param string $message
     *
     * @throws MissingComponentException
     */
    protected function setError(string $message)
    {
        Craft::$app->getSession()->setError($message);
    }
}
