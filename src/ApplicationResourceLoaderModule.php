<?php

namespace MediaWiki\Extension\RawCSS;

use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\Context;
use MediaWiki\ResourceLoader\WikiModule;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFormatter;

class ApplicationResourceLoaderModule extends WikiModule {
	private int $id;
	private ApplicationRepository $applicationRepository;
	private TitleFormatter $titleFormatter;

	public function __construct( array $options ) {
		parent::__construct();
		$this->id = $options['id'];
		$this->applicationRepository = MediaWikiServices::getInstance()->getService( 'RawCSS.ApplicationRepository' );
		$this->titleFormatter = MediaWikiServices::getInstance()->getTitleFormatter();
	}

	private function getApplication(): array {
		return $this->applicationRepository->getApplicationById( $this->id );
	}

	protected function getPages( Context $context ): array {
		$pages = [];
		foreach ( $this->getApplication()['coatings'] as $coating ) {
			$pages[$this->titleFormatter->getPrefixedDBkey( Title::newFromID( $coating ) )] = [ 'type' => 'style' ];
		}
		return $pages;
	}

	public function getStyles( Context $context ): array {
		$styles = parent::getStyles( $context );

		$renderedVariables = ':root{';
		foreach ( $this->getApplication()['variables'] as $name => $value ) {
			$renderedVariables .= sprintf( '--%s:%s;', $name, $value );
		}
		$renderedVariables .= '}';
		array_unshift( $styles['all'], $renderedVariables );

		return $styles;
	}

	protected function getPreloadLinks( Context $context ): array {
		return $this->getApplication()['preload'];
	}

	public function getType(): string {
		return self::LOAD_STYLES;
	}

	public function getGroup(): string {
		return self::GROUP_SITE;
	}

	public function getDefinitionSummary( Context $context ): array {
		$summary = parent::getDefinitionSummary( $context );
		$summary[] = [
			'variables' => $this->getApplication()['variables'],
			'preload' => $this->getApplication()['preload'],
		];
		return $summary;
	}
}
