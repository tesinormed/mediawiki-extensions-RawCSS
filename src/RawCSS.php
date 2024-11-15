<?php

namespace MediaWiki\Extension\RawCSS;

use ErrorException;
use MediaWiki\Config\Config;
use MediaWiki\Extension\RawCSS\Utilities\ParameterExtractor;
use MediaWiki\Extension\RawCSS\Utilities\TemplateEngine;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Parser\Parser;
use MediaWiki\Title\Title;
use PPFrame;
use Throwable;

class RawCSS {
	public const STYLE_SHEETS_DATA_KEY = 'RawCSS_StyleSheets';
	public const PRELOAD_DATA_KEY = 'RawCSS_Preload';

	private Config $extensionConfig;
	public TemplateEngine $templateEngine;
	private WikiPageFactory $wikiPageFactory;

	public function __construct( Config $mainConfig, Config $extensionConfig, WikiPageFactory $wikiPageFactory ) {
		$this->extensionConfig = $extensionConfig;
		$this->templateEngine = new TemplateEngine( $mainConfig, $extensionConfig );
		$this->wikiPageFactory = $wikiPageFactory;
	}

	/**
	 * Formats an error message and returns an array to be returned from a parser function hook
	 * @param Parser $parser
	 * @param string $messageKey
	 * @param mixed ...$messageParameters
	 * @return array
	 */
	private static function formatError( Parser $parser, string $messageKey, mixed ...$messageParameters ): array {
		$parser->addTrackingCategory( 'rawcss-page-error-category' );
		return [
			'<strong class="error">'
			. wfMessage( 'rawcss-' . $messageKey, ...$messageParameters )->inContentLanguage()->parse()
			. '</strong>',
			'isHTML' => true
		];
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
			return self::formatError( $parser, 'style-sheet-invalid' );
		}

		$revisionRecord = $parser->fetchCurrentRevisionRecordOfTitle( $styleSheetTitle );

		// add a "template" for Special:WhatLinksHere
		/** @noinspection PhpParamsInspection */
		$parser->getOutput()->addTemplate(
			$styleSheetTitle,
			$styleSheetTitle->getArticleID(),
			$revisionRecord?->getId()
		);

		$styleSheetWikiPage = $this->wikiPageFactory->newFromTitle( $styleSheetTitle );
		if ( !$styleSheetWikiPage->exists() ) {
			$styleSheetTitlePrefixedText = $styleSheetTitle->getPrefixedText();
			return self::formatError( $parser, 'style-sheet-not-found',
				$styleSheetTitlePrefixedText, wfEscapeWikiText( $styleSheetTitlePrefixedText ) );
		}
		$styleSheetTemplatePath = $this->templateEngine->getLatteTemplatePath( $styleSheetWikiPage );
		if ( !file_exists( $styleSheetTemplatePath ) ) {
			$this->templateEngine->writeLatteTemplate( $styleSheetWikiPage );
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
		} catch ( Throwable ) {
			return self::formatError( $parser, 'style-sheet-rendering-failed' );
		} finally {
			restore_error_handler();
		}

		// find all preload directives
		/** @noinspection PhpUndefinedVariableInspection */
		preg_match_all(
		// phpcs:ignore
			'%/\*!preload\|href=(' . $this->extensionConfig->get('RawCSSPreloadHrefRegex') . ')\|as=(stylesheet|image|font)\|type=(text/css|image/[-.\w]+(?:\+[-.\w]+)?|font/[-.\w]+(?:\+[-.\w]+)?)(?:\|(crossorigin))?\*/%m',
			$output, $matches, PREG_SET_ORDER
		);

		// write the preload directives into the extension data
		foreach ( ( $matches ?: [] ) as $match ) {
			// remove the preload directive from the output
			$output = str_replace( $match[0], '', $output );
			// shift over to the matched regex groups
			$match = array_slice( $match, offset: 1 );
			// make sure the URL is an actual URL
			if ( filter_var( $match[0], FILTER_VALIDATE_URL ) === false ) {
				continue;
			}
			// add into the extension data
			$parser->getOutput()->setExtensionData(
				self::PRELOAD_DATA_KEY,
				array_merge( $parser->getOutput()->getExtensionData( self::PRELOAD_DATA_KEY ) ?: [], [ $match ] )
			);
		}

		// write the style sheets into the extension data
		$parser->getOutput()->setExtensionData(
			self::STYLE_SHEETS_DATA_KEY,
			array_merge( $parser->getOutput()->getExtensionData( self::STYLE_SHEETS_DATA_KEY ) ?: [], [ $output ] )
		);

		return [];
	}
}
