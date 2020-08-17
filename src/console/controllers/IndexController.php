<?php

namespace seibertio\elasticsearch\console\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\ElementHelper;
use craft\models\Site;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Exception;
use seibertio\elasticsearch\components\Document;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\DocumentEvent;
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

        $startIndexAllSites = microtime(true);

        foreach (Craft::$app->sites->getAllSites() as $site) {
            $this->indexSite($site);
        }

        $endIndexAllSites = microtime(true);
        $durationIndexAllSites = $endIndexAllSites - $startIndexAllSites;

        $this->stdout('Finished indexing all sites in ' . (round($durationIndexAllSites * 100) / 100) . 's.' . PHP_EOL, Console::FG_GREEN);

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

    public function actionEntrySync($entryId, $siteId) {
        $entry = Entry::find()->id($entryId)->siteId($siteId)->one();
        if (!$entry) {
            $this->stderr('Entry not found.' . PHP_EOL);
            return ExitCode::IOERR;
        }

        $index = ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($entry->site);

        $index->on(DocumentEvent::EVENT_BEFORE_INDEX, function(DocumentEvent $event) {
            $this->stdout('Trying to index URL ' . $event->params['body']['url'] . '...' . PHP_EOL);
        });

        ElasticSearchPlugin::$plugin->index->indexEntry($entry, $index);
        $this->stdout('OK' . PHP_EOL);
        return ExitCode::OK;
    }

    public function actionSiteSync($site) {
        $site = Craft::$app->sites->getSiteByHandle($site) ?: Craft::$app->sites->getSiteById($site);

        if (!$site) {
            $this->stderr('Site not found.' . PHP_EOL);
            return ExitCode::IOERR;
        }

        $this->indexSite($site);
        return ExitCode::OK;
    }
    // Private Methods
    // =========================================================================
    /**
     * @param Site $site
     */
    private function indexSite(Site $site): void
    {
        $this->stdout('Indexing site ' . $site->handle . '...' . PHP_EOL, Console::FG_GREY);
        /** @var Site $site */

        $index = Index::getInstance($site);

        $index->on(DocumentEvent::EVENT_BEFORE_INDEX, function(DocumentEvent $event) {
            $this->stdout('Trying to index URL ' . $event->params['body']['url'] . '...' . PHP_EOL);
        });

        try {
            ElasticSearchPlugin::$plugin->indexManagement->createIndex($index);
        } catch (Exception $e) {
            // index already exists
        }

        $existingDocumentIds = ElasticSearchPlugin::$plugin->index->getDocumentIDs($index);

        $startIndexSite = microtime(true);
        $autoIndexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();

        $entryQuery = Entry::find()->site($site)->drafts(false)->revisions(false);
        $entryQuery->section($autoIndexableSectionHandles);

        /** @var Entry[] */
        $entriesToIndex = array_filter($entryQuery->all(), fn(Entry $entry) => $entry->enabled && !ElementHelper::isDraftOrRevision($entry));

        $entriesTotal = sizeof($entriesToIndex);
        $entriesProcessed = 0;

        $this->stdout($entriesTotal . ' entries to process...' . PHP_EOL, Console::FG_GREY);

        $indexedDocumentIds = [];

        foreach ($entriesToIndex as $entry) {
            $startIndexEntry = microtime(true);
            $documentIndexed = ElasticSearchPlugin::$plugin->index->indexEntry($entry);
            $endIndexEntry = microtime(true);
            $durationIndexEntry = $endIndexEntry - $startIndexEntry;
            $entriesProcessed++;

            $color = $documentIndexed !== false ? Console::FG_GREEN : Console::FG_YELLOW;

            if ($documentIndexed !== false)
                $indexedDocumentIds[] = $documentIndexed->getId();

            $this->stdout($entriesProcessed . '/' . $entriesTotal . ' - ' . (round($durationIndexEntry * 100) / 100) . 's (' . round(($entriesProcessed / $entriesTotal) * 100) . '%)' . PHP_EOL, $color);
        }

        $removableDocumentIds = array_diff($existingDocumentIds, $indexedDocumentIds);

        if (sizeof($removableDocumentIds) > 0) {

            $this->stdout('Removing ' . sizeof($removableDocumentIds) . ' old documents from index...' . PHP_EOL, Console::FG_GREY);

            foreach ($removableDocumentIds as $documentId) {
                try {
                    ElasticSearchPlugin::$plugin->index->deleteDocument(new Document($documentId, $index));
                } catch (Missing404Exception $e) {
                    // ignore: document was removed before we could
                }
            }
        }

        $endIndexSite = microtime(true);
        $durationIndexSite = $endIndexSite - $startIndexSite;
        $this->stdout('Finished indexing site ' . $site->handle . ' in ' . (round($durationIndexSite * 100) / 100) . 's.' . PHP_EOL, Console::FG_GREEN);
    }

}
