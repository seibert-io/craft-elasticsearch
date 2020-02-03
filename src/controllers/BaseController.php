<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\controllers;

use craft\web\Controller;

class BaseController extends Controller
{

    public function init(): void
    {
        parent::init();
        // nothing this controller responds with must be cached in the CDN or elsewhere
        $this->preventResponseCaching();
    }

    /**
     * Sets cache headers to prevent caching in CDN as well as client browsers
     */
    protected function preventResponseCaching(): void
    {
        $headers = \Yii::$app->response->headers;
        $headers
            ->set('Expires', gmdate('D, d M Y H:i:s', time() - 86400) . ' GMT')
            ->set('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->set('Pragma', 'no-cache')
            ->set('Edge-Control', 'max-age=0s');
    }
}
