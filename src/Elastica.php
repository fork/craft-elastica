<?php
/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * A plugin to connect to Elasticsearch and persist elements via hooks
 *
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

namespace fork\elastica;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use fork\elastica\models\Settings;
use fork\elastica\services\Indexer;
use fork\elastica\services\Utility as UtilityService;
use fork\elastica\utilities\Utility as CpUtility;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Event;
use yii\base\Exception;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://craftcms.com/docs/plugins/introduction
 *
 * @author    Fork Unstable Media GmbH
 * @package   Elastica
 * @since     1.0.0
 *
 * @property  UtilityService $utility
 * @property  Indexer $indexer
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class Elastica extends Plugin
{

    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Elastica::$plugin
     *
     * @var Elastica
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Elastica::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        $this->setComponents([
            'utility' => UtilityService::class,
        ]);

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                Utilities::class,
                Utilities::EVENT_REGISTER_UTILITY_TYPES,
                function (RegisterComponentTypesEvent $event) {
                    $event->types[] = CpUtility::class;
                }
            );
        }

        // elasticsearch index actions
        Event::on(Element::class, Element::EVENT_AFTER_SAVE, function (ModelEvent $event) {
            $this->indexer->handleAfterSaveEvent($event);
        });
        Event::on(Element::class, Element::EVENT_AFTER_RESTORE, function (Event $event) {
            $this->indexer->handleAfterRestoreEvent($event);
        });
        Event::on(Element::class, Element::EVENT_AFTER_DELETE, function (Event $event) {
            $this->indexer->handleAfterDeleteEvent($event);
        });

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function(RegisterUserPermissionsEvent $event) {
                $event->permissions[] = [
                    'heading' => 'Elastica',
                    'permissions' => [
                        'elasticaIndexTemplates' => [
                            'label' => 'Set index templates in elasticsearch',
                        ],
                        'elasticaSearchTemplates' => [
                            'label' => 'Add/update search templates in elasticsearch',
                        ],
                    ]
                ];
            }
        );
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return Model|null
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws Exception
     */
    protected function settingsHtml(): string
    {
        return Craft::$app->view->renderTemplate(
            'elastica/settings',
            [
                'connectionStatus' => $this->indexer->getConnectionStatus(),
                'settings' => $this->getSettings(),
            ]
        );
    }
}
