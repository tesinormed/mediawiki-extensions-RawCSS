<?php

use MediaWiki\Extension\RawCSS\Application\ApplicationRepository;
use MediaWiki\MediaWikiServices;

return [
	'RawCSS.ApplicationRepository' => static function ( MediaWikiServices $services ): ApplicationRepository {
		return new ApplicationRepository(
			$services->getPageStore(),
			$services->getRevisionStore(),
			$services->getConnectionProvider(),
			$services->getMainWANObjectCache()
		);
	},
];
