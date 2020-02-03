<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\models;

use Craft;
use craft\base\Model;
use craft\helpers\ConfigHelper;
use craft\helpers\UrlHelper;

class Settings extends Model
{
    /**
     * @property string[]
     */
    public $hosts;

    public $apiKeyId;

    public $apiKey;

    public $fetchBaseUrl;

    public $autoIndexEntries;

    public $languageAnalyzer;

    public function getHosts($siteHandle = null): array
    {
        return ConfigHelper::localizedValue($this->hosts, $siteHandle);
    }

    public function getApiKeyId($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->apiKeyId, $siteHandle);
    }

    public function getApiKey($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->apiKey, $siteHandle);
    }

    public function getFetchBaseUrl($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->fetchBaseUrl, $siteHandle) ?? UrlHelper::baseSiteUrl();
    }

    public function getAutoIndexEntries($siteHandle = null): bool
    {
        return (bool) ConfigHelper::localizedValue($this->autoIndexEntries, $siteHandle);
    }

    public function getLanguageAnalyzer($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->languageAnalyzer, $siteHandle) ?? $this->getSiteLanguageAnalyzer($siteHandle);
    }

    /**
     * Get ElasticSearch analyzer for the current site language
     *
     * @return string
     */
    private function getSiteLanguageAnalyzer($siteHandle = null): string
    {
        if ($siteHandle === null) {
            $siteHandle = Craft::$app->getSites()->getCurrentSite()->handle;
        }

        $site = Craft::$app->sites->getSiteByHandle($siteHandle);
        $siteLanguage = $site->language;

        $languageToElasticSearchAnalyzerMap = [
            'ar' => 'arabic',
            'bn' => 'bengali',
            'bg' => 'bulgarian',
            'ca' => 'catalan',
            'cs' => 'czech',
            'da' => 'danish',
            'de' => 'german',
            'en' => 'english',
            'el' => 'greek',
            'es' => 'spanish',
            'eu' => 'basque',
            'fa' => 'persian',
            'fi' => 'finnish',
            'fr' => 'french',
            'ga' => 'irish',
            'gl' => 'galician',
            'hi' => 'hindi',
            'hu' => 'hungarian',
            'hy' => 'armenian',
            'id' => 'indonesian',
            'it' => 'italian',
            'ja' => 'cjk',
            'ko' => 'cjk',
            'lt' => 'lithuanian',
            'lv' => 'latvian',
            'nb' => 'norwegian',
            'nl' => 'dutch',
            'pl' => 'stempel',
            'pt-BR' => 'brazilian',
            'pt' => 'portuguese',
            'ro' => 'romanian',
            'ru' => 'russian',
            'sv' => 'swedish',
            'tr' => 'turkish',
            'th' => 'thai',
            'zh' => 'cjk',
        ];

        $analyzer = 'standard';

        if (array_key_exists($siteLanguage, $languageToElasticSearchAnalyzerMap)) {
            $analyzer = $languageToElasticSearchAnalyzerMap[$siteLanguage];
        } else {
            $localParts = explode('-', $siteLanguage);
            $siteLanguagePart = $localParts[0];
            if (array_key_exists($siteLanguagePart, $languageToElasticSearchAnalyzerMap)) {
                $analyzer = $languageToElasticSearchAnalyzerMap[$siteLanguagePart];
            }
        }

        return $analyzer;
    }
}
