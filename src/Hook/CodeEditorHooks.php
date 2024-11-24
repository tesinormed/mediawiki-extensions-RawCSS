<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use MediaWiki\Extension\CodeEditor\Hooks\CodeEditorGetPageLanguageHook;
use MediaWiki\Title\Title;

/** @noinspection PhpUnused */
class CodeEditorHooks implements CodeEditorGetPageLanguageHook {
	public function onCodeEditorGetPageLanguage( Title $title, ?string &$lang, string $model, string $format ): void {
		if ( $model === CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
			$lang = 'json';
		}
		if ( $model === CONTENT_MODEL_LESS ) {
			$lang = 'less';
		}
	}
}
