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
    public function isEntryIndexable(Entry $entry): bool {
        $indexableSectionHandles = ElasticSearchPlugin::$plugin->getSettings()->getIndexableSectionHandles();
        return in_array($entry->section->handle, $indexableSectionHandles) || in_array('*', $indexableSectionHandles);
    }

    public function isEntryAutoUpdatableOnSave(Entry $entry): bool {
        $sectionHandlesUpdateableOnSave = ElasticSearchPlugin::$plugin->getSettings()->getSectionHandlesUpdatableOnSave();
        return in_array($entry->section->handle, $sectionHandlesUpdateableOnSave) || in_array('*', $sectionHandlesUpdateableOnSave);
    }
}
