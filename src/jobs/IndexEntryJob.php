<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\JobInterface;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use Exception;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\exceptions\IndexConfigurationException;
use yii\queue\RetryableJobInterface;

class IndexEntryJob extends TrackableJob implements JobInterface, RetryableJobInterface
{
	public int $entryId;
	
	public int $siteId;

    public function getEntry(): Entry
    {
		return Entry::find()->id($this->entryId)->siteId($this->siteId)->one();
    }

    public function execute($queue)
    {
			$entry = $this->getEntry();
			

			try {
				ElasticSearchPlugin::$plugin->index->indexEntry($entry);
			} catch (Missing404Exception $e) {
				// index does not exist, so we'll create it first and then try to re-index
				$site = $entry->site;
				$index = ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);

				ElasticSearchPlugin::$plugin->indexManagement->createIndex($index);
				ElasticSearchPlugin::$plugin->index->indexEntry($entry);
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
}
