<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\components;

use Craft;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\models\Site;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\events\DocumentEvent;
use seibertio\elasticsearch\events\IndexEvent;
use yii\base\Component;

/**
 * An ElasticSearch index that containes documents
 * 
 * Default behavior may be altered in listeners on the following events:
 * 
 * - IndexEvent
 *   IndexEvent::EVENT_INIT - allows registration of custom properties
 *   IndexEvent::EVENT_BEFORE_CREATE - allows to perform operations on ELasticSearch, e.g. creating pipelines (set $event->handled = true to prevent default pipeline)
 *   IndexEvent::EVENT_AFTER_CREATE 
 *   IndexEvent::EVENT_AFTER_DELETE 
 * 
 * - DocumentEvent  
 *   DocumentEvent::EVENT_BEFORE_INDEX - before a document is indexed. allows modifications to the document before it is indexed - e.g. add properties (invoke $event->cancel() to prevent indexing)
 *   DocumentEvent::EVENT_BEFORE_DELETE - before a document is deleted from the index (invoke $event->cancel() to prevent deletion)
 * 
 */
class Index extends Component
{
	public Site $site;
	
	public string $type;
	
	public array $settings = [];
	
	public array $sourceIncludes = [];

	/**
	 * Optional search response processor callback. Signature:
	 * ($input, $params, $response) => array
	 */
	public $searchResponseProcessor;
	
	/**
	 * Optional suggest response processor callback. Signature:
	 * ($input, $params, $response) => array
	 */
	public $suggestResponseProcessor;

	private array $properties = [];
	
	private string $name;

	private static $instancesBySiteId = [];
	
	public static function getInstance(Site $site): Index {
		$siteId = (string) $site->id;

		if (!array_key_exists($siteId, self::$instancesBySiteId)) {
			self::$instancesBySiteId[$siteId] = new self($site);
		}

		return self::$instancesBySiteId[$siteId];
	}

	private function __construct(Site $site)
	{
		$this->site = $site;
		$this->setName('site-' . $this->site->id . '-default');
		$this->type = 'entry';
		$this->searchResponseProcessor = array($this, 'defaultSearchResponseProcessor');
		$this->suggestResponseProcessor = array($this, 'defaultSuggestResponseProcessor');

		$this->initSettings();
		$this->initProperties();
		$this->initSourceIncludes();

		$this->trigger(IndexEvent::EVENT_INIT, new IndexEvent());

		$this->on(IndexEvent::EVENT_BEFORE_CREATE, [$this, 'onBeforeCreateIndex']);
		$this->on(DocumentEvent::EVENT_BEFORE_INDEX, [$this, 'onBeforeIndexDocument']);
	}

	public function setName($name): void 
	{
		$this->name = Craft::$app->env . '-' . $name;
	}

	public function getName(): string 
	{
		return $this->name;
	}

	public function initSettings(): void 
	{
		$this->settings = [
			'analysis' => [
				"analyzer" => [
					"ngram_analyzer" => [
						"tokenizer" => "ngram_tokenizer"
					],
					'spelling_correction_ngram' => [
						'tokenizer' => 'ngram_tokenizer',
						'filter' => ['shingle', 'lowercase']
					],
					'spelling_correction_trigram' => [
						'type' => 'custom',
						'tokenizer' => 'standard',
						'filter' => ['shingle', 'lowercase']
					],
					'spelling_correction_reverse' => [
						'type' => 'custom',
						'tokenizer' => 'standard',
						'filter' => ['reverse', 'lowercase']
					],
					'completion_analyzer' => [
						'type' => 'custom',
						'filter' => ['lowercase', 'completion_filter'],
						'tokenizer' => 'ngram_tokenizer',
					]
				],
				'tokenizer' => [
					'ngram_tokenizer' => [
						"type" => "ngram",
						"min_gram" => 3,
						"max_gram" => 3,
						"token_chars" => [
							"letter",
							"digit"
						]
					]
				],
				'filter' => [
					'completion_filter' => [
						'type' => 'edge_ngram',
						'min_gram' => 1,
						'max_gram' => 24,
					]
				]

			],
		];
	}

