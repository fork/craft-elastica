<?php /** @noinspection PhpUnused */
/** @noinspection PhpMissingReturnTypeInspection */

/**
 * Elasticsearch plugin for Craft CMS 3.x
 *
 * A plugin to connect to Elasticsearch and persist elements via hooks
 *
 * @link      http://fork.de
 * @copyright Copyright (c) 2021 Fork Unstable Media GmbH
 */

namespace fork\elastica\controllers;

use Exception;
use fork\elastica\Elastica;

use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Elasticsearch Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Fork Unstable Media GmbH
 * @package   Elastica
 * @since     1.0.0
 */
class ElasticsearchController extends Controller
{

    // Public Methods
    // =========================================================================

    /**
     * @return Response
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     */
    public function actionReindex() {
        $this->requirePostRequest();
        $this->requireAdmin();

        try {
            Elastica::$plugin->indexer->reIndex();
            return $this->asJson(true);
        } catch (Exception $e) {
            Craft::$app->response->setStatusCode(500);
            return $this->asFailure($e->getMessage());
        }
    }
}
