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
use Elastic\Elasticsearch\Client;
use Elastic\Elasticsearch\ClientBuilder;
use Elastic\Elasticsearch\Exception\AuthenticationException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use Elastic\Elasticsearch\Response\Elasticsearch;
use Exception;
use fork\elastica\Elastica;
use fork\elastica\events\IndexerInitEvent;
use fork\elastica\events\IndexEvent;
use fork\elastica\queue\ReindexJob;
use Http\Promise\Promise;
use stdClass;
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
    private Client $client;

    /** @var string */
    private string $indexPrefix = 'craft';

    public const EVENT_INDEXER_INIT = 'indexerInit';
    public const EVENT_BEFORE_INDEX_DATA = 'beforeIndexData';

    /**
     * list of section handles to consider for (re-)indexing entries
     *
     * @var string[]
     */
    protected array $sectionHandles;

    /**
     * list of group handles to consider for (re-)indexing categories
     *
     * @var string[]
     */
    protected array $categoryGroupHandles;

    /**
     * list of volume handles to consider for (re-)indexing assets
     *
     * @var string[]
     */
    protected array $volumeHandles;

    /**
     * @inheritdoc
     * @throws AuthenticationException
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
    public function getConnectionStatus(): bool|string
    {
        try {
            $status = $this->client->ping();
            return $status->asBool() ?: $status->asString();
        } catch (ClientResponseException|ServerResponseException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param Element $element
     * @param null $content
     *
     * @return Elasticsearch|Promise|null
     *
     * @throws ClientResponseException
     * @throws InvalidConfigException
     * @throws MissingComponentException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function index(Element $element, $content = null): Elasticsearch|Promise|null
    {
        $site = $element->getSite();

        // Fire a 'beforeIndexData' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_INDEX_DATA)) {
            $event = new IndexEvent([
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
            return empty($content) ? null : $this->client->index($params);
        } catch (Exception $exception) {
            Craft::error($exception->getMessage(), 'elasticsearch');
            if (Craft::$app->getRequest()->getIsCpRequest() && !Craft::$app->getResponse()->isSent) {
                // show error message when saving in control panel
                Craft::$app->getSession()->setError($exception->getMessage());
            } else {
                // to get error in index background job/queue
                throw $exception;
            }

            return null;
        }
    }

    /**
     * @param Element $element
     * @param bool $force
     *
     * @throws MissingComponentException
     * @throws InvalidConfigException
     */
    public function delete(Element $element, bool $force = false): void
    {
        $isMultiSite = Craft::$app->getIsMultiSite();

        foreach (Craft::$app->sites->getAllSites() as $site) {
            $siteElement = $element;

            if ($isMultiSite && !$force) {
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
     * Reindex the elements.
     *
     * @param ReindexJob|null $reindexJob
     * @param QueueInterface|null $queue
     * @param bool $deleteAll delete all contents and index settings and mappings
     * @throws ClientResponseException
     * @throws InvalidConfigException
     * @throws MissingComponentException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function reIndex(ReindexJob $reindexJob = null, QueueInterface $queue = null, bool $deleteAll = false): void
    {
        if ($deleteAll) {
            foreach ($this->getAllIndexNames() as $indexName) {
                $this->client->indices()->delete(['index' => $indexName, 'ignore_unavailable' => true]);
            }
        } else {
            foreach ($this->getAllIndexNames() as $indexName) {
                // delete content only to keep settings and mappings
                $this->client->deleteByQuery(
                    [
                        'index' => $indexName,
                        'ignore_unavailable' => true,
                        'body' => [
                            'query' => [
                                'match_all' => new stdClass(),
                            ],
                        ]
                    ]
                );
            }
        }

        $isConsole = Craft::$app->request->getIsConsoleRequest();

        $sites = Craft::$app->sites->getAllSites();
        $sitesCount = count($sites);
        foreach ($sites as $siteIndex => $site) {
            $entries = Entry::find()->section($this->sectionHandles)->site($site)->all();
            $categories = Category::find()->group($this->categoryGroupHandles)->site($site)->all();
            /** @var Asset[] $assets */
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
                    $progress = $siteIndex / $sitesCount + ($entriesCount + $categoriesCount + $assetIndex) / ($entriesCount + $categoriesCount + $assetsCount) / $sitesCount;
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
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function setIndexSettings(string $index, array $settings, bool $closeAndOpenIndex = false): void
    {
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
     * @return Elasticsearch|Promise
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function saveIndexTemplate(string $name, array $templateArray): Elasticsearch|Promise
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
     * @return Elasticsearch|Promise
     * @throws ClientResponseException
     * @throws ServerResponseException
     * @throws MissingParameterException
     */
    public function saveSearchTemplate(string $handle, array $source, array $params = null): Elasticsearch|Promise
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
     * @throws ClientResponseException
     * @throws InvalidConfigException
     * @throws MissingComponentException
     * @throws MissingParameterException
     * @throws ServerResponseException
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
     * @throws ClientResponseException
     * @throws InvalidConfigException
     * @throws MissingComponentException
     * @throws MissingParameterException
     * @throws ServerResponseException
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
        return match (get_class($element)) {
            Entry::class => $this->isSectionToBeIndexed($element->getSection()?->handle),
            Category::class => $this->isCategoryGroupToBeIndexed($element->getGroup()->handle),
            Asset::class => $this->isVolumeToBeIndexed($element->getVolume()->handle),
            default => false,
        };
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
     * @param string|null $sectionHandle
     *
     * @return bool
     */
    protected function isSectionToBeIndexed(?string $sectionHandle): bool
    {
        return !empty($sectionHandle) && in_array($sectionHandle, $this->sectionHandles);
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

        $parts[] = StringHelper::toLowerCase($site->handle);
        $parts[] = StringHelper::toLowerCase($site->language);

        return join('_', $parts);
    }

    /**
     * @return string[]
     */
    protected function getAllIndexNames(): array
    {
        $indexNames = [];
        $sites = Craft::$app->getSites()->getAllSites();

        foreach ($sites as $site) {
            foreach ($this->sectionHandles as $sectionHandle) {
                $indexNames[] = join(
                    '_',
                    [
                        $this->indexPrefix,
                        StringHelper::toSnakeCase($sectionHandle),
                        StringHelper::toLowerCase($site->handle),
                        StringHelper::toLowerCase($site->language)
                    ]
                );
            }

            foreach ($this->categoryGroupHandles as $cgHandle) {
                $indexNames[] = join(
                    '_',
                    [
                        $this->indexPrefix,
                        StringHelper::toSnakeCase($cgHandle),
                        StringHelper::toLowerCase($site->handle),
                        StringHelper::toLowerCase($site->language)
                    ]
                );
            }

            foreach ($this->volumeHandles as $volumeHandle) {
                $indexNames[] = join(
                    '_',
                    [
                        $this->indexPrefix,
                        StringHelper::toSnakeCase($volumeHandle),
                        StringHelper::toLowerCase($site->handle),
                        StringHelper::toLowerCase($site->language)
                    ]
                );
            }
        }

        return $indexNames;
    }
}
