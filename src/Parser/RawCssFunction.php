<?php

namespace MediaWiki\Extension\RawCSS\Parser;

use ErrorException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\RawCSS\Utilities\ErrorFormatter;
use MediaWiki\Extension\RawCSS\Utilities\ParameterExtractor;
use MediaWiki\Extension\RawCSS\Utilities\TemplateEngine;
use MediaWiki\Parser\Parser;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use PPFrame;
use Throwable;

class RawCssFunction {
	public const DATA_KEY = 'RawCSS_StyleSheets';

	public TemplateEngine $templateEngine;

	public function __construct( Config $mainConfig, Config $extensionConfig ) {
		$this->templateEngine = new TemplateEngine( $mainConfig, $extensionConfig );
	}

	public function onFunctionHook( Parser $parser, PPFrame $frame, array $arguments ): array {
		// get the style sheet page name
		$styleSheetPageName = $frame->expand( $arguments[0] );
		// get the true arguments
		$arguments = array_slice( $arguments, offset: 1 );
		// split each argument into parameters
		$parameters = ParameterExtractor::extractParameters( $arguments, $frame );

		$styleSheetTitle = Title::makeTitleSafe( NS_RAWCSS, $styleSheetPageName );
		if ( !$styleSheetTitle || $styleSheetTitle->isExternal() ) {
			return ErrorFormatter::formatError( $parser, 'rawcss-style-sheet-invalid' );
		}
		if ( !$styleSheetTitle->isKnown() ) {
			return ErrorFormatter::formatError( $parser, 'rawcss-style-sheet-not-found',
				$styleSheetTitle->getPrefixedText(), wfEscapeWikiText( $styleSheetTitle->getPrefixedText() ) );
		}

		$revisionRecord = $parser->fetchCurrentRevisionRecordOfTitle( $styleSheetTitle );

		// add a "template" for Special:WhatLinksHere
		/** @noinspection PhpParamsInspection */
		$parser->getOutput()->addTemplate(
			$styleSheetTitle,
			$styleSheetTitle->getArticleID(),
			$revisionRecord?->getId()
		);

		$styleSheetTemplatePath = $this->templateEngine->getLatteTemplatePath( $styleSheetTitle, $revisionRecord );
		if ( !file_exists( $styleSheetTemplatePath ) ) {
			$this->templateEngine->writeLatteTemplate(
				$styleSheetTitle,
				$revisionRecord,
				$revisionRecord->getContent( SlotRecord::MAIN, RevisionRecord::RAW )
			);
		}

		// render the template
		set_error_handler(
			static function ( int $errno, string $errstr, string $errfile, int $errline ): bool {
				/** @noinspection PhpUnhandledExceptionInspection */
				throw new ErrorException( $errstr, 0, $errno, $errfile, $errline );
			},
			error_levels: E_WARNING
		);
		try {
			$output = $this->templateEngine->createLatteEngine()
				->renderToString( $styleSheetTemplatePath, $parameters );
		} catch ( Throwable $exception ) {
			return ErrorFormatter::formatError( $parser, 'rawcss-style-sheet-rendering-failed',
				$exception->getMessage() );
		} finally {
			restore_error_handler();
		}

		// write the style sheets into the extension data
		$parser->getOutput()->setExtensionData(
			self::DATA_KEY,
			array_merge( $parser->getOutput()->getExtensionData( self::DATA_KEY ) ?: [], [ $output ] )
		);

		return [];
	}
}
