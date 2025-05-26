<?php

namespace MediaWiki\Extension\RawCSS\Parser;

use Exception;
use Less_Parser;
use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\Extension\RawCSS\Less\LessContent;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;

class RawCssParserTag {
	private ApplicationRepository $applicationRepository;

	public function __construct(
		ApplicationRepository $applicationRepository
	) {
		$this->applicationRepository = $applicationRepository;
	}

	/**
	 * @param string|null $text content inside the tag (ignored)
	 * @param string[] $params tag attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string|array
	 */
	public function onParserHook( ?string $text, array $params, Parser $parser, PPFrame $frame ): string|array {
		if ( self::isPresent( $params, 'src' ) && self::isPresent( $params, 'vars' ) ) {
			return $this->onInlineStyles( $params['src'], $frame->expand( $params['vars'] ), $parser );
		}

		// make sure ref is in the tag
		if ( !self::isPresent( $params, 'ref' ) ) {
			return self::formatError( $parser, 'rawcss-tag-missing-ref' );
		}

		if ( $this->applicationRepository->getApplicationById( $params['ref'] ) === null ) {
			return self::formatError( $parser, 'rawcss-tag-invalid-ref', wfEscapeWikiText( $params['ref'] ) );
		}

		$parser->getOutput()->addModuleStyles( [ "ext.rawcss.{$params['ref']}" ] );
		return '';
	}

	private function onInlineStyles( string $source, string $variables, Parser $parser ): string|array {
		/** @var LessContent $sourceContent */
		$sourceContent = $this->applicationRepository->getStylePageContent( $source, lessOnly: true );
		if ( $sourceContent === null ) {
			return self::formatError( $parser, 'rawcss-tag-invalid-src', wfEscapeWikiText( $source ) );
		}

		/** @var LessContent $variablesContent */
		$variablesContent = $this->applicationRepository->getStylePageContent( $variables, lessOnly: true );
		if ( $variablesContent === null ) {
			return self::formatError( $parser, 'rawcss-tag-invalid-vars', wfEscapeWikiText( $variables ) );
		}

		$lessParser = new Less_Parser();
		try {
			$lessParser->parse( $sourceContent->getText() );
			$lessParser->parse( $variablesContent->getText() );
			return [ '<style>' . $lessParser->getCss() . '</style>', 'markerType' => 'nowiki' ];
		} catch ( Exception ) {
			return self::formatError( $parser, 'rawcss-tag-parsing-exception',
				wfEscapeWikiText( $source ),
				wfEscapeWikiText( $variables )
			);
		}
	}

	private static function isPresent( array $params, string $key ): bool {
		return trim( $params[$key] ?? '' ) !== '';
	}

	private static function formatError( Parser $parser, mixed $key, mixed ...$params ): string {
		$parser->addTrackingCategory( 'rawcss-page-error-category' );
		return '<strong class="error">'
			. wfMessage( $key, ...$params )->inContentLanguage()->parse()
			. '</strong>';
	}
}
