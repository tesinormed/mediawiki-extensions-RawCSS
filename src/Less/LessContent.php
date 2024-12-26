<?php

namespace MediaWiki\Extension\RawCSS\Less;

use TextContent;

/**
 * Content object implementation for representing Less
 */
class LessContent extends TextContent {
	public function __construct( string $text, string $modelId = CONTENT_MODEL_LESS ) {
		parent::__construct( $text, $modelId );
	}
}
