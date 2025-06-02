<?php

namespace MediaWiki\Extension\RawCSS\Application;

use Exception;
use Less_Parser;
use MediaWiki\Content\Content;
use MediaWiki\Content\CssContent;
use MediaWiki\Content\WikitextContent;
use MediaWiki\DAO\WikiAwareEntity;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Extension\RawCSS\Less\LessContent;
use MediaWiki\Page\PageLookup;
use MediaWiki\Page\PageReferenceValue;
use MediaWiki\Page\ProperPageIdentity;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\User\User;
use Wikimedia\LightweightObjectStore\ExpirationAwareness;
use Wikimedia\ObjectCache\WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;
use WikiPage;

class ApplicationRepository {
	public const APPLICATIONS_PAGE_NAMESPACE = NS_MEDIAWIKI;
	public const APPLICATIONS_PAGE_NAME = 'RawCSS-applications';

	private const APPLICATION_REGEX = '/(*LF)^== *?([\w\-]+|\*) *?==\R((?:^===.+===\R(?:^; *?[\w-]+ *?:.+\R)*)+)/m';
	private const APPLICATION_SECTION_REGEX = '/(*LF)^=== *(.+?) *===\R((?:^; *?[\w-]+ *?:.+\R)*)/m';
	private const APPLICATION_SECTION_VARIABLE_REGEX = '/(*LF)^; *?([\w-]+) *?: *(.+?) *$/m';

	private const STYLE_PAGE_ALLOWED_REGEX = '/\/\*\s*RawCSS-allowed:\s*(.+?)\s*\*\//m';

	private PageLookup $pageLookup;
	private RevisionLookup $revisionLookup;
	private PermissionManager $permissionManager;
	private IConnectionProvider $dbProvider;
	private WANObjectCache $wanCache;

	public function __construct(
		PageLookup $pageLookup,
		RevisionLookup $revisionLookup,
		PermissionManager $permissionManager,
		IConnectionProvider $dbProvider,
		WANObjectCache $wanCache
	) {
		$this->pageLookup = $pageLookup;
		$this->revisionLookup = $revisionLookup;
		$this->permissionManager = $permissionManager;
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
		return $this->getApplications()[$id] ?? null;
	}

	private function isUsedByAnyApplication( ProperPageIdentity $pageIdentity ): bool {
		foreach ( $this->getApplications() as $application ) {
			if ( array_key_exists( $pageIdentity->getDBkey(), $application ) ) {
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
				'version' => 5,
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
			[ , $applicationId, $applicationText ] = $applicationMatch;

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
			$applications[$applicationId] = $applicationSpecification;
		}

		$lessParser = new Less_Parser();

		foreach ( $applications as $applicationId => $applicationSpecification ) {
			$application = [];

			foreach ( $applicationSpecification as $page => $variables ) {
				$styleVariables = [ 'rawcss-application-id' => $applicationId ] + $variables;
				$stylePage = $this->pageLookup->getPageByText( $page, defaultNamespace: NS_RAWCSS );
				if ( $stylePage === null ) {
					// invalid page
					continue;
				}
				if ( !$stylePage->exists() ) {
					// could exist in the future; keep this in the application so it gets purged when that page exists
					$application[$stylePage->getDBkey()] = [
						'revision' => 0,
						'variables' => $variables,
						'styles' => ''
					];
					continue;
				}

				// get the style page's content
				$stylePageRevision = $this->revisionLookup->getRevisionByTitle( $stylePage );
				$stylePageContent = $this->getStylePageRevisionContent( $stylePageRevision );
				// make sure it's valid
				if ( $stylePageContent === null ) {
					// keep this in the application so it gets purged when that page is edited
					$application[$stylePage->getDBkey()] = [
						'revision' => 0,
						'variables' => $variables,
						'styles' => ''
					];
					continue;
				}

				switch ( $stylePageContent->getModel() ) {
					case CONTENT_MODEL_LESS:
						// parse the Less
						try {
							/** @var LessContent $stylePageContent */
							$lessParser->parse( $stylePageContent->getText() );
							$lessParser->ModifyVars( $styleVariables );
							$application[$stylePage->getDBkey()] = [
								'revision' => $stylePageRevision->getId(),
								'variables' => $variables,
								'styles' => $lessParser->getCss()
							];
						} catch ( Exception ) {
							// keep this in the application so it gets purged when that page is edited
							$application[$stylePage->getDBkey()] = [
								'revision' => 0,
								'variables' => $variables,
								'styles' => ''
							];
						} finally {
							$lessParser->Reset();
						}
						break;
					case CONTENT_MODEL_CSS:
						// add it directly
						/** @var CssContent $stylePageContent */
						$application[$stylePage->getDBkey()] = [
							'revision' => $stylePageRevision->getId(),
							'variables' => $variables,
							'styles' => $stylePageContent->getText()
						];
						break;
				}
			}

			$applications[$applicationId] = $application;
		}

		return $applications;
	}

	public function getStylePageContent( ProperPageIdentity|string $page, bool $lessOnly = false ): ?Content {
		if ( is_string( $page ) ) {
			// turn this into a ProperPageIdentity
			$page = $this->pageLookup->getExistingPageByText( $page, defaultNamespace: NS_RAWCSS );

			// if the page is invalid or doesn't exist
			if ( $page === null ) {
				return null;
			}
		}

		return $this->getStylePageRevisionContent(
			$this->revisionLookup->getRevisionByTitle( $page ),
			$lessOnly
		);
	}

	private function getStylePageRevisionContent( ?RevisionRecord $revision, bool $lessOnly = false ): ?Content {
		$content = $revision?->getContent( SlotRecord::MAIN );
		// invalid if the revision or content is null
		if ( $content === null ) {
			return null;
		}

		// invalid if the page doesn't have the correct content model
		if ( !$content instanceof LessContent && !$content instanceof CssContent ) {
			return null;
		} elseif ( $lessOnly && $content instanceof CssContent ) {
			return null;
		}

		// valid
		return $content;
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

		// if this page is used in any application, purge the cache
		if ( $this->isUsedByAnyApplication( $page ) ) {
			$this->purgeCache();
		}
	}

	public function onEditFilter( User $user, WikiPage $wikiPage, Status $status ): bool {
		if ( !$this->isUsedByAnyApplication( $wikiPage ) ) {
			return true;
		}

		if ( $wikiPage->getContentModel() !== CONTENT_MODEL_CSS ) {
			return true;
		}

		if ( $this->permissionManager->userHasRight( $user, 'editinterface' ) ) {
			return true;
		}

		$contentText = $wikiPage->getContent()->getText();
		if ( !preg_match( self::STYLE_PAGE_ALLOWED_REGEX, $contentText, $allowedUsers ) ) {
			return true;
		}

		$allowedUsers = array_map( 'trim', explode( ',', $allowedUsers[1] ) );
		if ( in_array( $user->getName(), $allowedUsers, strict: true ) ) {
			return true;
		}

		$status->fatal( 'rawcss-edit-not-allowed' );
		$status->value = EditPage::AS_HOOK_ERROR_EXPECTED;
		return false;
	}
}
