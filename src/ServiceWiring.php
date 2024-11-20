<?php

use MediaWiki\Extension\RawCSS\ApplicationRepository;
use MediaWiki\MediaWikiServices;

return [
	'RawCSS.ApplicationRepository' => static function ( MediaWikiServices $services ): ApplicationRepository {
		$revisionLookup = $services->getRevisionLookup();
		$databaseProvider = $services->getConnectionProvider();
		$wanCache = $services->getMainWANObjectCache();
		return new ApplicationRepository( $revisionLookup, $databaseProvider, $wanCache );
	},
];
