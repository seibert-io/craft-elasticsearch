<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\controllers;

use Craft;
use craft\helpers\UrlHelper;
use seibertio\elasticsearch\jobs\IndexSiteJob;
use seibertio\elasticsearch\jobs\ReIndexSiteJob;

class IndicesController extends BaseController
{
    /**
     * @inheritdoc
     */
    public $allowAnonymous = false;

    public function actionRebuild()
    {
        $this->requirePostRequest();
        $request = Craft::$app->getRequest();
        $input = $request->getBodyParams();

        $reindex = $input['recreate'] === '1';

        $sites = $input['sites']; // '*';
        if (!$sites) {
            $sites = [];
        } else if ($sites === '*') {
            $sites = Craft::$app->sites->getAllSites();
        } else {
            $sites = array_map(fn ($siteId) => Craft::$app->sites->getSiteById($siteId), $sites);
        }

        foreach ($sites as $site) {
            if ($reindex) {
                Craft::$app->queue->push(new ReIndexSiteJob(['siteId' => $site->id]));
            } else {
                Craft::$app->queue->push(new IndexSiteJob(['siteId' => $site->id]));
            }
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/craft-elasticsearch'));
    }
}
