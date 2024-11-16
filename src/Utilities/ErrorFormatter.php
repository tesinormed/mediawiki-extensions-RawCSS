<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

use MediaWiki\Parser\Parser;

class ErrorFormatter {
	/**
	 * Formats an error message and returns an array to be returned from a parser function hook
	 * @param Parser $parser
	 * @param string $messageKey
	 * @param mixed ...$messageParameters
	 * @return array
	 */
	public static function formatError( Parser $parser, string $messageKey, mixed ...$messageParameters ): array {
		$parser->addTrackingCategory( 'rawcss-page-error-category' );
		return [
			'<strong class="error">'
			. wfMessage( $messageKey, ...$messageParameters )->inContentLanguage()->parse()
			. '</strong>',
			'isHTML' => true
		];
	}
}
