<?php

namespace MediaWiki\Extension\RawCSS\Application;

use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\Module;

/**
 * A {@link ResourceLoader} {@link Module} for a RawCSS application
 */
class ApplicationResourceLoaderModule extends Module {
	private int $applicationId;
	private ApplicationRepository $applicationRepository;

	public function __construct( array $options ) {
		$this->applicationId = $options['applicationId'];
		$this->applicationRepository = $options['applicationRepository'];
	}

	public function getApplication(): array {
		return $this->applicationRepository->getApplicationById( $this->applicationId );
	}

	public function getStyles( Context $context ): array {
		return [ 'all' => $this->getApplication()['styles'] ];
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
