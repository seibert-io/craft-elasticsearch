<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\jobs;

use craft\elements\Entry;
use craft\queue\JobInterface;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use yii\queue\RetryableJobInterface;

class IndexEntryJob extends TrackableJob implements JobInterface, RetryableJobInterface
{
	public int $entryId;
	
	public int $siteId;

    public function getEntry()
    {
		return Entry::find()->id($this->entryId)->siteId($this->siteId)->one();
    }

    public function execute($queue)
    {
			$entry = $this->getEntry();
			// Don't do anything if the entry that was requested to be indexed does not exist any longer

			if (!$entry) return;

			$index = $this->getIndex();

			try {
				ElasticSearchPlugin::$plugin->index->indexEntry($entry, $index);
			} catch (Missing404Exception $e) {
				// index does not exist, so we'll create it first and then try to re-index

				ElasticSearchPlugin::$plugin->indexManagement->createIndex($index);
				ElasticSearchPlugin::$plugin->index->indexEntry($entry, $index);
			}

			$this->markCompleted();
			
    }

    public function getDescription()
    {
        return 'Indexing of a Craft Entry';
	}
	
	public function getTtr()
    {
        return 60; // 1min
    }

    public function canRetry($attempt, $error)
    {
        return ($attempt < 5);
	}
	
	public function getCacheId(): string 
	{
		return $this->entryId;
	}

	public function getIndex(): Index {
		$site = $this->getEntry()->site;
		return ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);
	}
}
