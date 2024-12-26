<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use ManualLogEntry;
use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\Extension\RawCSS\Application\ApplicationResourceLoaderModule;
use MediaWiki\Extension\RawCSS\Parser\RawCssParserTag;
use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\Hook\ContentHandlerDefaultModelForHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;

/** @noinspection PhpUnused */

class MainHooks implements
	ParserFirstCallInitHook,
	ContentHandlerDefaultModelForHook,
	ResourceLoaderRegisterModulesHook,
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	PageDeleteCompleteHook
{
	private ApplicationRepository $applicationRepository;

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
	}

	/** @noinspection PhpUnused */
	public static function onRegistration(): void {
		// define the content model constants
		define( 'CONTENT_MODEL_LESS', 'less' );
	}

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ): void {
		$parser->setHook( 'rawcss', [ RawCssParserTag::class, 'onParserHook' ] );
	}

	/** @inheritDoc */
	public function onContentHandlerDefaultModelFor( $title, &$model ): bool {
		// RawCSS:*.css
		if ( $title->getNamespace() == NS_RAWCSS && str_ends_with( $title->getText(), '.css' ) ) {
			$model = CONTENT_MODEL_CSS;
			return false;
		}

		return true;
	}

	/** @inheritDoc */
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

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		// get all the RawCSS module styles applied to this page
		$rawCssModuleStyles = array_filter(
			$out->getModuleStyles(),
			static fn ( $moduleStyle ) => str_starts_with( $moduleStyle, 'ext.rawcss.' )
		);

		// if there's no RawCSS module styles on this page
		if ( $rawCssModuleStyles == [] ) {
			$wildcardApplication = $this->applicationRepository->getApplicationById( '*' );
			// if there's a wildcard application
			if ( $wildcardApplication !== null ) {
				$out->addModuleStyles( [ 'ext.rawcss.*' ] );
			}
		}

		// for each of the RawCSS module styles
		foreach ( $rawCssModuleStyles as $rawCssModuleStyle ) {
			$application = $this->applicationRepository->getApplicationById(
				preg_replace( '/^ext\.rawcss\./', '', $rawCssModuleStyle )
			);

			foreach ( $application['preload'] as $preload ) {
				// add the preload directives
				$out->addLink( [
					'rel' => 'preload',
					...$preload,
				] );
			}
		}
	}

	/** @inheritDoc */
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

	/** @inheritDoc */
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
}
