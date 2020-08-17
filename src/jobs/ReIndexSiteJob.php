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
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use yii\queue\RetryableJobInterface;

class ReIndexSiteJob extends TrackableJob implements JobInterface, RetryableJobInterface
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
        $index = $this->getIndex();

		try {
			ElasticSearchPlugin::$plugin->indexManagement->deleteIndex($index);
		} catch (Missing404Exception $e) {
			// not an error - the index simply does not exist yet
		}

		ElasticSearchPlugin::$plugin->indexManagement->createIndex($index);

        $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();

        $entryQuery = Entry::find()->site($site)->drafts(false)->revisions(false);
        if (!in_array('*', $indexableSectionHandles)) {
            $entryQuery->section($indexableSectionHandles);
        }

		/** @var Entry[] */
		$entriesToIndex = array_filter($entryQuery->all(), fn(Entry $entry) => $entry->enabled && !ElementHelper::isDraftOrRevision($entry));
		
		$entriesTotal = sizeof($entriesToIndex);
		$entriesProcessed = 0;

		foreach ($entriesToIndex as $entry) {
            $this->updateProgress($entriesProcessed / $entriesTotal);
            try {
                ElasticSearchPlugin::$plugin->index->indexEntry($entry);
            } catch (Exception $e) {
                Craft::error($e);
            }
			$entriesProcessed++;
		}

        $this->markCompleted();
    }

    public function getDescription()
    {
        return 'Rebuild the index of a Craft site';
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
        return $this->siteId . '-reindex';
    }

    public function getIndex(): Index {
		$site = $this->getSite();
		return ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);
	}
}
