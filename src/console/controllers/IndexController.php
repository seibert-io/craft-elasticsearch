<?php

namespace seibertio\elasticsearch\console\controllers;

use Craft;
use craft\helpers\Console;
use craft\models\Site;
use seibertio\elasticsearch\jobs\IndexSiteJob;
use seibertio\elasticsearch\jobs\ReIndexSiteJob;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Job Router console commands
 */
class IndexController extends Controller
{
    // Public Methods
    // =========================================================================

    public function actionIndexAllSites()
    {
        $this->stdout('Adding ElasticSearch indexing to queue...' . PHP_EOL, Console::FG_GREY);

        foreach (Craft::$app->sites->getAllSites() as $site) {
            /** @var Site $site */
            $job = new IndexSiteJob(['siteId' => $site->id]);
            Craft::$app->queue->push($job);
        }

        $this->stdout('Finished adding ElasticSearch indexing to queue.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    public function actionReIndexAllSites()
    {
        $this->stdout('Adding ElasticSearch re-indexing to queue...' . PHP_EOL, Console::FG_GREY);

        foreach (Craft::$app->sites->getAllSites() as $site) {
            /** @var Site $site */
            $job = new ReIndexSiteJob(['siteId' => $site->id]);
            Craft::$app->queue->push($job);
        }

        $this->stdout('Finished adding ElasticSearch re-indexing to queue.' . PHP_EOL, Console::FG_GREEN);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

}
