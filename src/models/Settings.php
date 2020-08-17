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

    public $languageAnalyzer;

    public $autoIndexableSectionHandles;

    public $autoDeleteableSectionHandles;

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


    public function getLanguageAnalyzer($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->languageAnalyzer, $siteHandle) ?: $this->getSiteLanguageAnalyzer($siteHandle);
    }

    public function getStopWordFilter($siteHandle = null): string
    {
        return ConfigHelper::localizedValue($this->languageAnalyzer, $siteHandle) ?: $this->getSiteLanguageStopwordFilter($siteHandle);
    }


    public function getAutoIndexableSectionHandles($siteHandle = null): array
    {
        return ConfigHelper::localizedValue($this->autoIndexableSectionHandles, $siteHandle);
    }

    public function getAutoDeleteableSectionHandles($siteHandle = null): array
    {
        return ConfigHelper::localizedValue($this->autoDeleteableSectionHandles, $siteHandle);
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

        $localParts = explode('-', $siteLanguage);
        $siteLanguagePart = $localParts[0];
        
        return $this->mapValue($languageToElasticSearchAnalyzerMap, $siteLanguage) ?? $this->mapValue($languageToElasticSearchAnalyzerMap, $siteLanguagePart) ?? 'standard';
    }

    /**
     * Get ElasticSearch stop words for the current site language
     *
     * @return string
     */
    private function getSiteLanguageStopwordFilter($siteHandle = null): string
    {
        if ($siteHandle === null) {
            $siteHandle = Craft::$app->getSites()->getCurrentSite()->handle;
        }

        $site = Craft::$app->sites->getSiteByHandle($siteHandle);
        $siteLanguage = $site->language;

        $languageToElasticStopwordFilterMap = [
            'ar' => '_arabic_',
            'bn' => '_bengali_',
            'bg' => '_bulgarian_',
            'ca' => '_catalan_',
            'cs' => '_czech_',
            'da' => '_danish_',
            'de' => '_german_',
            'en' => '_english_',
            'el' => '_greek_',
            'es' => '_spanish_',
            'eu' => '_basque_',
            'fa' => '_persian_',
            'fi' => '_finnish_',
            'fr' => '_french_',
            'ga' => '_irish_',
            'gl' => '_galician_',
            'hi' => '_hindi_',
            'hu' => '_hungarian_',
            'hy' => '_armenian_',
            'id' => '_indonesian_',
            'it' => '_italian_',
            'lv' => '_latvian_',
            'nb' => '_norwegian_',
            'nl' => '_dutch_',
            'pt-BR' => '_brazilian_',
            'pt' => '_portuguese_',
            'ro' => '_romanian_',
            'ru' => '_russian_',
            'sv' => '_swedish_',
            'tr' => '_turkish_',
            'th' => '_thai_',
        ];

        $localParts = explode('-', $siteLanguage);
        $siteLanguagePart = $localParts[0];
        
        return $this->mapValue($languageToElasticStopwordFilterMap, $siteLanguage) ?? $this->mapValue($languageToElasticStopwordFilterMap, $siteLanguagePart) ?? '_none_';
    }

    private function mapValue($mappingTable, $value)
    {
        if (array_key_exists($value, $mappingTable)) {
            return $mappingTable[$value];
        } 

        return null;
    }
}
