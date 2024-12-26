<?php

namespace MediaWiki\Extension\RawCSS\Less;

use CodeContentHandler;
use MediaWiki\Title\Title;

/**
 * Content handler for Less
 */
/** @noinspection PhpUnused */
class LessContentHandler extends CodeContentHandler {
	protected function getContentClass(): string {
		return LessContent::class;
	}

	public function canBeUsedOn( Title $title ): bool {
		return $title->getNamespace() == NS_RAWCSS;
	}
}
