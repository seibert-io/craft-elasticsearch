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

    public function __construct(Entry $entry)
    {
		$this->entry = $entry;
        parent::__construct($entry->siteId . '-' . $entry->id, Index::getInstance($entry->site));

        $this->on(DocumentEvent::EVENT_BEFORE_INDEX, [$this, 'onBeforeIndex']);
	}
	
	public function onBeforeIndex () 
	{
		$entry = $this->entry;

		$seoDescription = isset($entry->seoDescription) ? $entry->seoDescription : null;
		$socialDescription = isset($entry->socialDescription) ? $entry->socialDescription : null;

		$this->title = $entry->title;
		$this->description = $seoDescription ?? $socialDescription;
		$this->url = $entry->url;
		$this->postDate = $entry->postDate ? $entry->postDate->format('Y-m-d H:i:s') : null;
		$this->noPostDate = $entry->postDate ? false : true;
		$this->expiryDate = $entry->expiryDate ? $entry->expiryDate->format('Y-m-d H:i:s') : null;
		$this->noExpiryDate = $entry->expiryDate ? false : true;
		
		if ($entry->url) {

			try {
				$url = ElasticSearchPlugin::$plugin->crawl->getFetchableEntryUrl($entry);
				$responseBody = ElasticSearchPlugin::$plugin->crawl->fetchURL($url);
				
				$content = trim($this->extractIndexableContent($responseBody));
				
				if ($content !== '') {
					$this->content = base64_encode($content);
				}
			} catch (ConnectException $e) {
				// Could not connect to host. This needs to be handled as this might be a configuration issue
				Craft::error('Could connect to host. Cannot fetch url \'' . $entry->url . '\': ' . $e->getMessage() . '. The app seems to not be able to resolve the hostname. This may e.g. happen in docker setups and http://localhost base URLs Consider setting config value fetchBaseUrl of this plugin.', __METHOD__);
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
