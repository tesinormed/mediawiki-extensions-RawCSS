<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

class PreloadLinkGenerator {
	/**
	 * Creates a Link directive from an array of Link parameters
	 * @param array $linkParameters an array in the format of ['href', 'as', 'type', 'crossorigin']
	 * @return string
	 */
	public static function generatePreloadLink( array $linkParameters ): string {
		// remove escaping in the URL
		$linkParameters[0] = stripslashes( $linkParameters[0] );
		$link = sprintf(
			'<%s>;rel="preload";as="%s";type="%s"',
			$linkParameters[0], $linkParameters[1], $linkParameters[2],
		);
		if ( !empty( $linkParameters[3] ) ) {
			$link .= ';crossorigin="anonymous"';
		}
		return $link;
	}
}
