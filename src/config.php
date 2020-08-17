<?php
/**
 * @author David Seibert<david@seibert.io>
 */

/**
 * Craft ElasticSearch Plugin configuration
 *
 * Supports static as well as function based configuration, e.g.
 * - 'apiKey' => 'xxx'
 * - 'apiKey' => function (string $siteHandle) { return 'xxx'; }
 *
 * Supports per-site configuration, e.g.
 * - 'apiKey' => 'xxx'
 * - 'apiKey' => ['xxx', 'de' => 'xxy', 'es' => 'xxz']
 */
return [
    /**
     * Hosts the plugin may connect to.
     * (Uses function Syntax instead of a plain array as the config value is an array and the Craft ConfigHelper identifies such scenario
     * as a per-site config per default)
     *
     * Each host may be a string or an associative array as described in https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/configuration.html, e.g.
     * - foo.com
     * - https://foo.com:9400/
     * - https://foo.com:9500/elastic
     * - https://username:password!#$?*abc@foo.com:9200/elastic
     */
    'hosts' => function (string $siteHandle) {
        // differnt sites may use different elastic search configurations
        switch ($siteHandle) {
            default:
                return [
                    'https://hostname:port',
                ];
        }
    },

    /**
     * Set to use an API key pair to connect to ElasticSearch
     *
     * @see https://www.elastic.co/guide/en/elasticsearch/client/php-api/current/security.html
     */
    'apiKeyId' => 'xxx',
    'apiKey' => 'yyy',

    /**
     * Optionally replaces the apps baseUrl for page fetching.
     *
     * This may be required for the app to be able to fetch pages from itself, e.g.:
     * - if requests to the configured baseUrl are cached outside of Craft CMS (e.g. via Varnish, CDN or a reverse proxy),
     *   as the plugin needs to be able to fetch updated pages right after an entry update
     *   when caches may not yet have invalidated
     * - in local Docker setups, when using http(s)://localhost to access the application from the host machine
     *   and the app container listening on a different port. (on Windows/Mac, http://host.docker.internal/ will most
     *   likely be your goto- `fetchBaseUrl`)
     */
    'fetchBaseUrl' => '',

    /**
     * ElasticSearch language analyzer used for each site
     * If not set, a best-guess fallback for each site will be used based on the sites' language
     */
    'languageAnalyzer' => '',

    /**
     * Sections of which entries may be indexed
     */
    'indexableSectionHandles' => function (string $siteHandle) {
        return ['*'];
    },

    /**
     * Sections of which entries may be automatically updated on entry save
     */
    'sectionHandlesUpdatableOnSave' => function (string $siteHandle) {
        return ['*'];
    },
];
