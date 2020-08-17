<?php
/**
 * @author David Seibert<david@seibert.io>
 */
namespace seibertio\elasticsearch\components;

use Craft;
use Exception;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\exceptions\MarkupExtractionException;
use yii\base\Component;

/**
 * A document  that is indexed to an ElasticSearch index
 * 
 * Default behavior may be altered in listeners on the following events:
 * 
 * - DocumentEvent  
 *   DocumentEvent::EVENT_BEFORE_INDEX - before a document is indexed. allows modifications  - e.g. to set a pipeline (invoke $event->cancel() to prevent indexing)
 *   DocumentEvent::EVENT_BEFORE_DELETE - before a document is deleted from the index (invoke $event->cancel() to prevent deletion)
 * 
 */
class Document extends Component
{
	/**
	 * ElasticSearch Index this document will be saved into
	 */
	private Index $index;
	
	private string $id;
	
	/**
	 * Keys of all properties that may be set in the document
	 * @property string[]
	 */
	public $attributes = [];

	/**
	 * Dictionary of all properties to store in ElasticSearch
	 */
	private $properties = [];

	/**
	 * Set to a differenc function in an event listener on DocumentEvent::EVENT_BEFORE_INDEX
	 * to alter extraction of properties the entries url. You may even set $event->handled = true to a prepended(!)
	 * event listener in order to avoid fetching and parsing the entry URL entirely.
	 * 
	 * If overriden, the signature of the callback must be `($responseBody string): string`
	 * Default: ElasticSearchPlugin::$plugin->crawl, 'extractIndexableMarkup'
	 */
	public $processDocumentCallback;


	public function __construct(string $id, Index $index)
	{
	    parent::__construct([]);
		$this->id = $id;
		$this->index = $index;
		$this->processDocumentCallback = [ElasticSearchPlugin::$plugin->crawl, 'extractIndexableMarkup'];
		$this->attributes = array_keys($this->index->getProperties());

		// initialize associative properties array to null values
		$this->properties = array_fill_keys($this->attributes, null);		
	}

	public function getIndex(): Index {
		return $this->index;
	}

	public function __get($key) {
		if (!array_key_exists($key, $this->properties)) {
			$message = 'Trying to access unregistered attribute \"' . $key . '\"';
			Craft::error($message, 'elasticsearch');
			throw new Exception($message);
		}

		return $this->properties[$key];
	}

	public function __set($key, $value) 
    {
		// force registration of all custom properties during init event
		if (!array_key_exists($key, $this->properties)) {
			throw new Exception('Trying to set unregistered attribute \"' . $key . '\"');
		}
		
		$this->properties[$key] = $value;
	}

    /**
     * @param $html
     * @return string
     * @throws Exception
     * @throws MarkupExtractionException
     */
	public function extractIndexableContent($html): string {
		if (!is_callable($this->processDocumentCallback)) {
			$message = '$processDocumentCallback is not callable';
			Craft::error($message, 'elasticsearch');
			throw new Exception($message);
		}
		return call_user_func($this->processDocumentCallback, $html);
	}

	public function getAttributes()
    {
		return $this->attributes;
	}

	public function getId()
    {
		return $this->id;
	}

    public function getProperties()
    {
		return $this->properties;
	}
}
