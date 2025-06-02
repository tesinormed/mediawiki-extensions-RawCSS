<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use ManualLogEntry;
use MediaWiki\Content\Content;
use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\Extension\RawCSS\Application\ApplicationResourceLoaderModule;
use MediaWiki\Extension\RawCSS\Parser\RawCssParserTag;
use MediaWiki\Hook\EditFilterMergedContentHook;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\Hook\ArticlePurgeHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Status\Status;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\User\User;
use RuntimeException;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

class MainHooks implements
	ParserFirstCallInitHook,
	ResourceLoaderRegisterModulesHook,
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	PageDeleteCompleteHook,
	ArticlePurgeHook,
	EditFilterMergedContentHook
{
	private ApplicationRepository $applicationRepository;
	private RawCssParserTag $rawCssParserTag;

	public function __construct(
		PageStore $pageStore,
		RevisionLookup $revisionLookup,
		PermissionManager $permissionManager,
		IConnectionProvider $dbProvider,
		WANObjectCache $wanCache
	) {
		$this->applicationRepository = new ApplicationRepository(
			$pageStore,
			$revisionLookup,
			$permissionManager,
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
		foreach ( $this->applicationRepository->getApplicationIds() as $id ) {
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
				// ignore anything that ends with __ignore_for_wildcard
				&& !str_ends_with( $moduleStyle, '__ignore_for_wildcard' )
		);

		// if there's no RawCSS module styles on this page
		if ( count( $rawCssModuleStyles ) === 0 ) {
			$wildcardApplication = $this->applicationRepository->getApplicationById( '*' );

			// if there's a wildcard application
			if ( $wildcardApplication !== null ) {
				// use the wildcard module style
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

	/**
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/EditFilterMergedContent
	 * @inheritDoc
	 */
	public function onEditFilterMergedContent(
		IContextSource $context,
		Content $content,
		Status $status,
		$summary,
		User $user,
		$minoredit
	): bool {
		return $this->applicationRepository->onEditFilter( $user, $content, $status );
	}
}
