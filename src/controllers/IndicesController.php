<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\controllers;

use Craft;
use craft\helpers\UrlHelper;
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

		$sites = $input['sites']; // '*';
		if ($sites === '*') {
			$sites = Craft::$app->sites->getAllSites();
		} else {
			$sites = array_map(fn($siteId) => Craft::$app->sites->getSiteById($siteId), $sites);
		}

		foreach($sites as $site) {
			Craft::$app->queue->push(new ReIndexSiteJob(['siteId' => $site->id]));
        }

        return $this->redirect(UrlHelper::cpUrl('utilities/craft-elasticsearch'));
    }
}
