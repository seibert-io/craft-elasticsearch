<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\jobs;

use Craft;
use craft\elements\Entry;
use craft\queue\JobInterface;
use Exception;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;

class DeleteEntryJob extends TrackableJob implements JobInterface
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
		$index = $this->getIndex();
		
		try {
			ElasticSearchPlugin::$plugin->index->deleteEntry($entry, $index);
			$this->markCompleted();
		} catch (Exception $e) {
			// fire & forget. no need to react if index or entry document are already gone
		}
    }

    public function getDescription()
    {
        return 'Remove a Craft Entry from the index';
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
