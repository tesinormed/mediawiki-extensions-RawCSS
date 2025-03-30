<?php

namespace MediaWiki\Extension\RawCSS\Application;

use Exception;
use Less_Parser;
use MediaWiki\Config\Config;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\Module;

class ApplicationResourceLoaderModule extends Module {
	private string $applicationId;
	private ApplicationRepository $applicationRepository;
	private Config $extensionConfig;

	public function __construct( array $options ) {
		$this->applicationId = $options['applicationId'];
		$this->applicationRepository = MediaWikiServices::getInstance()->getService( 'RawCSS.ApplicationRepository' );
		$this->extensionConfig = MediaWikiServices::getInstance()->getConfigFactory()->makeConfig( 'rawcss' );
	}

	public function getApplication(): array {
		return $this->applicationRepository->getApplicationById( $this->applicationId );
	}

	public function getStyles( Context $context ): array {
		// if this skin isn't supported, don't output any styles
		if ( !in_array( $context->getSkin(), $this->extensionConfig->get( 'RawCSSAllowedSkins' ) ) ) {
			return [];
		}

		$styles = [];
		$lessParser = new Less_Parser( [ 'compress' => true, 'relativeUrls' => false ] );

		// for each style
		foreach ( $this->getApplication()['styles'] as $style ) {
			$stylePageTitle = $style['pageTitle'];
			$styleVariables = $style['variables'];

			// get the style page's content
			$stylePageContent = $this->applicationRepository->getStylePageContent( $stylePageTitle );
			// make sure it's valid
			if ( $stylePageContent === null ) {
				continue;
			}

			switch ( $stylePageContent->getModel() ) {
				case CONTENT_MODEL_LESS:
					// parse the Less
					try {
						$lessParser->parse( $stylePageContent->getText() );
						$lessParser->ModifyVars( $styleVariables );
						$styles[] = $lessParser->getCss();
						$lessParser->Reset();
					} catch ( Exception ) {
					}
					break;
				case CONTENT_MODEL_CSS:
					// add it directly
					$styles[] = $stylePageContent->getText();
			}
		}

		return [ 'all' => $styles ];
	}

	public function getSkins(): ?array {
		return $this->extensionConfig->get( 'RawCSSAllowedSkins' );
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
		// use the application specification as determiners
		$summary['styles'] = $this->getApplication()['styles'];
		$summary['preload'] = $this->getApplication()['preload'];
		return $summary;
	}
}
