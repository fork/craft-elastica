<?php

/** @noinspection PhpUnused */

/** @noinspection PhpMissingReturnTypeInspection */

namespace fork\elastica\console\controllers;

use craft\errors\MissingComponentException;
use Elastic\Elasticsearch\Exception\ClientResponseException;
use Elastic\Elasticsearch\Exception\MissingParameterException;
use Elastic\Elasticsearch\Exception\ServerResponseException;
use fork\elastica\Elastica;
use Craft;
use yii\base\Behavior;
use yii\base\InvalidConfigException;
use yii\console\Controller;

/**
 * Api Command
 *
 * The first line of this class docblock is displayed as the description
 * of the Console Command in ./craft help
 *
 * Craft can be invoked via commandline console by using the `./craft` command
 * from the project root.
 *
 * Console Commands are just controllers that are invoked to handle console
 * actions. The segment routing is plugin-name/controller-name/action-name
 *
 * The actionIndex() method is what is executed if no sub-commands are supplied, e.g.:
 *
 * ./craft elasticsearch/index
 *
 * Actions must be in 'kebab-case' so actionDoSomething() maps to 'do-something',
 * and would be invoked via:
 *
 * ./craft elasticsearch/index/reindex
 *
 * @author    Fork Unstable Media GmbH
 * @package   Elastica
 * @since     1.2.0
 */
class IndexController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Delete Index and Reindex
     *
     * @return void
     * @throws InvalidConfigException
     * @throws MissingComponentException
     * @throws ClientResponseException
     * @throws MissingParameterException
     * @throws ServerResponseException
     */
    public function actionReindex()
    {
        Craft::$app->config->general->enableTemplateCaching = false;

        // emulate getToken() method...
        $requestComponent = Craft::$app->get('request');
        $requestComponent->attachBehavior('getToken', ConsoleRequestTokenBehaviour::class);

        Elastica::$plugin->indexer->reIndex();
    }

}

class ConsoleRequestTokenBehaviour extends Behavior
{
    public function getToken()
    {
    }
}
