<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\events;

use yii\base\Event;

class QueryEvent extends Event
{
	const EVENT_BEFORE_QUERY = 'beforeQuery';
	
	const EVENT_AFTER_QUERY = 'afterQuery';
	
    const EVENT_BEFORE_SUGGEST_QUERY = 'beforeSuggestQuery';
	
	const EVENT_AFTER_SUGGEST_QUERY = 'afterSuggestQuery';
	
	public array $input;
	
	public array $query;

	public array $response;

}
