<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use ManualLogEntry;
use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\Extension\RawCSS\Application\ApplicationResourceLoaderModule;
use MediaWiki\Extension\RawCSS\Parser\RawCssParserTag;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

class MainHooks implements
	ParserFirstCallInitHook,
	ResourceLoaderRegisterModulesHook,
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	ArticlePurgeHook
{
	private ApplicationRepository $applicationRepository;
	private RawCssParserTag $rawCssParserTag;

	public function __construct(
		PageStore $pageStore,
		RevisionLookup $revisionLookup,
		IConnectionProvider $dbProvider,
		WANObjectCache $wanCache
	) {
		$this->applicationRepository = new ApplicationRepository(
			$pageStore,
			$revisionLookup,
			$dbProvider,
			$wanCache
		);
		$this->rawCssParserTag = new RawCssParserTag( $this->applicationRepository );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Extension.json/Schema#callback
	 */
	public static function onRegistration( array $extensionInfo, SettingsBuilder $settings ): void {
		// define the content model constants
		define( 'CONTENT_MODEL_LESS', 'less' );

		if ( $settings->getConfig()->get( 'RawCSSAllowedSkins' ) === null ) {
			throw new RuntimeException( '$wgRawCSSAllowedSkins must be set' );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ParserFirstCallInit
	 * @inheritDoc
	 */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'rawcss', [ $this->rawCssParserTag, 'onParserHook' ] );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ResourceLoaderRegisterModules
	 * @inheritDoc
	 */
	public function onResourceLoaderRegisterModules( ResourceLoader $rl ): void {
		// for each application
		foreach ( $this->applicationRepository->getApplicationIdentifiers() as $id ) {
			// register it with ResourceLoader
			$rl->register( "ext.rawcss.$id", [
				'class' => ApplicationResourceLoaderModule::class,
				'applicationId' => $id,
			] );
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// get all the RawCSS module styles applied to this page
		$rawCssModuleStyles = array_filter(
			$out->getModuleStyles(),
			static fn ( $moduleStyle ) => str_starts_with( $moduleStyle, 'ext.rawcss.' )
				&& !str_ends_with( $moduleStyle, '__ignore_for_wildcard' )
		);

		// if there's no RawCSS module styles on this page
		if ( count( $rawCssModuleStyles ) === 0 ) {
			$wildcardApplication = $this->applicationRepository->getApplicationById( '*' );

			// if there's a wildcard application
			if ( $wildcardApplication !== null ) {
				$out->addModuleStyles( [ 'ext.rawcss.*' ] );
			}
		}
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageSaveComplete
	 * @inheritDoc
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		$this->applicationRepository->onPageUpdate( $wikiPage );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageDeleteComplete
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		ProperPageIdentity $page,
		Authority $deleter,
		string $reason,
		int $pageID,
		RevisionRecord $deletedRev,
		ManualLogEntry $logEntry,
		int $archivedRevisionCount
	): void {
		$this->applicationRepository->onPageUpdate( $page );
	}

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticlePurge
	 * @inheritDoc
	 */
	public function onArticlePurge( $wikiPage ): void {
		$this->applicationRepository->onPageUpdate( $wikiPage );
	}
}
