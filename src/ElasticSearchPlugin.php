<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch;

use Craft;
use craft\elements\Entry;
use craft\events\ElementStructureEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Utilities;
use craft\web\UrlManager;
use seibertio\elasticsearch\jobs\TrackableJob;
use seibertio\elasticsearch\models\Settings;
use seibertio\elasticsearch\services\ClientService;
use seibertio\elasticsearch\services\CrawlService;
use seibertio\elasticsearch\services\EntryService;
use seibertio\elasticsearch\services\IndexManagementService;
use seibertio\elasticsearch\services\IndexService;
use seibertio\elasticsearch\services\QueryService;
use seibertio\elasticsearch\utilities\IndexUtility;
use yii\base\Event;
use yii\queue\ErrorEvent;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * Class ElasticSearchPlugin
 *
 * @property ClientService $client
 * @property IndexService $index
 * @property IndexManagementService $indexManagement
 * @property EntryService $entries
 * @property CrawlService $crawl
 * @property QueryService $query
 */
class ElasticSearchPlugin extends \craft\base\Plugin
{
	/**
	 * @var ElasticSearchPlugin
	 */
	public static ElasticSearchPlugin $plugin;

	/**
	 * @var string
	 */
	public $schemaVersion = '1.0.0';

	public function init()
	{
		parent::init();
		self::$plugin = $this;
		$this->name = $this->getName();

		$this->registerServices();
		$this->registerEventListeners();
		$this->registerRoutes();

		Craft::info('ElasticSearch plugin loaded');
	}

	/**
	 * Returns the user-facing name of the plugin, which can override the name
	 * in composer.json
	 *
	 * @return string
	 */
	public function getName(): string
	{
		return 'ElasticSearch';
	}

	protected function createSettingsModel()
	{
		return new Settings();
	}

	private function registerRoutes():void {
		// Register routes
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_SITE_URL_RULES,
			function (RegisterUrlRulesEvent $event) {
				$event->rules['search'] = 'craft-elasticsearch/query/search';
				$event->rules['suggest'] = 'craft-elasticsearch/query/suggest';
			}
		);
	}
	
	private function registerServices():void {
		// Publish services
		$this->setComponents([
			'client' => ClientService::class,
			'index' => IndexService::class,
			'indexManagement' => IndexManagementService::class,
			'entries' => EntryService::class,
			'crawl' => CrawlService::class,
			'query' => QueryService::class
		]);
	}

	private function registerEventListeners():void {	

		// Register the plugin's CP utility
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITY_TYPES,
			function (RegisterComponentTypesEvent $event) {
				$event->types[] = IndexUtility::class;
			}
		);
		
		Event::on(
			Entry::class,
			Entry::EVENT_AFTER_SAVE,
			function (ModelEvent $event) {
				if (!$event->sender) return;

				/** @var Entry */
				$entry = $event->sender;

				$this->entries->handleEntryUpdate($entry);
			}
		);

		Event::on(
			Entry::class,
			Entry::EVENT_AFTER_PROPAGATE,
			function (ModelEvent $event) {
				if (!$event->sender) return;

				/** @var Entry */
				$entry = $event->sender;

				$this->entries->handleEntryUpdate($entry);
			}
		);
		
		Event::on(
			Entry::class,
			Entry::EVENT_AFTER_DELETE,
			function (Event $event) {
				if (!$event->sender) return;

				/** @var Entry */
				$entry = $event->sender;

				$entry->enabled = false;
				$this->entries->handleEntryUpdate($entry);
			}
		);

		Event::on(Entry::class, Entry::EVENT_AFTER_MOVE_IN_STRUCTURE, function (ElementStructureEvent $event) {
			if (!$event->sender) return;

				/** @var Entry */
				$entry = $event->sender;

				$this->entries->handleEntryUpdate($entry);
        });


		Event::on(Queue::class,Queue::EVENT_AFTER_PUSH, function (PushEvent $event) {
			if ($event->job instanceof TrackableJob) {
				/** @var TrackableJob */
				$trackableJob = $event->job;
				$trackableJob->markQueued($event->id);
			}
		});

		Event::on(Queue::class,Queue::EVENT_AFTER_ERROR, function (ExecEvent $event) {
			if ($event->job instanceof TrackableJob) {
				if ($event->retry == false) {
					/** @var TrackableJob */
					$trackableJob = $event->job;
					$trackableJob->markCompleted();
				}
			}
		});

	}
}
