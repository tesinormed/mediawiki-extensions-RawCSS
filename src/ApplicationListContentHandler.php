<?php

namespace MediaWiki\Extension\RawCSS;

use Content;
use JsonContentHandler;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Title\Title;
use StatusValue;

/** @noinspection PhpUnused */
class ApplicationListContentHandler extends JsonContentHandler {
	public function __construct( string $modelId = CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
		parent::__construct( $modelId );
	}

	protected function getContentClass(): string {
		return ApplicationListContent::class;
	}

	public function canBeUsedOn( Title $title ): bool {
		return $title->getNamespace() == NS_MEDIAWIKI && $title->getText() == ApplicationRepository::LIST_PAGE_TITLE;
	}

	public function validateSave( Content $content, ValidationParams $validationParams ): StatusValue {
		$status = parent::validateSave( $content, $validationParams );
		if ( !$status->isOK() ) {
			return $status;
		} else {
			/** @var ApplicationListContent $content */
			$parsedContent = $content->parse();
			if ( !$parsedContent->isOK() ) {
				return $parsedContent;
			} else {
				return StatusValue::newGood();
			}
		}
	}
}
