<?php

namespace MediaWiki\Extension\RawCSS\Hook;

use ExtensionRegistry;
use ManualLogEntry;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigFactory;
use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\Extension\RawCSS\Application\ApplicationResourceLoaderModule;
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

/** @noinspection PhpUnused */

class MainHooks implements
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
		// define the content model constants
		define( 'CONTENT_MODEL_RAWCSS_APPLICATION_LIST', 'rawcss-application-list' );
		define( 'CONTENT_MODEL_LESS', 'less' );

		// check for incompatibility with TemplateStyles
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
	 * @param Title $title
	 * @param string &$model
	 * @return bool
	 */
	public function onContentHandlerDefaultModelFor( $title, &$model ): bool {
		// MediaWiki:RawCSS-applications.json
		if ( $title->getNamespace() == NS_MEDIAWIKI && $title->getText() == ApplicationRepository::LIST_PAGE_TITLE ) {
			$model = CONTENT_MODEL_RAWCSS_APPLICATION_LIST;
			return false;
		}

		if ( $title->getNamespace() == NS_RAWCSS ) {
			// RawCSS:*.less
			if ( str_ends_with( $title->getText(), '.less' ) ) {
				$model = CONTENT_MODEL_LESS;
				return false;
			}
			// RawCSS:*.css
			if ( str_ends_with( $title->getText(), '.css' ) ) {
				$model = CONTENT_MODEL_CSS;
				return false;
			}
		}

		if ( $title->getNamespace() == NS_TEMPLATE ) {
			// Template:*.css
			if ( $this->extensionConfig->get( 'RawCSSSetCSSContentModel' )
				&& str_ends_with( $title->getText(), '.css' ) ) {
				$model = CONTENT_MODEL_CSS;
				return false;
			}
		}

		return true;
	}

	/**
	 * Registers the different RawCSS applications as ResourceLoader modules
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
	 * Adds the requested coatings and preload directives to a page
	 * @param OutputPage $out
	 * @param Skin $skin
	 * @return void
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		// ResourceLoader modules to load
		$moduleNames = [];

		// if there's any templates on this page
		if ( array_key_exists( NS_TEMPLATE, $out->getTemplateIds() ) ) {
			// go through each template
			foreach ( $out->getTemplateIds()[NS_TEMPLATE] as $dbKey => $revisionId ) {
				$title = Title::makeTitle( NS_TEMPLATE, $dbKey );

				// and see if there's an application under that ID
				if ( $this->applicationRepository->getApplicationById( $title->getArticleID() ) ) {
					// add the application ResourceLoader module
					$moduleNames[] = 'ext.rawcss.' . $title->getArticleID();
				}
			}
		}

		// if this page is a template
		if ( $out->getTitle()->getNamespace() == NS_TEMPLATE ) {
			// if there's an application under that ID
			if ( $this->applicationRepository->getApplicationById( $out->getTitle()->getArticleID() ) ) {
				// add the application ResourceLoader module
				$moduleNames[] = 'ext.rawcss.' . $out->getTitle()->getArticleID();
			}
		}

		// if there were no matches and there's a catchall application
		if ( empty( $moduleNames ) && $this->applicationRepository->getApplicationById( 0 ) !== null ) {
			// add the application ResourceLoader module
			$moduleNames[] = 'ext.rawcss.0';
		}

		// add the ResourceLoader modules
		$out->addModuleStyles( $moduleNames );

		// for each module
		foreach ( $moduleNames as $moduleName ) {
			/** @var ApplicationResourceLoaderModule $module */
			$module = $out->getResourceLoader()->getModule( $moduleName );

			// if there's any preload directives
			foreach ( $module->getApplication()['preload'] as $href => $preloadDirective ) {
				// add them as <link rel="preload"> tags
				$out->addLink( [
					'rel' => 'preload',
					'href' => $href,
					...$preloadDirective
				] );
			}
		}
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
