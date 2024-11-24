<?php

namespace MediaWiki\Extension\RawCSS\Application;

use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use WANObjectCache;
use Wikimedia\Rdbms\Database;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * The repository to find and store RawCSS applications
 */
class ApplicationRepository {
	/**
	 * @var string The RawCSS application list page to search for applications in
	 */
	public const LIST_PAGE_TITLE = 'RawCSS-applications.json';

	private RevisionLookup $revisionLookup;
	private IConnectionProvider $databaseProvider;
	private WANObjectCache $wanCache;

	public function __construct(
		RevisionLookup $revisionLookup,
		IConnectionProvider $databaseProvider,
		WANObjectCache $wanCache
	) {
		$this->revisionLookup = $revisionLookup;
		$this->databaseProvider = $databaseProvider;
		$this->wanCache = $wanCache;
	}

	private function getCacheKey(): string {
		return $this->wanCache->makeKey( 'rawcss-applications' );
	}

	public function getApplicationIds(): array {
		return array_keys( $this->getApplications() );
	}

	public function getApplicationById( int $id ): ?array {
		// search for the correct application by checking the key with the given ID
		$filteredApplications = array_filter( $this->getApplications(),
			static fn ( $applicationId ) => $applicationId == $id, ARRAY_FILTER_USE_KEY );

		if ( !empty( $filteredApplications ) ) {
			return $filteredApplications[$id];
		} else {
			return null;
		}
	}

	public function isCoatingUsed( int $id ): bool {
		// search for applications with a coating matching the given ID
		$filteredApplications = array_filter( $this->getApplications(),
			static fn ( $application ) => in_array( $id, $application['coatings'] ) );

		return !empty( $filteredApplications );
	}

	public function getApplications(): array {
		return $this->wanCache->getWithSetCallback(
			$this->getCacheKey(),
			$this->wanCache::TTL_DAY,
			function ( $oldValue, &$ttl, array &$setOpts ) {
				$setOpts += Database::getCacheSetOptions( $this->databaseProvider->getReplicaDatabase() );

				$revisionRecord = $this->revisionLookup
					->getRevisionByTitle( Title::makeTitle( NS_MEDIAWIKI, self::LIST_PAGE_TITLE ) );
				// make sure the list page is readable
				if ( !$revisionRecord
					|| !$revisionRecord->getContent( SlotRecord::MAIN )
					|| $revisionRecord->getContent( SlotRecord::MAIN )->isEmpty() ) {
					return [];
				}

				$content = $revisionRecord->getContent( SlotRecord::MAIN );
				// make sure the list page has the correct content model
				if ( !( $content instanceof ApplicationListContent ) ) {
					return [];
				}

				$parsedContent = $content->parse();
				// make sure the list page can be parsed
				if ( !$parsedContent->isOK() ) {
					return [];
				} else {
					return $parsedContent->getValue();
				}
			},
			[
				'version' => ApplicationListContent::SCHEMA_VERSION,
				'checkKeys' => [ $this->getCacheKey() ],
				'lockTSE' => 300,
			]
		);
	}

	private function purgeCache(): void {
		$this->wanCache->touchCheckKey( $this->getCacheKey() );
	}

	public function onPageUpdate( Title $title ): void {
		// purge the cache when the MediaWiki:RawCSS-applications.json page is changed
		if ( $title->getNamespace() == NS_MEDIAWIKI && $title->getText() == self::LIST_PAGE_TITLE ) {
			$this->purgeCache();
		}
		// purge the cache if this is an in-use coating
		if ( $this->isCoatingUsed( $title->getArticleID() ) ) {
			$this->purgeCache();
		}
	}
}
