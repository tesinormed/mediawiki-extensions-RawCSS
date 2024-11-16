<?php

namespace MediaWiki\Extension\RawCSS\Parser;

use MediaWiki\Extension\RawCSS\Utilities\ErrorFormatter;
use MediaWiki\Extension\RawCSS\Utilities\LinkHeaderGenerator;
use MediaWiki\Extension\RawCSS\Utilities\ParameterExtractor;
use MediaWiki\Parser\Parser;
use PPFrame;

class LinkHeaderFunction {
	public const DATA_KEY = 'RawCSS_LinkHeaders';

	public function onFunctionHook( Parser $parser, PPFrame $frame, array $arguments ): array {
		$url = $frame->expand( $arguments[0] );
		$url = filter_var( $url, FILTER_SANITIZE_URL );
		if ( !filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return ErrorFormatter::formatError( $parser, 'rawcss-link-header-invalid-url', $url );
		}
		// get the true arguments
		$arguments = array_slice( $arguments, offset: 1 );
		// split each argument into parameters
		$parameters = ParameterExtractor::extractParameters( $arguments, $frame );

		$linkHeader = LinkHeaderGenerator::generateLinkHeader( $url, $parameters );
		$parser->getOutput()->setExtensionData(
			self::DATA_KEY,
			array_merge( $parser->getOutput()->getExtensionData( self::DATA_KEY ) ?: [], [ $linkHeader ] )
		);
		return [];
	}
}
