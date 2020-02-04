<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use seibertio\elasticsearch\components\Document;
use seibertio\elasticsearch\components\EntryDocument;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\DocumentEvent;

/**
 * Index Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class IndexService extends Component
{   
    /**
	 * @param Entry $entry
	 * @param Index|null $index if not provided, the default index will be used
	 */
    public function indexEntry(Entry $entry, $index = null): void
    {
        $document = new EntryDocument($entry, $index);
        $this->indexDocument($document);
    }

    public function indexDocument(Document $document): void
    {
        // trigger event to allow collection of properties
        $event = new DocumentEvent();
        $document->trigger(DocumentEvent::EVENT_BEFORE_INDEX, $event);

        if ($event->isCanceled()) {
            return;
        }

        $index = $document->getIndex();

        $params = [
            'index' => $index->getName(),
            'id' => $document->getId(),
            'body' => $document->getProperties(),
        ];

        $event = new DocumentEvent(['params' => $params]);
        $index->trigger(DocumentEvent::EVENT_BEFORE_INDEX, $event);

        if ($event->isCanceled()) {
            return;
        }

        ElasticSearchPlugin::$plugin->client->get()->index($event->params);
    }

    /**
	 * @param Entry $entry
	 * @param Index|null $index if not provided, the default index will be used
	 */
    public function deleteEntry(Entry $entry, $index = null): void
    {
        $document = new EntryDocument($entry, $index);
        $this->deleteDocument($document);
    }

    public function deleteDocument(Document $document): void
    {
        $event = new DocumentEvent();
        $document->trigger(DocumentEvent::EVENT_BEFORE_DELETE, $event);
        if ($event->isCanceled()) {
            return;
        }

        $index = $document->getIndex();

        $params = [
            'index' => $index->getName(),
            'id' => $document->getId(),
        ];

        $event = new DocumentEvent(['params' => $params]);
        $index->trigger(DocumentEvent::EVENT_BEFORE_DELETE, $event);
        if ($event->isCanceled()) {
            return;
        }

        ElasticSearchPlugin::$plugin->client->get()->delete($params);
    }

}
