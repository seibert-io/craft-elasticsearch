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
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\TransferStats;
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

    private Client $client;

    public function init()
    {
        parent::init();
        $this->initClient([
            'read_timeout' => 30,
        ]);
    }

    public function initClient(array $config = [])
    {
        $this->client = new Client($config);
    }

    public function getClient(): Client
    {
        return $this->client;
    }

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

        // Generate the sharable url based on the previously generated token
        $url = UrlHelper::urlWithToken($fetchUrl ?? $entry->getUrl(), $token);

        $url = $this->replaceBaseUrl($url);

        return $url;
    }

    public function fetchURL($url)
    {
        $redirectedUrl = $url;
        try {
            $response = $this->getClient()->request('GET', $url, [
                'on_stats' => function (TransferStats $stats) use (&$redirectedUrl) {
                    // follow redirects
                    $redirectedUrl = $this->replaceBaseUrl((string) $stats->getEffectiveUri());
                }
            ]);
            return $response->getBody();
        } catch (ConnectException $e) {
            if ($redirectedUrl !== $url) return $this->fetchURL($redirectedUrl);
            throw $e;
        }
    }

    public function extractIndexableMarkup($markup): string
    {
        // search for page segments explicitly allowed to be indexed.
        // if none are found, assume the entire body may be indexed
        $allowRegex = '/<!--\s?search:allow\s?-->(.*)<!--\s?\/search:allow\s?-->/Us';
        if (preg_match_all($allowRegex, $markup, $matches, PREG_SET_ORDER, 0) > 0) {
            $matches = array_map(fn ($match) => $match[1], $matches);
        } else {
            $bodyRegex = '/<body[^>]*>(.*)<\/body>/si';
            if (preg_match($bodyRegex, $markup, $matches, PREG_OFFSET_CAPTURE, 0) === 1) {
                $matches = [$matches[1][0]];
            } else {
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

    private function replaceBaseUrl($url): string
    {
        // replace base url with the base url set in plugin config.
        // this is required for scenarios where the app may not resolve its own hostname and requires to use a different one to access itself
        // this happens e.g. in local docker environments where the app url is http://localhost as seen from the host machine
        $fetchBaseUrl = ElasticSearchPlugin::$plugin->getSettings()->getFetchBaseUrl();
        if (empty($fetchBaseUrl)) return $url;
        return preg_replace('/https?:\/\/[^\/]+(.*)/', $fetchBaseUrl . '$1', $url);
    }
}
