<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\utilities;

use Craft;
use craft\base\Utility;
use craft\helpers\ArrayHelper;
use Elasticsearch\Common\Exceptions\Missing404Exception;
use seibertio\elasticsearch\ElasticSearchPlugin;
use seibertio\elasticsearch\jobs\IndexSiteJob;
use seibertio\elasticsearch\jobs\ReIndexSiteJob;

class IndexUtility extends Utility
{
    /**
     * Returns the display name of this utility.
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return 'Elasticsearch';
    }

    /**
     * Returns the utility’s unique identifier.
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     *
     * @return string
     */
    public static function id(): string
    {
        return 'craft-elasticsearch';
    }

    /**
     * Returns the path to the utility's SVG icon.
     * @return string|null The path to the utility SVG icon
     */
    public static function iconPath()
    {
        return Craft::getAlias('@seibertio/elasticsearch/icon.svg');
    }

    /**
     * Returns the number that should be shown in the utility’s nav item badge.
     * If `0` is returned, no badge will be shown
     * @return int
     */
    public static function badgeCount(): int
    {
        return 0;
    }

    /**
     * Returns the utility's content HTML.
     * @return string
     * @throws \Twig_Error_Loader
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     */
    public static function contentHtml(): string
    {
        $canConnect = ElasticSearchPlugin::$plugin->client->canConnect();
        $sites = ArrayHelper::map(Craft::$app->sites->getAllSites(), 'id', 'name');
        $insights = [];

        foreach (Craft::$app->sites->getAllSites() as $site) {

            $index = ElasticSearchPlugin::$plugin->indexManagement->getSiteIndex($site);

            $comments = [];

            $documentCount = 0;

            try {
                $documentCount = ElasticSearchPlugin::$plugin->indexManagement->getIndexDocumentCount($index);
            } catch (Missing404Exception $e) {
                $comments[] = 'Warning: index does not exist. Consider rebuilding.';
            }

            // create job stubs just to retrieve the job info. it won't be added to the queue
            $reIndexjob = new ReIndexSiteJob(['siteId' => $site->id]);
            $indexjob = new IndexSiteJob(['siteId' => $site->id]);

            if ($reIndexjob->getProgress() > 0) {
                $comments[] = 'Re-indexing in progress (' . round($reIndexjob->getProgress() * 100) . '%)';
            }

            if ($indexjob->getProgress() > 0) {
                $comments[] = 'Indexing in progress (' . round($indexjob->getProgress() * 100) . '%)';
            }

            $insights[$site->id] = [
                'id' => $site->id,
                'name' => $site->name,
                'documentCount' => $documentCount,
                'comments' => join('\n', $comments),
            ];
        }

        return Craft::$app->getView()->renderTemplate(
            'craft-elasticsearch/utility',
            [
                'canConnect' => $canConnect,
                'sites' => $sites,
                'insights' => $insights,
            ]
        );
    }
}
