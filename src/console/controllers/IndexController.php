<?php

namespace seibertio\elasticsearch\console\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\ElementHelper;
use craft\models\Site;
use seibertio\elasticsearch\ElasticSearchPlugin;
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

    public function actionIndexAllSitesSync()
    {
        $this->stdout('Indexing sites...' . PHP_EOL, Console::FG_GREY);

        foreach (Craft::$app->sites->getAllSites() as $site) {
            $this->stdout('Indexing site ' . $site->handle . '...' . PHP_EOL, Console::FG_GREY);
            /** @var Site $site */

            $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();

            $entryQuery = Entry::find()->site($site)->drafts(false)->revisions(false);
            if (sizeof($indexableSectionHandles) > 0) {
                $entryQuery->section($indexableSectionHandles);
            }

            /** @var Entry[] */
            $entriesToIndex = array_filter($entryQuery->all(), fn (Entry $entry) => $entry->enabled && !ElementHelper::isDraftOrRevision($entry));

            $entriesTotal = sizeof($entriesToIndex);
            $entriesProcessed = 0;

            $this->stdout($entriesTotal . ' total' . PHP_EOL, Console::FG_GREY);

            foreach ($entriesToIndex as $entry) {
                $indexed = ElasticSearchPlugin::$plugin->index->indexEntry($entry);

                $entriesProcessed++;

                $color = $indexed ? Console::FG_GREEN : Console::FG_YELLOW;
                $this->stdout($entriesProcessed . '/' . $entriesTotal . ' (' . round(($entriesProcessed / $entriesTotal) * 100) . '%)' . PHP_EOL, $color);
            }
        }

        $this->stdout('Finished indexing.' . PHP_EOL, Console::FG_GREEN);

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
