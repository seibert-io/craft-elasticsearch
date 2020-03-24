<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\events;

use yii\base\Event;

class DocumentEvent extends Event
{
	const EVENT_INIT = 'onInit';
	
    const EVENT_BEFORE_INDEX = 'beforeIndex';
	
	const EVENT_BEFORE_DELETE = 'beforeDelete';

	public $params;
	
	private bool $canceled = false;

	public function cancel(): void {
		$this->canceled = true;
	}

	public function isCanceled(): bool {
		return $this->canceled;
	}
}
