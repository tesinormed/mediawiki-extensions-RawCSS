<?php

namespace MediaWiki\Extension\RawCSS\Less;

use CodeContentHandler;
use MediaWiki\Title\Title;

/**
 * A {@link \ContentHandler} for <a href="https://lesscss.org/">Less</a>
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
