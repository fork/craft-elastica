<?php

namespace fork\elastica\utilities;

use Craft;
use craft\base\Utility as BaseUtility;
use craft\errors\MissingComponentException;
use fork\elastica\Elastica;
use fork\elastica\services\Indexer;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;

/**
 * The Utility class represents the plugin's utility as being accessible in the Craft CP's "utilities" section.
 *
 * @package fork\elastica\utilities
 */
class Utility extends BaseUtility
{
    /**
     * @var Indexer
     */
    protected Indexer $indexer;

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $this->indexer = Elastica::$plugin->indexer;
    }

    /**
     * Returns the display name of this utility.
     *
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return 'Elastica';
    }

    /**
     * Returns the utility’s unique identifier.
     *
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     *
     * @return string
     */
    public static function id(): string
    {
        return 'elastica';
    }

    /**
     * Returns the path to the utility's SVG icon.
     *
     * @return string|null The path to the utility SVG icon
     */
    public static function icon(): ?string
    {
        return Craft::getAlias("@fork/elastica/assetbundles/elastica/dist/img/Elastica-icon.svg");
    }

    /**
     * Returns the utility's content HTML.
     *
     * @return string
     *
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws MissingComponentException
     * @throws Exception
     */
    public static function contentHtml(): string
    {
        $triggered = Elastica::$plugin->utility->handleUtilityFormSubmit();

        $settings = Elastica::$plugin->getSettings();

        return Craft::$app->getView()->renderTemplate('elastica/utilities', [
            'connectionStatus' => Elastica::$plugin->indexer->getConnectionStatus(),
            'triggered' => $triggered,
            'indexTemplateName' => $settings->indexTemplateName,
            'indexTemplate' => $settings->indexTemplate,
        ]);
    }
}
