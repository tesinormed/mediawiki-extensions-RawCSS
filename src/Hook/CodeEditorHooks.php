<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use MediaWiki\Extension\CodeEditor\Hooks\CodeEditorGetPageLanguageHook;
use MediaWiki\Title\Title;

class CodeEditorHooks implements CodeEditorGetPageLanguageHook {
	/**
	 * @see https://www.mediawiki.org/wiki/Extension:CodeEditor/Hooks/CodeEditorGetPageLanguage
	 * @inheritDoc
	 */
	public function onCodeEditorGetPageLanguage( Title $title, ?string &$lang, string $model, string $format ): void {
		if ( $model === CONTENT_MODEL_LESS ) {
			$lang = 'less';
		}
	}
}
