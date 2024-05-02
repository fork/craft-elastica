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
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\errors\MissingComponentException;
use craft\events\ModelEvent;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\models\Site;
use craft\queue\QueueInterface;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Elasticsearch\Common\Exceptions\NoNodesAvailableException;
use Exception;
use fork\elastica\Elastica;
use fork\elastica\events\IndexerInitEvent;
use fork\elastica\events\IndexEvent;
use fork\elastica\queue\ReindexJob;
use yii\base\Event;
use yii\base\InvalidConfigException;

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
     * list of volume handles to consider for (re-)indexing assets
     *
     * @var string[]
     */
    protected $volumeHandles;

    /**
     * @inheritdoc
     */
    public function init(): void
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
            $this->volumeHandles = $event->getVolumeHandles();
        }
    }

    /**
     * Returns the connection status
     *
     * @return bool|string
     */
    public function getConnectionStatus()
    {
        try {
            return $this->client->ping();
        } catch (NoNodesAvailableException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param Element $element
     * @param $content
     *
     * @return array|bool
     *
     * @throws MissingComponentException
     * @throws InvalidConfigException
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
     * @throws MissingComponentException
     * @throws InvalidConfigException
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
     * @param ReindexJob|null $reindexJob
     * @param QueueInterface|null $queue
     * @param bool $deleteAll delete all contents and index settings and mappings
     * @throws MissingComponentException
     * @throws InvalidConfigException
     */
    public function reIndex(ReindexJob $reindexJob = null, QueueInterface $queue = null, bool $deleteAll = false): void
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
            $assets = Asset::find()->volume($this->volumeHandles)->site($site)->all();
            $entriesCount = count($entries);
            $categoriesCount = count($categories);
            $assetsCount = count($assets);

            foreach ($entries as $entryIndex => $entry) {
                if ($isConsole) {
                    echo 'Indexing entry: ' . $entry->title . "\n";
                }

                $reindexStep = function () use ($entry) {
                    $this->index($entry);
                };

                // either execute the re-index step using passed job and queue …
                if (!empty($reindexJob) && !empty($queue)) {
                    $progress = $siteIndex / $sitesCount + $entryIndex / ($entriesCount + $categoriesCount + $assetsCount) / $sitesCount;
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
                    $progress = $siteIndex / $sitesCount + ($entriesCount + $categoryIndex) / ($entriesCount + $categoriesCount + $assetsCount) / $sitesCount;
                    $label = $site->name . ' | ' . $category->slug;
                    $reindexJob->step($queue, $reindexStep, $progress, $label);
                }
                // … or just do it right here and now
                else {
                    $reindexStep();
                }
            }

            foreach ($assets as $assetIndex => $asset) {
                if ($isConsole) {
                    echo 'Indexing asset: ' . $asset->title . "\n";
                }

                $reindexStep = function () use ($asset) {
                    $this->index($asset);
                };

                // either execute the re-index step using passed job and queue …
                if (!empty($reindexJob) && !empty($queue)) {
                    $progress = $siteIndex / $sitesCount + ($entriesCount + $categoryIndex + $assetIndex) / ($entriesCount + $categoriesCount + $assetsCount) / $sitesCount;
                    $label = $site->name . ' | ' . $asset->filename;
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
            // updating non-dynamic settings requires index closing (and opening)
            $this->client->indices()->close(['index' => $index]);
        }

        $this->client->indices()->putSettings([
            'index' => $index,
            'body' => $settings
        ]);

        if ($closeAndOpenIndex) {
            // updating non-dynamic settings requires index closing (and opening)
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
    public function saveIndexTemplate(string $name, array $templateArray): array
    {
        return $this->client->indices()->putTemplate([
            'name' => $name,
            'body' => $templateArray
        ]);
    }

    /**
     * Saves/updates a search template in elasticsearch
     *
     * @param string $handle
     * @param array $source
     * @param array|null $params
     * @return array
     */
    public function saveSearchTemplate(string $handle, array $source, array $params = null): array
    {
        return $this->client->putScript([
            'id' => $handle,
            'body' => [
                'script' =>
                    [
                        'lang' => 'mustache',
                        'source' => $source,
                        'params' => $params
                    ]
            ]
        ]);
    }

    /**
     * Handles the EVENT_AFTER_SAVE event for elements.
     *
     * @param ModelEvent $event
     *
     * @throws MissingComponentException
     * @throws InvalidConfigException
     *
     * @see Element::EVENT_AFTER_SAVE
     */
    public function handleAfterSaveEvent(ModelEvent $event): void
    {
        /** @var Element $element */
        $element = $event->sender;

        if (ElementHelper::isDraftOrRevision($element)) {
            // don’t do anything with drafts or revisions
            return;
        }

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
     * @param Event $event
     *
     * @throws MissingComponentException
     * @throws InvalidConfigException
     *
     * @see Element::EVENT_AFTER_RESTORE
     */
    public function handleAfterRestoreEvent(Event $event): void
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
     * @param Event $event
     *
     * @throws MissingComponentException
     * @throws InvalidConfigException
     *
     * @see Element::EVENT_AFTER_DELETE
     */
    public function handleAfterDeleteEvent(Event $event): void
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
     * @throws InvalidConfigException
     */
    protected function isElementToBeIndexed(Element $element): bool
    {
        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                return $this->isSectionToBeIndexed($element->getSection()->handle);

            case Category::class:
                /** @var Category $element */
                return $this->isCategoryGroupToBeIndexed($element->getGroup()->handle);

            case Asset::class:
                /** @var Asset $element */
                return $this->isVolumeToBeIndexed($element->getVolume()->handle);

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
        return match (get_class($element)) {
            Asset::class => true,
            Entry::class => $element->getStatus() === Entry::STATUS_LIVE,
            default => $element->getStatus() === Element::STATUS_ENABLED,
        };
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
     * @param string $groupHandle
     * @return bool
     */
    protected function isCategoryGroupToBeIndexed(string $groupHandle): bool
    {
        return in_array($groupHandle, $this->categoryGroupHandles);
    }

    /**
     * Determines if assets of the given volume handle are considered to be indexed.
     *
     * @param string $volumeHandle
     * @return bool
     */
    protected function isVolumeToBeIndexed(string $volumeHandle): bool
    {
        return in_array($volumeHandle, $this->volumeHandles);
    }

    /**
     * Determines the index name for the element in elasticsearch
     *
     * @param Element $element
     * @param Site $site
     *
     * @return string
     * @throws InvalidConfigException
     */
    protected function getIndexName(Element $element, Site $site): string
    {
        $parts = [$this->indexPrefix];

        switch (get_class($element)) {
            case Entry::class:
                /** @var Entry $element */
                $parts[] = StringHelper::toSnakeCase($element->getSection()->handle);
                break;

            case Category::class:
                /** @var Category $element */
                $parts[] = 'cat';
                $parts[] = StringHelper::toSnakeCase($element->getGroup()->handle);
                break;

            case Asset::class:
                /** @var Asset $element */
                $parts[] = 'file';
                $parts[] = StringHelper::toSnakeCase($element->getVolume()->handle);
                break;

            default:
                break;
        }

        $parts[] = StringHelper::toLowerCase($site->language);

        return join('_', $parts);
    }
}
