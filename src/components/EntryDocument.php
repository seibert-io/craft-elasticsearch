<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\components;

use craft\elements\Entry;
use seibertio\elasticsearch\events\DocumentEvent;

/**
 * A document that is indexed to an ElasticSearch index based on a Craft Entry
 *
 * @inheritdoc
 */
class EntryDocument extends Document
{
    public Entry $entry;

    /**
     * @param Entry $entry
     * @param Index|null $index if not provided, the default index will be used
     */
    public function __construct(Entry $entry, $index = null)
    {
        $this->entry = $entry;
        parent::__construct($entry->siteId . '-' . $entry->id, $index ?? Index::getInstance($entry->site));

        $this->on(DocumentEvent::EVENT_BEFORE_INDEX, [$this, 'onBeforeIndex']);
        $this->trigger(DocumentEvent::EVENT_INIT, new DocumentEvent());
    }

    public function onBeforeIndex()
    {
        $entry = $this->entry;

        if (empty($this->title)) {
            $this->title = $entry->title;
        }

        $this->postDate = $this->postDate ?? ($entry->postDate ? $entry->postDate->format('Y-m-d H:i:s') : null);
        $this->expiryDate = $this->expiryDate ?? ($entry->expiryDate ? $entry->expiryDate->format('Y-m-d H:i:s') : null);
        $this->noPostDate = $this->noPostDate ?? !$entry->postDate;
        $this->noExpiryDate = $this->noExpiryDate ?? !$entry->expiryDate;

        if (!is_array($this->attachments)) {
            $this->attachments = [];
        }
    }
}
