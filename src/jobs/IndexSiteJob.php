<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\models\Site;
use craft\queue\JobInterface;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Exception;
use seibertio\elasticsearch\components\Document;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use yii\queue\RetryableJobInterface;

class IndexSiteJob extends TrackableJob implements JobInterface, RetryableJobInterface
{
    /**
     * @property string[]
     */
    public int $siteId;


    public function getSite(): Site
    {
        return Craft::$app->sites->getSiteById($this->siteId);
    }

    public function execute($queue)
    {
        $site = $this->getSite();

        $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();

        $entryQuery = Entry::find()->site($site)->drafts(false)->revisions(false);
        if (!in_array('*', $indexableSectionHandles)) {
            $entryQuery->section($indexableSectionHandles);
        }

        /** @var Entry[] */
        $entriesToIndex = array_filter($entryQuery->all(), fn (Entry $entry) => $entry->enabled && !ElementHelper::isDraftOrRevision($entry));

        $entriesTotal = sizeof($entriesToIndex);
        $entriesProcessed = 0;

        $index = Index::getInstance($site);

        $existingDocumentIds = ElasticSearchPlugin::$plugin->index->getDocumentIDs($index);
        $indexedDocumentIds = [];

        foreach ($entriesToIndex as $entry) {
            $this->updateProgress($entriesProcessed / $entriesTotal);
            try {
                $documentIndexed = ElasticSearchPlugin::$plugin->index->indexEntry($entry);

                if ($documentIndexed !== false)
                    $indexedDocumentIds[] = $documentIndexed->getId();
            } catch (Exception $e) {
                Craft::error($e);
            }

            $entriesProcessed++;
        }

        $removableDocumentIds = array_diff($existingDocumentIds, $indexedDocumentIds);

        foreach ($removableDocumentIds  as $documentId) {
            try {
                ElasticSearchPlugin::$plugin->index->deleteDocument(new Document($documentId, $index));
            } catch (Missing404Exception $e) {
                // ignore: document was removed before we could
            }
        }


        $this->markCompleted();
    }

    public function getDescription()
    {
        return 'Index all indexable entries of a Craft site';
    }

    public function getTtr()
    {
        return 60 * 15; // 15min
    }

    public function canRetry($attempt, $error)
    {
        return ($attempt < 2);
    }

    public function getCacheId(): string
    {
        return $this->siteId . '-index';
    }

    public function getIndex(): Index
    {
        $site = $this->getSite();
        return ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);
    }
}
