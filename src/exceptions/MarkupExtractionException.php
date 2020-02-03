<?php
/**
 * @author David Seibert<david@seibert.io>
 */

namespace seibertio\elasticsearch\exceptions;


class MarkupExtractionException extends \Exception {
	private string $markup;

	public function __construct($markup, $message = "", $code = 0, ?\Throwable $previous = null)
	{
		parent::__construct($message, $code, $previous);
		$this->markup = $markup;
	}

	public function getMarkup(): string {
		return $this->markup;
	}
}