<?php

$namespaceNames = [];
$namespaceAliases = [];

// without RawCSS installed
if ( !defined( 'NS_RAWCSS' ) ) {
	define( 'NS_RAWCSS', 6200 );
	define( 'NS_RAWCSS_TALK', 6201 );
}

// en (English)
$namespaceNames['en'] = [
	NS_RAWCSS => 'RawCSS',
	NS_RAWCSS_TALK => 'RawCSS_talk',
];
