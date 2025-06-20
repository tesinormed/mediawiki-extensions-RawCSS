<?php

namespace MediaWiki\Extension\RawCSS\Application;

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
		return array_values( $this->applicationRepository->getApplicationById( $this->applicationId ) );
	}

	public function getStyles( Context $context ): array {
		// if this skin isn't supported, don't output any styles
		if ( !in_array( $context->getSkin(), $this->extensionConfig->get( 'RawCSSAllowedSkins' ) ) ) {
			return [];
		}

		return [ 'all' => array_column_flat( $this->getApplication(), 'styles' ) ];
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
		$summary['revision'] = array_column_flat( $this->getApplication(), 'revision' );
		$summary['variables'] = array_column_flat( $this->getApplication(), 'variables' );
		return $summary;
	}
}

function array_column_flat( array $array, string $column ): array {
	$result = [];
	foreach ( $array as $element ) {
		$result = array_merge( $result, array_column( $element, $column ) );
	}
	return $result;
}
