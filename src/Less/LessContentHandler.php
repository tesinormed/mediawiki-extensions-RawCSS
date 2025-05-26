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
		return $title->getNamespace() === NS_RAWCSS;
	}

	/** @inheritDoc */
	public function validateSave( Content $content, ValidationParams $validationParams ): StatusValue {
		/** @var LessContent $content */
		return $content->validate();
	}
}
