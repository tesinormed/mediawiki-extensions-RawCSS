<?php

namespace MediaWiki\Extension\RawCSS\Application;

use Content;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Page\PageStore;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The repository to find and store RawCSS applications
 */
class ApplicationRepository {
	/**
	 * @var string The page where RawCSS applications are defined
	 */
	public const APPLICATIONS_PAGE_NAMESPACE = NS_MEDIAWIKI;
	public const APPLICATIONS_PAGE_NAME = 'RawCSS-applications';
	public const APPLICATIONS_PAGE_SCHEMA_VERSION = 2;

	private PageStore $pageStore;
	private RevisionLookup $revisionLookup;
	private IConnectionProvider $dbProvider;
	private WANObjectCache $wanCache;

	public function __construct(
		PageStore $pageStore,
		RevisionLookup $revisionLookup,
		IConnectionProvider $dbProvider,
		WANObjectCache $wanCache
	) {
		$this->pageStore = $pageStore;
		$this->revisionLookup = $revisionLookup;
		$this->dbProvider = $dbProvider;
		$this->wanCache = $wanCache;
	}

	private function makeCacheKey(): string {
		return $this->wanCache->makeKey( 'rawcss-applications' );
	}

	private function purgeCache(): void {
		$this->wanCache->touchCheckKey( $this->makeCacheKey() );
	}

	public function getApplicationIds(): array {
		return array_keys( $this->getApplications() );
	}

	public function getApplicationById( string $id ): ?array {
		$applications = $this->getApplications();
		if ( !array_key_exists( key: $id, array: $applications ) ) {
			return null;
		} else {
			return $applications[$id];
		}
	}

	public function getApplications(): array {
		// get the applications from the cache if it exists; otherwise create it
		return $this->wanCache->getWithSetCallback(
			$this->makeCacheKey(),
			$this->wanCache::TTL_DAY,
			function ( $oldValue, &$ttl, array &$setOpts ) {
				$setOpts += Database::getCacheSetOptions( $this->dbProvider->getReplicaDatabase() );

				// get the applications page
				$applicationsPage = $this->pageStore
					->getPageByName( self::APPLICATIONS_PAGE_NAMESPACE, self::APPLICATIONS_PAGE_NAME );
				// if the applications page doesn't exist, exit
				if ( $applicationsPage === null ) {
					return [];
				}

				// get the applications revision
				$revisionRecord = $this->revisionLookup
					->getRevisionByTitle( $applicationsPage );
				// make sure the list page is readable
				if ( !$revisionRecord
					|| !$revisionRecord->getContent( SlotRecord::MAIN )
					|| $revisionRecord->getContent( SlotRecord::MAIN )->isEmpty() ) {
					return [];
				}

				return $this->parseApplicationsPageContent(
					// newline is required because of the way the regex works
					$revisionRecord->getContent( SlotRecord::MAIN )->getText() . "\n"
				);
			},
			[
				'version' => self::APPLICATIONS_PAGE_SCHEMA_VERSION,
				'checkKeys' => [ $this->makeCacheKey() ],
				'lockTSE' => 300,
			]
		);
	}

	private function parseApplicationsPageContent( string $content ): array {
		preg_match_all( '/(*LF)^== *?([\w\-]+|\*) *?==\R((?:^===.+===\R(?:^; *?[\w-]+ *?:.+\R)*)+)/m',
			$content, $applicationMatches,
			PREG_SET_ORDER
		);

		// go through each application
		$applications = [];
		foreach ( $applicationMatches as $applicationMatch ) {
			[ , $applicationIdentifier, $applicationText ] = $applicationMatch;

			preg_match_all( '/(*LF)^=== *(.+?) *===\R((?:^; *?[\w-]+ *?:.+\R)*)/m',
				$applicationText, $sectionMatches,
				PREG_SET_ORDER
			);

			// go through each section
			$applicationSpecification = [];
			foreach ( $sectionMatches as $sectionMatch ) {
				[ , $sectionTitle, $sectionVariablesText ] = $sectionMatch;

				preg_match_all( '/(*LF)^; *?([\w-]+) *?: *(.+?) *$/m',
					$sectionVariablesText, $sectionVariableMatches,
					PREG_SET_ORDER
				);

				// go through each section variable
				$sectionVariables = [];
				foreach ( $sectionVariableMatches as $styleVariableMatch ) {
					$sectionVariables[$styleVariableMatch[1]] = $styleVariableMatch[2];
				}

				if ( !str_starts_with( $sectionTitle, '__preload' ) ) {
					// if this isn't a preload directive
					// make sure it's a valid style page
					if ( $this->getStylePageContent( $sectionTitle ) === null ) {
						continue;
					}
					// insert into styles
					$applicationSpecification['styles'][$sectionTitle] = $sectionVariables;
				} else {
					// if this is a preload directive
					// insert into preload
					$applicationSpecification['preload'][] = $sectionVariables;
				}
			}
			$applications[$applicationIdentifier] = $applicationSpecification;
		}
		return $applications;
	}

	public function getStylePageContent( string $stylePageTitle ): ?Content {
		$stylePage = $this->pageStore->getExistingPageByText( $stylePageTitle, defaultNamespace: NS_RAWCSS );
		// invalid if the page doesn't exist
		if ( $stylePage === null ) {
			return null;
		}

		$stylePageContent = $this->revisionLookup->getRevisionByTitle( $stylePage )?->getContent( SlotRecord::MAIN );
		// invalid if the content is null
		if ( $stylePageContent === null ) {
			return null;
		}
		// invalid if the page doesn't have the correct content model
		if ( $stylePageContent->getModel() !== CONTENT_MODEL_LESS
			&& $stylePageContent->getModel() !== CONTENT_MODEL_CSS ) {
			// skip over this
			return null;
		}

		// valid
		return $stylePageContent;
	}

	public function onPageUpdate( ProperPageIdentity $page ): void {
		// if this page is the applications page, purge the cache
		if ( $page->isSamePageAs( new PageReferenceValue(
			namespace: self::APPLICATIONS_PAGE_NAMESPACE,
			dbKey: self::APPLICATIONS_PAGE_NAME,
			wikiId: WikiAwareEntity::LOCAL
		) ) ) {
			$this->purgeCache();
		}
	}
}
