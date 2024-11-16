<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

class LinkHeaderGenerator {
	/**
	 * Creates a Link header from a URL and Link header parameters
	 * @param string $url a valid URL according to <code>FILTER_VALIDATE_URL</code>
	 * @param array $parameters an associative array of Link header parameters
	 * @return string
	 */
	public static function generateLinkHeader( string $url, array $parameters ): string {
		$header = "<$url>";
		foreach ( $parameters as $parameter => $value ) {
			$header .= sprintf( ";%s=\"%s\"", $parameter, rawurlencode( $value ) );
		}
		return $header;
	}
}
