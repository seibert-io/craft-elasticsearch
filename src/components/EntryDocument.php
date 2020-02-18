<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\components;

use Craft;
use craft\elements\Entry;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ServerException;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\DocumentEvent;
use seibertio\elasticsearch\exceptions\MarkupExtractionException;

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
    }

    public function onBeforeIndex()
    {
        $entry = $this->entry;

        $this->title = $this->title ?? $entry->title;
        $this->url = $this->url ?? $entry->url;
        $this->postDate = $this->postDate ?? ($entry->postDate ? $entry->postDate->format('Y-m-d H:i:s') : null);
        $this->expiryDate = $this->expiryDate ?? ($entry->expiryDate ? $entry->expiryDate->format('Y-m-d H:i:s') : null);
        $this->noPostDate = $this->noPostDate ?? !$entry->postDate;
        $this->noExpiryDate = $this->noExpiryDate ?? !$entry->expiryDate;
        if (!is_array($this->attachments)) $this->attachments = [];

        if ($entry->url) {

            try {
                $url = ElasticSearchPlugin::$plugin->crawl->getFetchableEntryUrl($entry);
                $responseBody = ElasticSearchPlugin::$plugin->crawl->fetchURL($url);

                $content = trim($this->extractIndexableContent($responseBody));

                if ($content !== '') {
                    $this->attachments = array_merge($this->attachments, [['input' => base64_encode('<div>' . $content . '</div>')]]);
                }
            } catch (ConnectException $e) {
                // Could not connect to host. This needs to be handled as this might be a configuration issue
                Craft::error('Could not connect to host. Cannot fetch url \'' . $entry->url . '\': ' . $e->getMessage() . '. The app seems to not be able to resolve the hostname. This may e.g. happen in docker setups and http://localhost base URLs Consider setting config value fetchBaseUrl of this plugin.', __METHOD__);
                throw $e;
            } catch (ServerException $e) {
                // We can't fetch the page due to an error during page rendering - not in the responsibility of this plugin
                Craft::error('Server error. Cannot fetch url \'' . $entry->url . '\': ' . $e->getMessage(), __METHOD__);
            } catch (RequestException $e) {
                Craft::error('Request error. Cannot fetch url ' . $entry->url . ': ' . $e->getMessage(), __METHOD__);
                throw $e;
            } catch (MarkupExtractionException $e) {
                Craft::error('Markup extraction error. Cannot parse markup in response body of url ' . $entry->url . ': ' . $e->getMessage(), __METHOD__);
                throw $e;
            } catch (\Exception $e) {
                throw new \Exception('Unknown error. Cannot fetch or parse url ' . $entry->url . ':' . $e->getMessage(), 0, $e);
            }
        }
    }
}
