<?php

namespace MediaWiki\Extension\RawCSS\Application;

use Exception;
use Less_Parser;
use MediaWiki\Content\Content;
use MediaWiki\Content\CssContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\Extension\RawCSS\Less\LessContent;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;

class ApplicationRepository {
	public const APPLICATIONS_PAGE_NAMESPACE = NS_MEDIAWIKI;
	public const APPLICATIONS_PAGE_NAME = 'RawCSS-applications';

	private const APPLICATION_REGEX = '/(*LF)^== *?([\w\-]+|\*) *?==\R((?:^===.+===\R(?:^; *?[\w-]+ *?:.+\R)*)+)/m';
	private const APPLICATION_SECTION_REGEX = '/(*LF)^=== *(.+?) *===\R((?:^; *?[\w-]+ *?:.+\R)*)/m';
	private const APPLICATION_SECTION_VARIABLE_REGEX = '/(*LF)^; *?([\w-]+) *?: *(.+?) *$/m';

	private PageLookup $pageLookup;
	private RevisionLookup $revisionLookup;
	private IConnectionProvider $dbProvider;
	private WANObjectCache $wanCache;

	public function __construct(
		PageLookup $pageLookup,
		RevisionLookup $revisionLookup,
		IConnectionProvider $dbProvider,
		WANObjectCache $wanCache
	) {
		$this->pageLookup = $pageLookup;
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

	public function getApplicationIdentifiers(): array {
		return array_keys( $this->getApplications() );
	}

	public function getApplicationById( string $id ): ?array {
		return $this->getApplications()[$id] ?? null;
	}

	private function isUsedByAnyApplication( ProperPageIdentity $pageIdentity ): bool {
		foreach ( $this->getApplications() as $application ) {
			if ( array_key_exists( $pageIdentity->getDBkey(), array_keys( $application ) ) ) {
				return true;
			}
		}

		return false;
	}

	public function getApplications(): array {
		return $this->wanCache->getWithSetCallback(
			$this->makeCacheKey(),
			ExpirationAwareness::TTL_DAY,
			function ( $oldValue, &$ttl, array &$setOpts ) {
				$setOpts += Database::getCacheSetOptions( $this->dbProvider->getReplicaDatabase() );

				// get the applications page
				$applicationsPage = $this->pageLookup->getPageByName(
					self::APPLICATIONS_PAGE_NAMESPACE,
					self::APPLICATIONS_PAGE_NAME
				);
				// if the applications page doesn't exist, exit
				if ( $applicationsPage === null ) {
					$ttl = ExpirationAwareness::TTL_UNCACHEABLE;
					return [];
				}

				// get the applications revision
				$revisionRecord = $this->revisionLookup->getRevisionByTitle( $applicationsPage );
				// make sure the list page is readable
				if ( $revisionRecord === null
					|| !$revisionRecord->getContent( SlotRecord::MAIN )
					|| $revisionRecord->getContent( SlotRecord::MAIN )->isEmpty()
					|| !$revisionRecord->getContent( SlotRecord::MAIN ) instanceof WikitextContent
				) {
					$ttl = ExpirationAwareness::TTL_UNCACHEABLE;
					return [];
				}

				return $this->parseApplicationsPageContent(
					// newline is required because of the way the regex works
					$revisionRecord->getContent( SlotRecord::MAIN )->getText() . "\n"
				);
			},
			[
				'version' => 4,
				'checkKeys' => [ $this->makeCacheKey() ],
				'pcTTL' => ExpirationAwareness::TTL_PROC_SHORT,
				'lockTSE' => 30,
			]
		);
	}

	private function parseApplicationsPageContent( string $content ): array {
		preg_match_all(
			self::APPLICATION_REGEX,
			$content,
			$applicationMatches,
			PREG_SET_ORDER
		);

		// go through each application
		$applications = [];
		foreach ( $applicationMatches as $applicationMatch ) {
			[ , $applicationIdentifier, $applicationText ] = $applicationMatch;

			preg_match_all(
				self::APPLICATION_SECTION_REGEX,
				$applicationText,
				$sectionMatches,
				PREG_SET_ORDER
			);

			// go through each section
			$applicationSpecification = [];
			foreach ( $sectionMatches as $sectionMatch ) {
				[ , $sectionTitle, $sectionVariablesText ] = $sectionMatch;

				preg_match_all(
					self::APPLICATION_SECTION_VARIABLE_REGEX,
					$sectionVariablesText,
					$sectionVariableMatches,
					PREG_SET_ORDER
				);

				// go through each section variable
				$sectionVariables = [];
				foreach ( $sectionVariableMatches as $styleVariableMatch ) {
					$sectionVariables[$styleVariableMatch[1]] = $styleVariableMatch[2];
				}

				// insert
				$applicationSpecification[$sectionTitle] = $sectionVariables;
			}
			$applications[$applicationIdentifier] = $applicationSpecification;
		}

		$lessParser = new Less_Parser();

		foreach ( $applications as $applicationIdentifier => $applicationSpecification ) {
			$application = [];

			foreach ( $applicationSpecification as $page => $variables ) {
				$styleVariables = [ 'rawcss-application-id' => $applicationIdentifier ] + $variables;
				$stylePage = $this->pageLookup->getPageByText( $page, defaultNamespace: NS_RAWCSS );
				if ( $stylePage === null ) {
					continue;
				}
				if ( !$stylePage->exists() ) {
					$application[$stylePage->getDBkey()] = '';
				}

				// get the style page's content
				$stylePageContent = $this->getStylePageContent( $stylePage );
				// make sure it's valid
				if ( $stylePageContent === null ) {
					continue;
				}

				switch ( $stylePageContent->getModel() ) {
					case CONTENT_MODEL_LESS:
						// parse the Less
						try {
							/** @var LessContent $stylePageContent */
							$lessParser->parse( $stylePageContent->getText() );
							$lessParser->ModifyVars( $styleVariables );
							$application[$stylePage->getDBkey()] = $lessParser->getCss();
						} catch ( Exception ) {
							$application[$stylePage->getDBkey()] = '';
						} finally {
							$lessParser->Reset();
						}
						break;
					case CONTENT_MODEL_CSS:
						// add it directly
						/** @var CssContent $stylePageContent */
						$application[$stylePage->getDBkey()] = $stylePageContent->getText();
						break;
				}
			}

			$applications[$applicationIdentifier] = $application;
		}

		return $applications;
	}

	public function getStylePageContent( ProperPageIdentity|string $stylePage, bool $lessOnly = false ): ?Content {
		if ( is_string( $stylePage ) ) {
			$stylePage = $this->pageLookup->getExistingPageByText( $stylePage, defaultNamespace: NS_RAWCSS );

			// invalid if the page doesn't exist
			if ( $stylePage === null ) {
				return null;
			}
		}

		$stylePageContent = $this->revisionLookup->getRevisionByTitle( $stylePage )?->getContent( SlotRecord::MAIN );
		// invalid if the content is null
		if ( $stylePageContent === null ) {
			return null;
		}

		// invalid if the page doesn't have the correct content model
		if ( !$stylePageContent instanceof LessContent && !$stylePageContent instanceof CssContent ) {
			return null;
		}
		if ( $lessOnly && $stylePageContent instanceof CssContent ) {
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

		if ( $this->isUsedByAnyApplication( $page ) ) {
			$this->purgeCache();
		}
	}
}