	/**
	 * Register a new property that should be indexed and hence may be set in documents using this index.
	 * 
	 * The index supports spelling suggestions and phrase suggestions. 
	 * If you'd like them to include the value of a custom registered field, add
	 * 'copy_to' => 'phraseSuggestions', 'copy_to' => 'spellingSuggetsions' or 'copy_to' => ['phraseSuggestions', 'spellingSuggestions']
	 * 
	 * @see https://www.elastic.co/guide/en/elasticsearch/reference/current/mapping.html
	 * 
	 */
	public function registerProperty($name, $configuration): void 
	{
		if (array_key_exists($name, $this->properties)) {
			$message = 'Trying to overwrite existing type property \"' . $name . '\" in index \"' . $this->getName() . '\"';
			Craft::error($message, 'elasticsearch');
			throw new \Exception($message);
		}

		$this->properties[$name] = $configuration;
	}

	public function initSourceIncludes(): void 
	{
		$this->sourceIncludes = [
			'title',
			'description',
			'url',
			'imageUrl',
		];
	}

	public function initProperties(): void 
	{
		$analyzer = ElasticSearchPlugin::$plugin->getSettings()->getLanguageAnalyzer();

		$this->properties = [
			'postDate'      => [
				'type'   => 'date',
				'format' => 'yyyy-MM-dd HH:mm:ss',
			],
			'noPostDate'    => [
				'type'  => 'boolean',
				"null_value" => true
			],
			'expiryDate'    => [
				'type'   => 'date',
				'format' => 'yyyy-MM-dd HH:mm:ss',
			],
			'noExpiryDate'  => [
				'type'  => 'boolean',
				"null_value" => true
			],
			'phraseSuggestions' => [
				'type' => 'completion',
				"analyzer" => "completion_analyzer",
				"search_analyzer" => "standard",
				"copy_to" => 'spellingSuggestions',
			],
			'spellingSuggestions' => [
				'type' => 'text',
				'analyzer' => $analyzer,
				'store' => true,
				'fields' => [
					'trigram' => [
						'type' => 'text',
						'analyzer' => 'spelling_correction_trigram'
					],
					'ngram' => [
						'type' => 'text',
						'analyzer' => 'spelling_correction_ngram'
					],
					'reverse' => [
						'type' => 'text',
						'analyzer' => 'spelling_correction_reverse'
					]
				]
			],
			'title' => [
				'type' => 'text',
				'analyzer' => $analyzer,
				'copy_to' => 'spellingSuggestions',
				'store' => true,
				//'index_phrases' => true,
				'fields' => [
					"ngram" => [
						'type' => 'text',
						'analyzer' => 'ngram_analyzer',
					]
				]
			],
			'description' => [
				'type' => 'text',
				'analyzer' => $analyzer,
				'copy_to' => 'spellingSuggestions',
				'store' => true,
				//'index_phrases' => true,
				'fields' => [
					"ngram" => [
						'type' => 'text',
						'analyzer' => 'ngram_analyzer',
					]
				]
			],
			'imageUrl' => [
				'type' => 'keyword',
			],
			'url' => [
				'type' => 'text',
			],
			'content' => [
				'type' => 'text',
				'analyzer' => $analyzer,
			],
			'attachment' => [
				'properties' => [
					'content' => [
						'type' => 'text',
						'analyzer' => $analyzer,
						'copy_to' => 'spellingSuggestions',
						//'index_phrases' => true,
						'fields' => [
							"ngram" => [
								'type' => 'text',
								'analyzer' => 'ngram_analyzer',
							]
						]
					],
				],
			],
		];
	}

	public function getType(): string 
	{
		return $this->type;
	}

