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
        if (sizeof($indexableSectionHandles) > 0) {
            $entryQuery->section($indexableSectionHandles);
        }

		/** @var Entry[] */
		$entriesToIndex = array_filter($entryQuery->all(), fn(Entry $entry) => $entry->enabled && !ElementHelper::isDraftOrRevision($entry));
		
		$entriesTotal = sizeof($entriesToIndex);
		$entriesProcessed = 0;

		foreach ($entriesToIndex as $entry) {
			$this->updateProgress($entriesProcessed / $entriesTotal);
			ElasticSearchPlugin::$plugin->index->indexEntry($entry);
			$entriesProcessed++;
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

    public function getIndex(): Index {
		$site = $this->getSite();
		return ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);
	}
}
