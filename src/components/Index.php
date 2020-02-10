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
		$this->searchResponseProcessor = array(self::className(), 'defaultSearchResponseProcessor');
		$this->suggestResponseProcessor = array(self::className(), 'defaultSuggestResponseProcessor');

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
		$stopwordList = ElasticSearchPlugin::$plugin->getSettings()->getStopWordFilter($this->site->handle);

		$this->settings = [
			'analysis' => [
				"analyzer" => [
					"language_stopwords" => [
						'type' => 'custom',
						'tokenizer' => 'standard',
						'filter' => ['lowercase', 'language_stopwords']
					],
					"ngram_analyzer" => [
						"tokenizer" => "ngram_tokenizer",
						'filter' => ['lowercase']
					],
					"edgengram_analyzer" => [
						"tokenizer" => "edge_ngram_tokenizer",
						'filter' => ['lowercase']
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
					],
					'edge_ngram_tokenizer' => [
						"type" => "edge_ngram",
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
					],
					'language_stopwords' => [
						'type' => 'stop',
						'stopwords' => $stopwordList
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
		$analyzer = ElasticSearchPlugin::$plugin->getSettings()->getLanguageAnalyzer($this->site->handle);

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
			'unusualSuggestions' => [
				'type' => 'text',
				'analyzer' => 'language_stopwords',
				'store' => true
			],
			'title' => [
				'type' => 'text',
				'analyzer' => $analyzer,
				'copy_to' => ['spellingSuggestions', 'unusualSuggestions'],
				'store' => true,
				//'index_phrases' => true,
				'fields' => [
					"ngram" => [
						'type' => 'text',
						'analyzer' => 'ngram_analyzer',
					],
					"edge_ngram" => [
						'type' => 'text',
						'analyzer' => 'edgengram_analyzer',
					]
				]
			],
			'description' => [
				'type' => 'text',
				'analyzer' => $analyzer,
				'copy_to' => ['spellingSuggestions', 'unusualSuggestions'],
				'store' => true,
				//'index_phrases' => true,
				'fields' => [
					"ngram" => [
						'type' => 'text',
						'analyzer' => 'ngram_analyzer',
					],
					"edge_ngram" => [
						'type' => 'text',
						'analyzer' => 'edgengram_analyzer',
					]
				]
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
						'copy_to' => ['spellingSuggestions', 'unusualSuggestions'],
						//'index_phrases' => true,
						'fields' => [
							"ngram" => [
								'type' => 'text',
								'analyzer' => 'ngram_analyzer',
							],
							"edge_ngram" => [
								'type' => 'text',
								'analyzer' => 'edgengram_analyzer',
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
		
		$queryString = strtolower($input['query']);

		return [
			'query' => [
				'bool' => [
					'must'   => [
						[
							'multi_match' => [
								'query'    => $queryString,
								'type' => 'cross_fields',
								'minimum_should_match' => '100%',
								'fields'   => ['title^6', 'title.edge_ngram^6', 'description^2', 'description.edge_ngram^2', 'attachment.content', 'attachment.content.edge_ngram', 'title.ngram^0.1', 'description.ngram^0.1', 'attachment.content.ngram^0.1'],
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
			],
			"highlight" => [
				"fields" => [
					"title.*" => [
						"number_of_fragments" => 0,
						"pre_tags" => ["<em>"], 
						"post_tags" => ["</em>"]
					],
					"description.*" => [
						"number_of_fragments" => 0,
						"pre_tags" => ["<em>"], 
						"post_tags" => ["</em>"]
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
		$queryString = strtolower($input['query']);
		$size = min(25, abs(isset($input['size']) ? (int) $input['size'] : 10));

		return [
			'query' => [
				'multi_match' => [
					'query'    => $queryString,
					'type' => 'cross_fields',
					'minimum_should_match' => '100%',
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
								"field" => "unusualSuggestions",
								//"min_doc_count" => 3,
								"filter_duplicate_text" => true,
								"source_fields" => ["attachment.content" , "title", "description"]
							] 
						 ] 
					  ] 
				] 
			 ] 
		];
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

	public static function defaultSearchResponseProcessor($input, $params, $response): array 
	{
		$createUrl = function ($params = []) use ($input): string {
			return UrlHelper::urlWithParams(UrlHelper::baseSiteUrl() . 'search', array_merge($input, $params));
		};
		
		$totalHits = $response['hits']['total']['value'];
		$numHitsInPage = sizeof($response['hits']['hits']);
		$highlightingRequested = array_key_exists('highlight', $input) &&  $input['highlight'] == 1;

		$mapHit = function($hit) use($highlightingRequested){
			$mappedHit = $hit['_source'];
			$mappedHit['id'] = $hit['_id'];
			$mappedHit['score'] = $hit['_score'];

			if ($highlightingRequested && array_key_exists('highlight', $hit)) {
				foreach ($hit['highlight'] as $highlightField => $value) {
					if ($highlightField === 'title' || strpos($highlightField, 'title.') === 0) {
						$mappedHit['title'] = $value[0];
					}
					if ($highlightField === 'description' || strpos($highlightField, 'description.') === 0) {
						$mappedHit['description'] = $value[0];
					}
				}
			}

			return $mappedHit;
		};

		$processedResponse = [
			'links' => [
				'self' => $createUrl()
			],
			'data' => [
				'totalHits' => $totalHits,
				'hits' => array_map($mapHit, $response['hits']['hits']),
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

	public static function defaultSuggestResponseProcessor($input, $params, $response): array 
	{
		$createUrl = function ($params = []) use ($input): string {
			return UrlHelper::urlWithParams(UrlHelper::baseSiteUrl() . 'search', array_merge($input, $params));
		};

		$queryString = strtolower(trim($input['query']));

		$phraseSuggestions = array_map(fn($option) => ['text' => $option['text'], 'score' => $option['_score'], 'type' => 'phrase'], $response['suggest']['phrases'][0]['options']);
		$spellingSuggestions = array_map(fn($suggestion) => ['text' => $suggestion['text'], 'score' => $suggestion['score'] * 100, 'type' => 'spelling'],$response['suggest']['spelling'][0]['options']);
		$significantTextSuggestions = array_map(fn($bucket) => ['text' => $bucket['key'], 'score' => $bucket['score'], 'type' => 'significant'], $response['aggregations']['bucket_sample']['keywords']['buckets']);
		
		// filter text suggestions that are already part of the input query
		$significantTextSuggestions = array_filter($significantTextSuggestions, fn($suggestion) => strpos($queryString, strtolower($suggestion['text'])) === false);
		
		// check if one of the significant suggestions allows autocompletion of the query
		$hasCompletionSignificantTextSuggestion = false;
		
		$wordsInQuery = explode(' ', $queryString);
		$lastwordInQuery = $wordsInQuery[sizeof($wordsInQuery) - 1];

		$filterForAutoCompletions = function($suggestion) use($lastwordInQuery) {
			return strpos(strtolower($suggestion['text']), strtolower($lastwordInQuery)) === 0;
		};

		// filter significant and spelling suggestions for texts suitable for auto completion
		$significantTextSuggestionAutoCompletions = array_filter($significantTextSuggestions, $filterForAutoCompletions);
		$spellingSuggestionsAutoCompletions = array_filter($spellingSuggestions, $filterForAutoCompletions);

		// if auto completions exist, use these instead of the whole set
		if (sizeof($significantTextSuggestionAutoCompletions)  > 0) {
			$significantTextSuggestions = $significantTextSuggestionAutoCompletions;
		}
		// if auto completions exist, use these instead of the whole set
		if (sizeof($spellingSuggestionsAutoCompletions)  > 0) {
			$spellingSuggestions = $spellingSuggestionsAutoCompletions;
		}

		// filter significant suggestions for texts suitable for auto completion
		$significantTextSuggestionAutoCompletions = array_filter($significantTextSuggestions, $filterForAutoCompletions);

		// if auto completions exist, use these instead of the whole set
		if (sizeof($significantTextSuggestionAutoCompletions)  > 0) {
			$significantTextSuggestions = $significantTextSuggestionAutoCompletions;
		}

		// build a complete query string suggestion by combining the query string and the suggestion
		$mapSignificantTextSuggestions = function($suggestion) use ($queryString) {
			// if suggestion starts with query return the suggestion as-is
			if (strpos(strtolower($suggestion['text']), $queryString) === 0) {
				return $suggestion;
			}
			
			// otherwise join query and suggestion to a new query string proposal
			// if the last word in the query is the start of a suggestion, repace it with the suggestion
			$wordsInQuery = explode(' ', $queryString);
			$lastwordInQuery = $wordsInQuery[sizeof($wordsInQuery) - 1];

			if (strpos(strtolower($suggestion['text']), strtolower($lastwordInQuery)) === 0) {
				$wordsInQuery[sizeof($wordsInQuery) - 1] = $suggestion['text'];
			} else {
				$wordsInQuery[] = $suggestion['text'];
			}

			$suggestion['text'] = join(' ', $wordsInQuery);

			return $suggestion;
		};

		$significantTextSuggestions = array_map($mapSignificantTextSuggestions, $significantTextSuggestions);
		
		// build suggestion list by joining phrase suggetsions and significant text suggestions
		$suggestions = array_merge($phraseSuggestions, $significantTextSuggestions);

		// if no suggestions were found, add spelling suggestions
		//if (sizeof($suggestions) === 0) {
			$suggestions = array_merge($suggestions, $spellingSuggestions);
		//}

		$wordsInQuery = explode(' ', $queryString);
		$highlightSuggestions = function($suggestion) use($wordsInQuery) {
			$wordsInSuggestion = explode(' ', $suggestion['text']);
			
			for ($i = 0; $i < sizeof($wordsInSuggestion); ++$i) {
				if (sizeof($wordsInQuery) < $i + 1 || $wordsInSuggestion[$i] != $wordsInQuery[$i]) {
					$wordsInSuggestion[$i] = '<em>' . $wordsInSuggestion[$i] . '</em>';
				}
			}

			$suggestion['highlight'] = join(' ', $wordsInSuggestion);

			return $suggestion;
		};

		$suggestions = array_map($highlightSuggestions, $suggestions);

		$processedResponse = [
			'links' => [
				'self' => $createUrl()
			],
			'data' => [
				'suggestions' => $suggestions,
			]
		];


		// sort suggestions by score descending
		$suggestionSort = function($suggestionA, $suggestionB) use($queryString) {
			$scoreA = round($suggestionA['score'] * 100000);
			$scoreB = round($suggestionB['score'] * 100000);
			
			
			// make sure prefix_matches are ranked first
			if (strpos(strtolower($suggestionA['text']), $queryString) === 0) $scoreA += 100000;
			if (strpos(strtolower($suggestionB['text']), $queryString) === 0) $scoreB += 100000;
			
			if ($scoreA === $scoreB) {
				$numWordsA = sizeof(explode(' ', $suggestionA['text']));
				$numWordsB = sizeof(explode(' ', $suggestionB['text']));
				if ($numWordsA === $numWordsB) {
					// sort alphabetically
					return strcasecmp($suggestionA['text'], $suggestionB['text']);
				}

				return $numWordsA - $numWordsB;
			}
			// sort descending
			return $scoreB - $scoreA;
		};

		usort($processedResponse['data']['suggestions'], $suggestionSort);

		// make suggestions unique
		$uniqueSuggestionTexts = [];
		foreach ($processedResponse['data']['suggestions'] as $index => $suggestion) {
			if (in_array($suggestion['text'], $uniqueSuggestionTexts)) {
				unset($processedResponse['data']['suggestions'][$index]);
				continue;
			}

			$uniqueSuggestionTexts[] = $suggestion['text'];
		}

		return $processedResponse;
	}

}
