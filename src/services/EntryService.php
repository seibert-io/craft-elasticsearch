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
    public function handleEntryUpdate(Entry $entry): void
    {
        if (!ElasticSearchPlugin::$plugin->getSettings()->getAutoIndexEntries()) return;

        if (ElementHelper::isDraftOrRevision($entry)) return;

        $entries = [$entry];

        foreach ($entries as $entryToProcess) {
            if (ElementHelper::isDraftOrRevision($entryToProcess)) continue;

            if (!$entryToProcess->enabled || !$entryToProcess->enabledForSite) {
                $job = new DeleteEntryJob(['entryId' => $entryToProcess->getId(), 'siteId' => $entryToProcess->siteId]);
            } else {
                $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();
                if (sizeof($indexableSectionHandles) === 0 || in_array($entryToProcess->section->handle, $indexableSectionHandles)) {
                    $job = new IndexEntryJob(['entryId' => $entryToProcess->id, 'siteId' => $entryToProcess->siteId]);
                }
            }

            if (isset($job) && !$job->isQueued()) {
                $queueId = Craft::$app->queue->push($job);
                $job->markQueued($queueId);
            }
        }
    }
}