	public function getSettings(): array 
	{
		return $this->settings;
	}

	public function getProperties(): array 
	{
		return $this->properties;
	}

	public function getMappings(): array 
	{
		return [
			'properties' => $this->properties,
		];
	}

	/**
     * @param $input
     * @return array
     */
    public function getSearchQuery($input): array
    {
		$currentTimeDb = Db::prepareDateForDb(new \DateTime());
		
		$queryString = $input['query'];

		return [
			'query' => [
				'bool' => [
					'must'   => [
						[
							'multi_match' => [
								'query'    => $queryString,
								'type' => 'cross_fields',
								'minimum_should_match' => '50%',
								'fields'   => ['title^6', 'title.*^6', 'description^2', 'description.*^2', 'attachment.content', 'attachment.content.*'],
							]
						]
					],
					'filter' => [
						'bool' => [
							'must' => [
								[
									'range' => [
										'postDate' => [
											'lte' => $currentTimeDb
										]
									],
								],
								[
									'bool' => [
										'should' => [
											[
												'range' => [
													'expiryDate' => [
														'gt' => $currentTimeDb
													]
												]
											],
											[
												'term' => [
													'noExpiryDate' => true
												]
											]
										]
									]
								]

							]
						]
					]
				]
			]
		];
	}

	/**
     * @param $input
     * @return array
     */
    public function getSuggestQuery($input): array
    {
		$queryString = $input['query'];
		$size = min(25, abs(isset($input['size']) ? (int) $input['size'] : 10));

		return [
			'query' => [
				'multi_match' => [
					'query'    => $queryString,
					'type' => 'cross_fields',
					'minimum_should_match' => '50%',
					'fields'   => ['title^6', 'title.*^6', 'description^2', 'description.*^2', 'attachment.content', 'attachment.content.*'],
				]
			],
			'suggest' => [
				'phrases' => [
					'text' => $queryString,
					'completion' => [
						'field' => 'phraseSuggestions',
						'skip_duplicates' => true,
						'size' => $size,
						
					],
				],
				'spelling' => [
					'text' => $queryString,
					'phrase' => [
						'field' => 'spellingSuggestions.trigram',
						'size' => $size,
						'max_errors' => 1,
						'gram_size' => 4,
						'direct_generator' => [
							[
								'field' => 'spellingSuggestions.trigram',
								'min_word_length' => 2,
								'suggest_mode' => 'always',
							],
							[
								'field' => 'spellingSuggestions.ngram',
								'min_word_length' => 2,
								'suggest_mode' => 'always',
							],
							[
								'field' => 'spellingSuggestions.reverse',
								'min_word_length' => 2,
								'suggest_mode' => 'always',
								'pre_filter' => 'spelling_correction_reverse',
								'post_filter' => 'spelling_correction_reverse',
							],
						],
					]
				],
			],
			"aggregations" => [
				"bucket_sample" => [
				   "sampler" => [
					  "shard_size" => 25,
					], 
					"aggregations" => [
						"keywords" => [
							"significant_text" => [
								"field" => "spellingSuggestions",
								"filter_duplicate_text" => true,
							] 
						 ] 
					  ] 
				] 
			 ] 
		];
		// TODO: test and process aggregation buckets
	}

	public function onBeforeIndexDocument(DocumentEvent $event): void 
	{
		$event->params['pipeline'] = 'attachment';
	}

	public function onBeforeCreateIndex(): void 
	{
		try {
            ElasticSearchPlugin::$plugin->client->get()->ingest()->getPipeline(['id' => 'attachment']);
        } catch (Missing404Exception $e) {
            Craft::info('Creating pipeline \'attachment\'');

            $this->getClient()->ingest()->putPipeline([
                'id' => 'attachment',
                'body' => [
                    'description' => 'my attachment ingest processor',
                    'processors' => [
                        [
                            'attachment' => [
                                'field' => 'content',
                                'target_field' => 'attachment',
                                'indexed_chars' => -1,
                                'ignore_missing' => true,
                            ],
                            'remove' => [
                                'field' => 'content',
                            ],
                        ],
                    ],
                ],
            ]);
        }
	}

