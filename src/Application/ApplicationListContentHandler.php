<?php

namespace MediaWiki\Extension\RawCSS\Application;

use Content;
use JsonContentHandler;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Title\Title;
use StatusValue;

/**
 * A {@link \ContentHandler} for the RawCSS application list page
 */
/** @noinspection PhpUnused */
class ApplicationListContentHandler extends JsonContentHandler {
	public function __construct( string $modelId = CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
		parent::__construct( $modelId );
	}

	protected function getContentClass(): string {
		return ApplicationListContent::class;
	}

	public function canBeUsedOn( Title $title ): bool {
		// only allow MediaWiki:RawCSS-applications.json
		return $title->getNamespace() == NS_MEDIAWIKI && $title->getText() == ApplicationRepository::LIST_PAGE_TITLE;
	}

	public function validateSave( Content $content, ValidationParams $validationParams ): StatusValue {
		// validate JSON according to the JSON specification
		$status = parent::validateSave( $content, $validationParams );
		if ( !$status->isOK() ) {
			return $status;
		}

		// validate JSON according to the application list JSON schema
		/** @var ApplicationListContent $content */
		$parsedContent = $content->parse();
		if ( !$parsedContent->isGood() ) {
			// mark it as fatal
			$parsedContent->setOK( false );
			return $parsedContent;
		} else {
			return StatusValue::newGood();
		}
	}
}
