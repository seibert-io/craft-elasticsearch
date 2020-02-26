<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use benf\neo\elements\Block;
use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\jobs\DeleteEntryJob;
use seibertio\elasticsearch\jobs\IndexSiteJob;

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
    private function getEntryAndRelatedEntries(Entry $entry, $includeDescendants = true): array
    {
        $ancestors = [];

        if ($entry->enabled) {
            $ancestors[] = $entry;

            // find regular field relations
            $relatedEntries = Entry::find()->site($entry->site)->relatedTo(['targetElement' => $entry])->unique()->all();
            if ($includeDescendants) {
                $relatedEntries = array_merge($relatedEntries, $entry->getDescendants(1)->all());
            }
            /*
            // find neo block relations
            $blocks = Block::find()->relatedTo(['targetElement' => $entry])->unique()->all();
            $entriesTargetingBlock = Entry::find()->id(array_map(fn ($block) => $block->ownerId, $blocks))->all();
            $relatedEntries = array_merge($relatedEntries, $entriesTargetingBlock);
            */
            foreach ($relatedEntries as $ancestor) {
                /** @var Entry $relatedEntry */
                if (ElementHelper::isDraftOrRevision($ancestor)) {
                    continue;
                }

                foreach ($this->getEntryAndRelatedEntries($ancestor, false) as $ancestorEntry) {

                    if (sizeof(array_filter($ancestors, fn ($ancestor) => $ancestor->id === $ancestorEntry->id)) === 0) {
                        $ancestors[] = $ancestorEntry;
                    }
                }
            }
        }

        return $ancestors;
    }

    public function handleEntryUpdate(Entry $entry): void
    {
        if (!ElasticSearchPlugin::$plugin->getSettings()->getAutoIndexEntries()) return;

        if (ElementHelper::isDraftOrRevision($entry)) return;

        //$entries = $this->getEntryAndRelatedEntries($entry);
        $entries = [$entry];

        foreach ($entries as $entryToProcess) {
            if (ElementHelper::isDraftOrRevision($entryToProcess)) continue;

            if (!$entryToProcess->enabled) {
                $job = new DeleteEntryJob(['entryId' => $entryToProcess->getId(), 'siteId' => $entryToProcess->siteId]);
            } else {
                $cacheId = ElementHelper::createSlug(get_class($this)) . '-indexsite-throttle-' . $entryToProcess->siteId;

                if (!Craft::$app->cache->get($cacheId)) {
                    $job = new IndexSiteJob(['siteId' => $entryToProcess->siteId]);
                    Craft::$app->cache->set($cacheId, true, 60 * 60 * 2); // throttle site indexing to 2hrs TODO: move to setting?
                }
            }

            if (!$job->isQueued()) {
                $queueId = Craft::$app->queue->push($job);
                $job->markQueued($queueId);
            }
        }
    }
}