	public function defaultSearchResponseProcessor($input, $params, $response): array 
	{
		$createUrl = function ($params = []) use ($input): string {
			return UrlHelper::urlWithParams(UrlHelper::baseSiteUrl() . 'search', array_merge($input, $params));
		};
		
		$totalHits = $response['hits']['total']['value'];
		$numHitsInPage = sizeof($response['hits']['hits']);

		$processedResponse = [
			'links' => [
				'self' => $createUrl()
			],
			'data' => [
				'totalHits' => $totalHits,
				'hits' => array_map(fn($hit) => $hit['_source'], $response['hits']['hits']),
			]
		];

		// add next link
		if (($params['from'] + $numHitsInPage) < $totalHits) {
			$processedResponse['links']['next'] = $createUrl([
				'from' => $params['from'] + $params['size'],
				'size' => $params['size'],
			]);
		}

		// add last link
		if ($params['from'] > 0) {
			$processedResponse['links']['last'] = $createUrl([
				'from' => max(0, $params['from'] - $params['size']),
				'size' => $params['size'],
			]);
		}

		return $processedResponse;
	}

	public function defaultSuggestResponseProcessor($input, $params, $response): array 
	{
		$createUrl = function ($params = []) use ($input): string {
			return UrlHelper::urlWithParams(UrlHelper::baseSiteUrl() . 'search', array_merge($input, $params));
		};

		$phreaseSuggestions = array_map(fn($option) => ['text' => $option['text'], 'score' => $option['_score']], $response['suggest']['phrases'][0]['options']);
		$spellingSuggestions = $response['suggest']['spelling'][0]['options'];
		$significantTextSuggestions = array_map(fn($bucket) => ['text' => $bucket['key'], 'score' => $bucket['score']], $response['aggregations']['bucket_sample']['keywords']['buckets']);
		
		// filter text suggestions that are already part of the input query
		$significantTextSuggestions = array_filter($significantTextSuggestions, fn($suggestion) => strpos($input['query'], $suggestion['text']) === false);
		
		// build a complete query string suggestion by combining the query string and the suggestion
		$mapSignificantTextSuggestions = function($suggestion)use ($input) {
			// if suggestion starts with query return the suggestion as-is
			if (strpos($suggestion['text'], $input['query']) === 0) {
				return $suggestion;
			}

			// otherwise join query and suggestion to a new query string proposal
			$suggestion['text'] = $input['query'] . ' ' . $suggestion['text'];

			return $suggestion;
		};

		$significantTextSuggestions = array_map($mapSignificantTextSuggestions, $significantTextSuggestions);
		
		// build suggestion list by joining phrase suggetsions, significant text suggestions and spelling suggestions
		$suggestions = array_merge($phreaseSuggestions, $significantTextSuggestions, $spellingSuggestions);

		$processedResponse = [
			'links' => [
				'self' => $createUrl()
			],
			'data' => [
				'suggestions' => $suggestions,
			]
		];

		// sort suggestions by score descending
		$suggestionSort = function($suggestionA, $suggestionB) use($input) {
			$scoreA = round($suggestionA['score'] * 100000);
			$scoreB = round($suggestionB['score'] * 100000);
			
			// make sure prefix_matches are ranked first
			if (strpos(strtolower($suggestionA['text']), strtolower($input['query'])) === 0) $scoreA += 100000;
			if (strpos(strtolower($suggestionB['text']), strtolower($input['query'])) === 0) $scoreB += 100000;

			// sort descending
			return $scoreB - $scoreA;
		};

		usort($processedResponse['data']['suggestions'], $suggestionSort);

		return $processedResponse;
	}

}