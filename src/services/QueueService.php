<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use seibertio\elasticsearch\jobs\DeleteEntryJob;
use seibertio\elasticsearch\jobs\IndexEntryJob;

class QueueService extends Component
{
    public function indexEntry(Entry $entry): IndexEntryJob
    {
        $job = new IndexEntryJob(['entryId' => $entry->id, 'siteId' => $entry->siteId]);

        $queueId = Craft::$app->queue->push($job);
        $job->markQueued($queueId);

        return $job;
    }

    public function deleteEntry(Entry $entry): DeleteEntryJob {

        $job = new DeleteEntryJob(['entryId' => $entry->getId(), 'siteId' => $entry->siteId]);
        $queueId = Craft::$app->queue->push($job);
        $job->markQueued($queueId);

        return $job;
    }
}
