<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use Craft;
use craft\base\Component;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use GuzzleHttp\Client;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\exceptions\MarkupExtractionException;

/**
 * Crawl Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class CrawlService extends Component
{
    /**
     * @param Entry $entry Entry to create a token for
     * @param string|null if null $fetchUrl $entry->getUrl() will be used
     */
    public function getTokenizedUrl(Entry $entry, $fetchUrl = null)
    {
        $token = Craft::$app->getTokens()->createToken([
            'preview/preview',
            [
                'elementType' => get_class($entry),
                'sourceId' => $entry->id,
                'siteId' => $entry->siteId,
                'draftId' => null,
                'revisionId' => null,
            ],
        ]);

        $client = new Client([
            'timeout' => 10.0,
        ]);

        // Generate the sharable url based on the previously generated token
        $url = UrlHelper::urlWithToken($fetchUrl ?? $entry->getUrl(), $token);

        // replace base url with the base url set in plugin config.
        // this is required for scenarios where the app may not resolve its own hostname and requires to use a different one to access itself
        // this happens e.g. in local docker environments where the app url is http://localhost as seen from the host machine
        $url = str_replace(UrlHelper::baseUrl(), ElasticSearchPlugin::$plugin->getSettings()->getFetchBaseUrl(), $url);

        return $url;
    }

    public function fetchURL($url)
    {
        $client = new Client([
            'connect_timeout' => 10,
        ]);

        $response = $client->request('GET', $url);
        return $response->getBody();
	}
	
	public function extractIndexableMarkup($markup): string {
		// search for page segments explicitly allowed to be indexed.
			// if none are found, assume the entire body may be indexed
			$allowRegex = '/<!--\s?search:allow\s?-->(.*)<!--\s?\/search:allow\s?-->/Us';
			if (preg_match_all($allowRegex, $markup, $matches, PREG_SET_ORDER, 0) > 0) {
				$matches = array_map(fn($match) => $match[1], $matches);
			} else {
				$bodyRegex = '/<body[^>]*>(.*)<\/body>/si';
				if (preg_match($bodyRegex, $markup, $matches, PREG_OFFSET_CAPTURE, 0) === 1) {
					$matches = [$matches[1][0]];
				}
				else {
					$message = 'Could not parse document - no HTML body found and no explicit search:allow comments exist';
					Craft::error($message, 'elasticsearch');
					throw new MarkupExtractionException($message);
				}
			}
			
			$content = '';

			foreach ($matches as $match) {
				$denyRegex = '/<!--\s?search:deny\s?-->(.*)<!--\s?\/search:deny\s?-->/Us';
				$indexableContent = preg_replace($denyRegex, '', $match);
				$content .= $indexableContent;
			}

			return $content;
	}
}
