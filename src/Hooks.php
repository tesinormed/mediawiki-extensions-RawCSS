<?php

namespace MediaWiki\Extension\RawCSS;

use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\Authority;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderRegisterModulesHook;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\Hook\ContentHandlerDefaultModelForHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\Title\Title;
use MediaWiki\User\UserIdentity;
use RuntimeException;
use Skin;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

class Hooks implements
	ContentHandlerDefaultModelForHook,
	ResourceLoaderRegisterModulesHook,
	BeforePageDisplayHook,
	PageSaveCompleteHook,
	PageDeleteCompleteHook
{
	private Config $extensionConfig;
	private ApplicationRepository $applicationRepository;

	public function __construct(
		ConfigFactory $configFactory,
		RevisionLookup $revisionLookup,
		IConnectionProvider $databaseProvider,
		WANObjectCache $wanCache
	) {
		$this->extensionConfig = $configFactory->makeConfig( 'rawcss' );
		$this->applicationRepository = new ApplicationRepository( $revisionLookup, $databaseProvider, $wanCache );
	}

	/** @noinspection PhpUnused */
	public static function onRegistration(): void {
		define( 'CONTENT_MODEL_RAWCSS_APPLICATION_LIST', 'rawcss-application-list' );
		if ( ExtensionRegistry::getInstance()->isLoaded( 'TemplateStyles' ) ) {
			global $wgRawCSSSetCSSContentModel;
			global $wgTemplateStylesNamespaces;
			if ( $wgRawCSSSetCSSContentModel && $wgTemplateStylesNamespaces[NS_TEMPLATE] ) {
				throw new RuntimeException(
					'$wgRawCSSSetCSSContentModel requires $wgTemplateStylesNamespaces[NS_TEMPLATE] to be false'
				);
			}
		}
	}

	/**
	 * Sets the content model for MediaWiki:RawCSS-applications.json to
	 * <code>ApplicationListContentHandler::MODEL_ID</code> and
	 * @param Title $title
	 * @param string &$model
	 * @return bool
	 */
	public function onContentHandlerDefaultModelFor( $title, &$model ): bool {
		if ( $title->getNamespace() == NS_MEDIAWIKI && $title->getText() == ApplicationRepository::LIST_PAGE_TITLE ) {
			$model = CONTENT_MODEL_RAWCSS_APPLICATION_LIST;
			return false;
		}

		if ( $this->extensionConfig->get( 'RawCSSSetCSSContentModel' )
			&& $title->getNamespace() == NS_TEMPLATE
			&& str_ends_with( $title->getText(), '.css' ) ) {
			$model = CONTENT_MODEL_CSS;
			return false;
		}

		return true;
	}

	/**
	 * @param ResourceLoader $rl
	 * @return void
	 */
	public function onResourceLoaderRegisterModules( ResourceLoader $rl ): void {
		foreach ( $this->applicationRepository->getApplicationIds() as $id ) {
			$rl->register( 'ext.rawcss.' . $id, [
				'class' => ApplicationResourceLoaderModule::class,
				'id' => $id,
			] );
		}
	}

	/**
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$moduleStyles = [];

		if ( array_key_exists( NS_TEMPLATE, $out->getTemplateIds() ) ) {
			foreach ( $out->getTemplateIds()[NS_TEMPLATE] as $dbKey => $revisionId ) {
				$title = Title::newFromDBkey( $dbKey );

				if ( $this->applicationRepository->getApplicationById( $title->getArticleID() ) ) {
					$moduleStyles[] = 'ext.rawcss.' . $title->getArticleID();
				}
			}
		}

		if ( count( $moduleStyles ) == 0 && $this->applicationRepository->getApplicationById( 0 ) !== null ) {
			$moduleStyles[] = 'ext.rawcss.null';
		}

		$out->addModuleStyles( $moduleStyles );
	}

	/**
	 * Runs the application repository page update hook
	 * @param WikiPage $wikiPage
	 * @param UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param RevisionRecord $revisionRecord
	 * @param EditResult $editResult
	 * @return void
	 */
	public function onPageSaveComplete(
		$wikiPage,
		$user,
		$summary,
		$flags,
		$revisionRecord,
		$editResult
	): void {
		$this->applicationRepository->onPageUpdate( $wikiPage->getTitle() );
	}

	/**
	 * Runs the application repository page update hook
	 * @param ProperPageIdentity $page
	 * @param Authority $deleter
	 * @param string $reason
	 * @param int $pageID
	 * @param RevisionRecord $deletedRev
	 * @param ManualLogEntry $logEntry
	 * @param int $archivedRevisionCount
	 * @return void
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
		$this->applicationRepository->onPageUpdate( Title::newFromPageIdentity( $page ) );
	}
}
