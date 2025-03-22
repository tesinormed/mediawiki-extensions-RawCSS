<?php

namespace MediaWiki\Extension\RawCSS\Less;

use MediaWiki\Content\CodeContentHandler;
use MediaWiki\Content\Content;
use MediaWiki\Content\ValidationParams;
use MediaWiki\Title\Title;
use StatusValue;

class LessContentHandler extends CodeContentHandler {
	protected function getContentClass(): string {
		return LessContent::class;
	}

	public function canBeUsedOn( Title $title ): bool {
		return $title->getNamespace() == NS_RAWCSS;
	}

	/**
	 * @inheritDoc
	 */
	public function validateSave( Content $content, ValidationParams $validationParams ): StatusValue {
		$status = parent::validateSave( $content, $validationParams );
		if ( !$status->isGood() ) {
			return $content->validate();
		} else {
			return $status;
		}
	}
}
