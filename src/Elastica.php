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
use yii\base\Event;

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
 * @property  \fork\elastica\services\Utility $utility
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
    public $schemaVersion = '1.0.0';

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
                $event->permissions['Elastica'] = [
                    'elasticaIndexTemplates' => [
                        'label' => 'Set index templates in elasticsearch',
                    ],
                ];
            }
        );

        /**
         * Logging in Craft involves using one of the following methods:
         *
         * Craft::trace(): record a message to trace how a piece of code runs. This is mainly for development use.
         * Craft::info(): record a message that conveys some useful information.
         * Craft::warning(): record a warning message that indicates something unexpected has happened.
         * Craft::error(): record a fatal error that should be investigated as soon as possible.
         *
         * Unless `devMode` is on, only Craft::warning() & Craft::error() will log to `craft/storage/logs/web.log`
         *
         * It's recommended that you pass in the magic constant `__METHOD__` as the second parameter, which sets
         * the category to the method (prefixed with the fully qualified class name) where the constant appears.
         *
         * To enable the Yii debug toolbar, go to your user account in the AdminCP and check the
         * [] Show the debug toolbar on the front end & [] Show the debug toolbar on the Control Panel
         *
         * http://www.yiiframework.com/doc-2.0/guide-runtime-logging.html
         */
        //Craft::info(
        //    Craft::t(
        //        'elasticsearch',
        //        '{name} plugin loaded',
        //        ['name' => $this->name]
        //    ),
        //    __METHOD__
        //);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     *
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
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
