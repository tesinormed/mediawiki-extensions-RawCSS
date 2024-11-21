<?php

namespace MediaWiki\Extension\RawCSS\Less;

use TextContent;

/**
 * A {@link \Content} for <a href="https://lesscss.org/">Less</a>
 */
class LessContent extends TextContent {
	public function __construct( string $text, string $modelId = CONTENT_MODEL_LESS ) {
		parent::__construct( $text, $modelId );
	}
}
