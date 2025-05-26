<?php

namespace MediaWiki\Extension\RawCSS\Less;

use Less_Exception_Parser;
use Less_Parser;
use MediaWiki\Content\TextContent;
use StatusValue;

class LessContent extends TextContent {
	public function __construct( string $text, string $modelId = CONTENT_MODEL_LESS ) {
		parent::__construct( $text, $modelId );
	}

	/** @inheritDoc */
	public function isValid(): bool {
		return $this->validate()->isGood();
	}

	public function validate(): StatusValue {
		$parser = new Less_Parser();
		try {
			$parser->parse( $this->getText() );
		} catch ( Less_Exception_Parser ) {
			return StatusValue::newFatal( 'rawcss-less-validation-fail' );
		}
		return StatusValue::newGood();
	}
}
