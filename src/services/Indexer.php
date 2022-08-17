<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * A plugin to connect to Elasticsearch and persist elements via hooks
 *
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

namespace fork\elastica\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\ModelEvent;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\queue\QueueInterface;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Exception;
use fork\elastica\Elastica;
use fork\elastica\events\IndexerInitEvent;
use fork\elastica\events\IndexEvent;
use fork\elastica\queue\ReindexJob;
use yii\base\Event;

/**
 * Indexer Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Fork Unstable Media GmbH
 * @package   Elastica
 * @since     1.0.0
 */
class Indexer extends Component
{

    // Public Methods
    // =========================================================================

    /** @var Client $client */
    private $client;
    /** @var string */
    private $indexPrefix = 'craft';

    const EVENT_INDEXER_INIT = 'indexerInit';
    const EVENT_BEFORE_INDEX_DATA = 'beforeIndexData';

    /**
     * list of section handles to consider for (re-)indexing entries
     *
     * @var string[]
     */
    protected $sectionHandles;

    /**
     * list of group handles to consider for (re-)indexing categories
     *
     * @var string[]
     */
    protected $categoryGroupHandles;

    /**
     * @inheritdoc
     */
    public function init()
    {
        $env = Craft::$app->config->env;
        $pluginSettings = Elastica::$plugin->getSettings();
        $hosts = $pluginSettings->hosts ?: [];

        $envHosts = [];
        foreach ($hosts as $host) {
            if ($env == $host[0]) {
                $envHosts[] = $host[1];
                $this->indexPrefix = $host[2];
            }
        }

        $clientBuilder = ClientBuilder::create();
        $clientBuilder->setHosts($envHosts);
        $this->client = $clientBuilder->build();

        if ($this->hasEventHandlers(self::EVENT_INDEXER_INIT)) {
            $event = new IndexerInitEvent();
            $this->trigger(self::EVENT_INDEXER_INIT, $event);
            $this->sectionHandles = $event->getSectionHandles();
            $this->categoryGroupHandles = $event->getCategoryGroupHandles();
        }
    }

    /**
     * @param Element $element
     * @param $content
     *
     * @return array|bool
     *
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\InvalidConfigException
     */
    public function index(Element $element, $content = null)
    {
        $site = $element->getSite();

        // Fire a 'beforeIndexData' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_DATA)) {
            $event = new IndexEvent([
                'entry' => $element, // TODO: remove in future release and use sender alone
                'sender' => $element,
                'indexData' => $content,
            ]);
            $this->trigger(self::EVENT_BEFORE_INDEX_DATA, $event);
            $content = $event->indexData;
        }

        $params = [
            'index' => $this->getIndexName($element, $site),
            'id' => $element->id,
            'body' => $content,
        ];

        try {
            return empty($content) ? false : $this->client->index($params);
        } catch (Exception $exception) {
            Craft::error($exception->getMessage(), 'elasticsearch');
            if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getResponse()->isSent) {
                // show error message when saving in control panel
                Craft::$app->getSession()->setError($exception->getMessage());
            } else {
                // to get error in index background job/queue
                throw $exception;
            }

