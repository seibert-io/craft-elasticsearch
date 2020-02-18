<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\jobs\DeleteEntryJob;
use seibertio\elasticsearch\jobs\IndexEntryJob;

/**
 * Entry Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class EntryService extends Component
{
	/**
     * Retrieves a list of the entry, its descendants and all of its related entries to an entry within the given entries site 
	 * (based on a relation chain where the given entry is the original target element,
	 * bubbling upwards recursively from there on)
     *
     * @param Entry $entry
	 * @return Entry[]
     */
    private function getEntryAndRelatedEntries(Entry $entry): array
    {
        $ancestors = [];

        if ($entry->enabled) {
            if (sizeof(array_filter($ancestors, fn($ancestor) => $ancestor->id === $entry->id)) === 0) {
                $ancestors[] = $entry;
            }

            $relatedEntries = Entry::find()->site($entry->site)->relatedTo(['targetElement' => $entry])->unique()->all();
            $relatedEntries = array_merge($relatedEntries, $entry->getDescendants(1)->all());
            
            foreach ($relatedEntries as $ancestor) {
                /** @var Entry $relatedEntry */
                if (ElementHelper::isDraftOrRevision($ancestor)) {
                    continue;
                }

                $ancestors = array_merge($ancestors, $this->getEntryAndRelatedEntries($ancestor));
            }
        }

        return $ancestors;
	}
	
	public function handleEntryUpdate(Entry $entry):void {
		if (!ElasticSearchPlugin::$plugin->getSettings()->getAutoIndexEntries()) return;
		
		if (ElementHelper::isDraftOrRevision($entry)) return;

		$entries = $this->getEntryAndRelatedEntries($entry);

		foreach ($entries as $entryToProcess) {
			if ($entryToProcess->id === $entry->id && !$entry->enabled) {
				$job = new DeleteEntryJob(['entryId' => $entry->getId(), 'siteId' => $entry->siteId]);
			} else {
				$job = new IndexEntryJob(['entryId' => $entry->getId(), 'siteId' => $entry->siteId]);
			}

			if (!$job->isQueued()) {
				$queueId = Craft::$app->queue->push($job);
				$job->markQueued($queueId);
			}
			
		}
	}
}
