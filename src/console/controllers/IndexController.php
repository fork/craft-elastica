<?php

namespace fork\elastica\console\controllers;

use fork\elastica\Elastica;

use Craft;
use yii\base\Behavior;
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
   * Handle mm360features/api console commands
   *
   * The first line of this method docblock is displayed as the description
   * of the Console Command in ./craft help
   *
   * @return mixed
   */
//    public function actionIndex()
//    {
//        $result = 'something';
//
//        echo "Welcome to the console ApiController actionIndex() method\n";
//
//        return $result;
//    }

    /**
     * Delete Index and Reindex
     *
     * @return mixed
     * @throws \yii\base\InvalidConfigException
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
    public function getToken() {}
}
