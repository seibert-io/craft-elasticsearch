<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\events;

use yii\base\Event;

class IndexEvent extends Event
{
    const EVENT_INIT = 'init';

    const EVENT_BEFORE_CREATE = 'beforeCreateIndex';

    const EVENT_AFTER_CREATE = 'afterCreateIndex';

    const EVENT_BEFORE_INDEX = 'beforeIndex';

    const EVENT_AFTER_DELETE = 'afterDelete';
}
