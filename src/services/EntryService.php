<?php

/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\services;

use craft\base\Component;
use craft\elements\Entry;
use seibertio\elasticsearch\ElasticSearchPlugin;

/**
 * Entry Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 */
class EntryService extends Component
{
    public function isEntryAutoIndexable(Entry $entry): bool {
        $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getAutoIndexableSectionHandles();
        return in_array($entry->section->handle, $indexableSectionHandles);
    }

    public function isEntryAutoDeleteable(Entry $entry): bool {
        $deleteableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getAutoDeleteableSectionHandles();
        return in_array($entry->section->handle, $deleteableSectionHandles);
    }
}
