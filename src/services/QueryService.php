<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use seibertio\elasticsearch\components\Index;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\QueryEvent;
use yii\web\BadRequestHttpException;

/**
 * Query Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class QueryService extends Component
{

    public function search($input, Index $index): array
    {
        if (!isset($input['query'])) {
            throw new BadRequestHttpException('Missing query parameter');
        }

        $from = abs(isset($input['from']) ? (int) $input['from'] : 0);
        $size = min(25, max(1, abs(isset($input['size']) ? (int) $input['size'] : 10)));

        $event = new QueryEvent([
            'input' => $input,
            'query' => $index->getSearchQuery($input),
        ]);

        // allow query to be modified/extended via event listeners
        $index->trigger(QueryEvent::EVENT_BEFORE_QUERY, $event);

        $params = [
            'index' => $index->getName(),
            '_source_includes' => $index->sourceIncludes,
            'from' => $from,
            'size' => $size,
            'body' => $event->query,
        ];

        $response = ElasticSearchPlugin::$plugin->client->get()->search($params);
        $response = call_user_func($index->searchResponseProcessor, $input, $params, $response);

        return $response;
    }

    public function suggest($input, Index $index): array
    {
        if (!isset($input['query'])) {
            throw new BadRequestHttpException('Missing query parameter');
        }

        $event = new QueryEvent([
            'input' => $input,
            'query' => $index->getSuggestQuery($input),
        ]);

        // allow query to be modified/extended via event listeners
        $index->trigger(QueryEvent::EVENT_BEFORE_SUGGEST_QUERY, $event);

        $params = [
            'index' => $index->getName(),
            'stored_fields' => ['suggestions'],
            'size' => 0,
            'body' => $event->query,
        ];

        $response = ElasticSearchPlugin::$plugin->client->get()->search($params);
        $response = call_user_func($index->suggestResponseProcessor, $input, $params, $response);

        return $response;
    }
}
