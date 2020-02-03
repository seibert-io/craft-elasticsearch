<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\controllers;

use Craft;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;

class QueryController extends BaseController
{
    /**
     * @inheritdoc
     */
    public $allowAnonymous = true;

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    public function actionSearch(): array
    {
        //$this->requirePostRequest();
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Craft::$app->getRequest();
        $input = $request->getQueryParams();

        return ElasticSearchPlugin::$plugin->query->search($input, Index::getInstance(Craft::$app->sites->getCurrentSite()));
    }

    public function actionSuggest(): array
    {
        //$this->requirePostRequest();
        \Yii::$app->response->format = \yii\web\Response::FORMAT_JSON;

        $request = Craft::$app->getRequest();
        $input = $request->getQueryParams();

        return ElasticSearchPlugin::$plugin->query->suggest($input, Index::getInstance(Craft::$app->sites->getCurrentSite()));
    }

    public function actionGetToken()
    {
        // TODO: CSRF token protection
        // return Craft::$app->getRequest()->getCsrfToken();
    }
}
