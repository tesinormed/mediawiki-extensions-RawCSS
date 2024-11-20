<?php

namespace MediaWiki\Extension\RawCSS;

use MediaWiki\Extension\CodeEditor\Hooks\CodeEditorGetPageLanguageHook;
use MediaWiki\Title\Title;

/** @noinspection PhpUnused */
class CodeEditorHooks implements CodeEditorGetPageLanguageHook {
	public function onCodeEditorGetPageLanguage( Title $title, ?string &$lang, string $model, string $format ): bool {
		if ( $model === CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
			$lang = 'json';
			return false;
		} else {
			return true;
		}
	}
}