            return false;
        }
    }

    /**
     * @param Element $element
     * @param bool $force
     *
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\InvalidConfigException
     */
    public function delete(Element $element, $force = false)
    {
        $isMultiSite = Craft::$app->getIsMultiSite();

        foreach (Craft::$app->sites->getAllSites() as $site) {
            $siteElement = $element;

            if ($siteElement && $isMultiSite && !$force) {
                $siteElement = Craft::$app->elements->getElementById($siteElement->id, get_class($siteElement), $site->id);
            }

            if (!$siteElement) {
                continue;
            }

            // don't delete language version if still enabled for site
            if ($isMultiSite && $this->isElementLive($siteElement) && !$force) {
                continue;
            }

            $params = [
                'index' => $this->getIndexName($siteElement, $site),
                'id' => $siteElement->id,
            ];

            try {
                $this->client->delete($params);
            } catch (Exception $e) {
                // skip not found
                if (!empty($e->getCode()) && $e->getCode() == 404) {
                    continue;
                } else {
                    Craft::error($e->getMessage(), 'elasticsearch');
                    if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getResponse()->isSent) {
                        Craft::$app->getSession()->setError($e->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Reindexes the elements.
     *
     * @param \fork\elastica\queue\ReindexJob|null $reindexJob
     * @param \craft\queue\QueueInterface|null $queue
     * @param bool $deleteAll delete all contents and index settings and mappings
     */
    public function reIndex(ReindexJob $reindexJob = null, QueueInterface $queue = null, $deleteAll = false)
    {
        if ($deleteAll) {
            $this->client->indices()->delete(['index' => strtolower($this->indexPrefix) . '*']);
        } else {
            // delete content only to keep settings and mappings
            $this->client->deleteByQuery(
                [
                    'index' => strtolower($this->indexPrefix) . '*',
                    'body' => [
                        'query' => [
                            'match_all' => new \stdClass(),
                        ],
                    ]
                ]
            );
        }

        $isConsole = Craft::$app->request->getIsConsoleRequest();

        $sites = Craft::$app->sites->getAllSites();
        $sitesCount = count($sites);
        foreach ($sites as $siteIndex => $site) {
            $entries = Entry::find()->section($this->sectionHandles)->site($site)->all();
            $categories = Category::find()->group($this->categoryGroupHandles)->site($site)->all();
            $entriesCount = count($entries);
            $categoriesCount = count($categories);

            foreach ($entries as $entryIndex => $entry) {
                if ($isConsole) {
                    echo 'Indexing entry: ' . $entry->title . "\n";
                }

                $reindexStep = function () use ($entry) {
                    $this->index($entry);
                };

                // either execute the re-index step using passed job and queue …
                if (!empty($reindexJob) && !empty($queue)) {
                    $progress = $siteIndex / $sitesCount + $entryIndex / ($entriesCount + $categoriesCount) / $sitesCount;
                    $label = $site->name . ' | ' . $entry->slug;
                    $reindexJob->step($queue, $reindexStep, $progress, $label);
                }
                // … or just do it right here and now
                else {
                    $reindexStep();
                }
            }

            foreach ($categories as $categoryIndex => $category) {
                if ($isConsole) {
                    echo 'Indexing category: ' . $category->title . "\n";
                }

                $reindexStep = function () use ($category) {
                    $this->index($category);
                };

                // either execute the re-index step using passed job and queue …
                if (!empty($reindexJob) && !empty($queue)) {
                    $progress = $siteIndex / $sitesCount + ($entriesCount + $categoryIndex) / ($entriesCount + $categoriesCount) / $sitesCount;
                    $label = $site->name . ' | ' . $category->slug;
                    $reindexJob->step($queue, $reindexStep, $progress, $label);
                }
                // … or just do it right here and now
                else {
                    $reindexStep();
                }
            }
        }
    }

    /**
     * Set index settings for an elasticsearch index
     *
     * @param string $index
     * @param array $settings
     * @param bool $closeAndOpenIndex
     */
    public function setIndexSettings(string $index, array $settings, $closeAndOpenIndex = false) {
        if ($closeAndOpenIndex) {
            // updating non dynamic settings requires index closing (and opening)
            $this->client->indices()->close(['index' => $index]);
        }

        $this->client->indices()->putSettings([
            'index' => $index,
            'body' => $settings
        ]);

        if ($closeAndOpenIndex) {
            // updating non dynamic settings requires index closing (and opening)
            $this->client->indices()->open(['index' => $index]);
        }
    }

    /**
     * Set an index template for an elasticsearch index
     *
     * @param string $name
     * @param array $templateArray
     * @return array
     */
    public function saveIndexTemplate(string $name, array $templateArray) {
        return $this->client->indices()->putTemplate([
            'name' => $name,
            'body' => $templateArray
        ]);
    }

    /**
     * Handles the EVENT_AFTER_SAVE event for elements.
     *
     * @param \craft\events\ModelEvent $event
     *
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\InvalidConfigException
     *
     * @see \craft\base\Element::EVENT_AFTER_SAVE
     */
    public function handleAfterSaveEvent(ModelEvent $event)
    {
        /** @var Element $element */
        $element = $event->sender;

        if ($this->isElementToBeIndexed($element)) {
            if ($this->isElementLive($element)) {
                $this->index($element);
            } else {
                $this->delete($element);
            }
        }
    }

    /**
     * Handles the EVENT_AFTER_RESTORE event for elements.
     *
     * @param \yii\base\Event $event
     *
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\InvalidConfigException
     *
     * @see \craft\base\Element::EVENT_AFTER_RESTORE
     */
    public function handleAfterRestoreEvent(Event $event)
    {
        /** @var Element $element */
        $element = $event->sender;

        if ($this->isElementToBeIndexed($element)) {
            if ($this->isElementLive($element)) {
                $this->index($element);
            } else {
                $this->delete($element);
            }
        }
    }

    /**
     * Handles the EVENT_AFTER_DELETE event for elements.
     *
     * @param \yii\base\Event $event
     *
     * @throws \craft\errors\MissingComponentException
     * @throws \yii\base\InvalidConfigException
     *
     * @see \craft\base\Element::EVENT_AFTER_DELETE
     */
    public function handleAfterDeleteEvent(Event $event)
    {
        /** @var Element $element */
        $element = $event->sender;

        if ($this->isElementToBeIndexed($element)) {
            $this->delete($element, true);
        }
    }

    /**
     * Determines if the given element has to be indexed (which depends on the element's section/group and the element's status).
     *
     * @param Element $element
     *
     * @return bool
     *
     * @throws \yii\base\InvalidConfigException
     */
    protected function isElementToBeIndexed(Element $element): bool
    {
        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                return $this->isSectionToBeIndexed($element->getSection()->handle) && !$element->getIsDraft() && !$element->getIsRevision();

            case Category::class:
                /** @var Category $element */
                return $this->isCategoryGroupToBeIndexed($element->getGroup()->handle);

            default:
                return false;
        }
    }

    /**
     * Determines if the given element is 'live'/'enabled'/'active' depending on the element's class
     *
     * @param Element $element
     * @return bool
     */
    protected function isElementLive(Element $element): bool
    {
        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                return $element->getStatus() === Entry::STATUS_LIVE;

            default:
                return $element->getStatus() === Element::STATUS_ENABLED;
        }
    }

    /**
     * Determines if entries of the given section handle are considered to be indexed.
     *
     * @param string $sectionHandle
     *
     * @return bool
     */
    protected function isSectionToBeIndexed(string $sectionHandle): bool
    {
        return in_array($sectionHandle, $this->sectionHandles);
    }

    /**
     * Determines if categories of the given group handle are considered to be indexed.
     *
     * @param string $sectionHandle
     *
     * @return bool
     */
    protected function isCategoryGroupToBeIndexed(string $groupHandle): bool
    {
        return in_array($groupHandle, $this->categoryGroupHandles);
    }

    /**
     * Determines the index name for the element in elasticsearch
     *
     * @param Element $element
     * @param Site $site
     *
     * @return string
     */
    protected function getIndexName(Element $element, Site $site): string
    {
        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                $snakeHandle = StringHelper::toSnakeCase($element->getSection()->handle);
                break;

            case Category::class:
                /** @var Category $element */
                $snakeHandle = StringHelper::toSnakeCase($element->getGroup()->handle);
                break;

            default:
                $snakeHandle = '';
                break;
        }

        return strtolower($this->indexPrefix . '_' . $snakeHandle . '_' . $site->language);
    }
}
