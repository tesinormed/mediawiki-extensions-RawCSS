<?php

use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\MediaWikiServices;

return [
	'RawCSS.ApplicationRepository' => static function ( MediaWikiServices $services ): ApplicationRepository {
		$pageStore = $services->getPageStore();
		$revisionLookup = $services->getRevisionLookup();
		$databaseProvider = $services->getConnectionProvider();
		$wanCache = $services->getMainWANObjectCache();
		return new ApplicationRepository( $pageStore, $revisionLookup, $databaseProvider, $wanCache );
	},
];
