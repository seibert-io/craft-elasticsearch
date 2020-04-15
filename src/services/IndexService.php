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
     * @return Document|false falseif cancelled
     */
    public function indexEntry(Entry $entry, $index = null)
    {
        $document = new EntryDocument($entry, $index);
        return $this->indexDocument($document);
    }

    /**
     * @param Document $document
     * @return Document|false falseif cancelled
     */
    public function indexDocument(Document $document)
    {
        // trigger event to allow collection of properties
        $event = new DocumentEvent();
        $document->trigger(DocumentEvent::EVENT_BEFORE_INDEX, $event);


        if ($event->isCanceled()) {
            return false;
        }

        $index = $document->getIndex();

        $params = [
            'index' => $index->getName(),
            'id' => $document->getId(),
            'body' => $document->getProperties(),
            'client' => [
                'timeout' => 5, // in seconds
                'connect_timeout' => 5 // in seconds
            ]
        ];

        $event = new DocumentEvent(['params' => $params]);
        $index->trigger(DocumentEvent::EVENT_BEFORE_INDEX, $event);

        if ($event->isCanceled()) {
            return false;
        }

        ElasticSearchPlugin::$plugin->client->get()->index($event->params);

        return $document;
    }

    /**
     * @param Entry $entry
     * @param Index|null $index if not provided, the default index will be used
     */
    public function deleteEntry(Entry $entry, $index = null): bool
    {
        $document = new EntryDocument($entry, $index);
        return $this->deleteDocument($document);
    }

    public function deleteDocument(Document $document): bool
    {
        $event = new DocumentEvent();
        $document->trigger(DocumentEvent::EVENT_BEFORE_DELETE, $event);
        if ($event->isCanceled()) {
            return false;
        }

        $index = $document->getIndex();

        $params = [
            'index' => $index->getName(),
            'id' => $document->getId(),
            'client' => [
                'timeout' => 5, // in seconds
                'connect_timeout' => 5 // in seconds
            ]
        ];

        $event = new DocumentEvent(['params' => $params]);
        $index->trigger(DocumentEvent::EVENT_BEFORE_DELETE, $event);
        if ($event->isCanceled()) {
            return false;
        }

        ElasticSearchPlugin::$plugin->client->get()->delete($params);
        return true;
    }

    public function getDocumentIDs($index): array
    {
        $params = [
            'index' => $index->getName(),
            'size' => 250,
            'scroll' => "20s",
            '_source_includes' => ['id'],
            'stored_fields' => [],
            'body' => ['query' => ['match_all' => new \stdClass()]],
            'client' => [
                'timeout' => 5, // in seconds
                'connect_timeout' => 5 // in seconds
            ]
        ];

        $response = ElasticSearchPlugin::$plugin->client->get()->search($params);
        $ids = array_map(fn ($hit) => $hit['_id'], $response['hits']['hits']);
        $totalDocuments = $response['hits']['total']['value'];

        while (sizeof($ids) < $totalDocuments && sizeof($response['hits']['hits']) > 0) {
            $params = [
                'scroll_id' => $response['_scroll_id'],
                'scroll' => '20s'
            ];

            $response = ElasticSearchPlugin::$plugin->client->get()->scroll($params);
            $ids = array_merge($ids, array_map(fn ($hit) => $hit['_id'], $response['hits']['hits']));
        }

        return $ids;
    }
}
