<?php

namespace MediaWiki\Extension\RawCSS\Parser;

use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\PPFrame;
use Wikimedia\Message\MessageSpecifier;

class RawCssParserTag {
	/**
	 * @param string|null $text content inside the tag (ignored)
	 * @param string[] $params tag attributes
	 * @param Parser $parser
	 * @param PPFrame $frame
	 * @return string HTML
	 */
	public static function onParserHook( ?string $text, array $params, Parser $parser, PPFrame $frame ): string {
		/** @var ApplicationRepository $applicationRepository */
		$applicationRepository = MediaWikiServices::getInstance()->getService( 'RawCSS.ApplicationRepository' );

		// make sure ref is in the tag
		if ( !isset( $params['ref'] ) || trim( $params['ref'] ) == '' ) {
			return self::formatError( $parser, 'rawcss-tag-missing-ref' );
		}

		if ( $applicationRepository->getApplicationById( $params['ref'] ) === null ) {
			return self::formatError( $parser, 'rawcss-tag-invalid-ref', wfEscapeWikiText( $params['ref'] ) );
		}

		$parser->getOutput()->addModuleStyles( [ "ext.rawcss.{$params['ref']}" ] );
		return '';
	}

	/**
	 * Formats an error message into HTML
	 * @param Parser $parser
	 * @param string|string[]|MessageSpecifier $key The message key to use
	 * @param mixed ...$params The parameters for the message
	 * @return string The formatted error
	 */
	private static function formatError( Parser $parser, mixed $key, mixed ...$params ): string {
		$parser->addTrackingCategory( 'rawcss-page-error-category' );
		return '<strong class="error">'
			. wfMessage( $key, ...$params )->inContentLanguage()->parse()
			. '</strong>';
	}
}
