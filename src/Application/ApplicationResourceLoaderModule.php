<?php

namespace MediaWiki\Extension\RawCSS\Application;

use CssContent;
use Exception;
use Less_Parser;
use MediaWiki\Extension\RawCSS\Less\LessContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\Module;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

/**
 * A {@link ResourceLoader} {@link Module} for a RawCSS application
 */
class ApplicationResourceLoaderModule extends Module {
	private int $applicationId;
	private ApplicationRepository $applicationRepository;
	private RevisionLookup $revisionLookup;

	public function __construct( array $options ) {
		$this->applicationId = $options['id'];
		$this->applicationRepository = MediaWikiServices::getInstance()->getService( 'RawCSS.ApplicationRepository' );
		$this->revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
	}

	public function getApplication(): array {
		return $this->applicationRepository->getApplicationById( $this->applicationId );
	}

	public function getStyles( Context $context ): array {
		$styles = [];

		// create the Less parser
		$lessParser = new Less_Parser( [ 'compress' => true, 'relativeUrls' => false ] );
		// add the application variables
		$lessParser->ModifyVars( $this->getApplication()['variables'] );

		// to use later for conditional addition of the compiled Less
		$lessCoatingCount = 0;
		foreach ( $this->getApplication()['coatings'] as $coatingArticleId ) {
			$coatingTitle = Title::newFromID( $coatingArticleId );
			$coatingContent = $this->revisionLookup->getRevisionByTitle( $coatingTitle )
				->getContent( SlotRecord::MAIN );

			if ( $coatingContent instanceof LessContent ) {
				try {
					$lessParser->parse( $coatingContent->getText() );
				} catch ( Exception ) {
					wfLogWarning( "RawCSS application resource module (application ID $this->applicationId) has an invalid coating; article ID $coatingArticleId" );
				}
				$lessCoatingCount++;
			} elseif ( $coatingContent instanceof CssContent ) {
				// add the raw CSS
				$styles['all'][] = $coatingContent->getText();
			}
		}
		if ( $lessCoatingCount > 0 ) {
			try {
				// add the compiled Less
				$styles['all'][] = $lessParser->getCss();
			} catch ( Exception ) {
				wfLogWarning( "RawCSS application resource module (application ID $this->applicationId) failed Less CSS compilation" );
			}
		}

		return $styles;
	}

	public function getType(): string {
		return self::LOAD_STYLES;
	}

	public function getGroup(): string {
		return self::GROUP_SITE;
	}

	public function supportsURLLoading(): bool {
		return true;
	}

	public function getDefinitionSummary( Context $context ): array {
		$summary = parent::getDefinitionSummary( $context );
		$summary[] = [
			// use the application specification as determiners
			'coatings' => $this->getApplication()['coatings'],
			'variables' => $this->getApplication()['variables'],
			'preload' => $this->getApplication()['preload'],
		];
		return $summary;
	}
}
