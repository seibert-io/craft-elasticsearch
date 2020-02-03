<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\models\Site;
use Elasticsearch\Common\Exceptions\BadRequest400Exception;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\IndexEvent;
use seibertio\elasticsearch\exceptions\IndexConfigurationException;

/**
 * Index Management Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class IndexManagementService extends Component
{
    public function getSiteIndex(Site $site): Index
    {
        return Index::getInstance($site);
    }

    public function createIndex(Index $index): void
    {
        try {
            $index->trigger(IndexEvent::EVENT_BEFORE_CREATE, new IndexEvent());

            $params = [
                'index' => $index->getName(),
                'body' => [
                    'mappings' => $index->getMappings(),
                ],
            ];

            // settings must only be provided to ES if not empty
            $settings = $index->getSettings();

            if (sizeof($settings) > 0) {
                $params['body']['settings'] = $settings;
            }

            Craft::info('Creating index \'' . $index->getName() . '\'');

            ElasticSearchPlugin::$plugin->client->get()->indices()->create($params);

            $index->trigger(IndexEvent::EVENT_AFTER_CREATE, new IndexEvent());
        } catch (BadRequest400Exception $e) {
            $message = 'Error during index configuration. Either the provided settings/mappings are invalid or the settings/mappings cannot be updated and you need to recreate the index and reindex all documents to adapt.' . $e->getMessage();
            Craft::error($message, 'elasticssearch');
            throw new IndexConfigurationException($message, 0, $e);
        }
    }

    public function getIndexDocumentCount(Index $index): int
    {
        $response = ElasticSearchPlugin::$plugin->client->get()->count(['index' => $index->getName()]);
        return $response['count'];
    }

    public function deleteIndex(Index $index): void
    {
        $params = [
            'index' => $index->getName(),
        ];

        ElasticSearchPlugin::$plugin->client->get()->indices()->delete($params);

        $index->trigger(IndexEvent::EVENT_AFTER_DELETE, new IndexEvent());
    }
}
