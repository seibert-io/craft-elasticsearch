<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use Elasticsearch\Client;
use Elasticsearch\ClientBuilder;
use Exception;
use seibertio\elasticsearch\ElasticSearchPlugin;

/**
 * Client Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class ClientService extends Component
{
    private $client;

    public function get(): Client
    {
        if (!$this->client) {
            $plugin = ElasticSearchPlugin::$plugin;

            $client = ClientBuilder::create() // Instantiate a new ClientBuilder
                ->setHosts($plugin->getSettings()->getHosts());

            $apiKeyId = $plugin->getSettings()->getApiKeyId();
            $apiKey = $plugin->getSettings()->getApiKey();

            if ($apiKeyId && $apiKey) {
                $client->setApiKey($plugin->getSettings()->getApiKeyId(), $plugin->getSettings()->getApiKey());
            }

            $this->client = $client->build();
        }

        return $this->client;
    }

    public function canConnect(): bool
    {
        try {
            $this->get()->info();
            return true;
        } catch (Exception $e) {
        }

        return false;
    }
}
