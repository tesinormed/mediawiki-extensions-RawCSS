<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

use InvalidArgumentException;
use Latte\ContentType;
use Latte\Engine;
use Latte\Sandbox\SecurityPolicy;
use MediaWiki\Config\Config;
use WikiPage;

class TemplateEngine {
	private Config $mainConfig;
	private Config $extensionConfig;

	public function __construct( Config $mainConfig, Config $extensionConfig ) {
		$this->mainConfig = $mainConfig;
		$this->extensionConfig = $extensionConfig;
	}

	/**
	 * Returns the correct Latte cache directory based on the current configuration
	 * @return string the Latte cache directory
	 */
	public function getLatteCachePath(): string {
		if ( !empty( $this->extensionConfig->get( 'RawCSSLatteCacheDirectory' ) ) ) {
			return $this->extensionConfig->get( 'RawCSSLatteCacheDirectory' );
		} elseif ( $this->mainConfig->has( 'CacheDirectory' )
			&& !empty( $this->mainConfig->get( 'CacheDirectory' ) ) ) {
			return $this->mainConfig->get( 'CacheDirectory' )
				. DIRECTORY_SEPARATOR . 'RawCSS'
				. DIRECTORY_SEPARATOR . 'Latte';
		} else {
			return MW_INSTALL_PATH
				. DIRECTORY_SEPARATOR . 'cache'
				. DIRECTORY_SEPARATOR . 'RawCSS'
				. DIRECTORY_SEPARATOR . 'Latte';
		}
	}

	private function createLattePolicy(): SecurityPolicy {
		$policy = SecurityPolicy::createSafePolicy();
		$policy->allowFilters( [ 'dataStream', 'noEscape', 'noCheck' ] );
		return $policy;
	}

	/**
	 * Returns the correctly configured Latte engine
	 * @return Engine the Latte engine
	 */
	public function createLatteEngine(): Engine {
		$latte = new Engine();
		$tempDirectory = self::getLatteCachePath();
		if ( !file_exists( $tempDirectory ) ) {
			mkdir( $tempDirectory, permissions: 0755, recursive: true );
		}
		$latte->setTempDirectory( $tempDirectory );
		$latte->setAutoRefresh( false );
		$latte->setStrictParsing();
		$latte->setStrictTypes();
		$latte->setPolicy( self::createLattePolicy() );
		$latte->setContentType( ContentType::Css );
		return $latte;
	}

	/**
	 * Returns the correct path for the Latte template of a RawCSS style sheet
	 * @param WikiPage $wikiPage
	 * @return string the path of the Latte template
	 */
	public function getLatteTemplatePath( WikiPage $wikiPage ): string {
		if ( $wikiPage->getNamespace() != NS_RAWCSS ) {
			throw new InvalidArgumentException( '$wikiPage is not in the NS_RAWCSS namespace' );
		}

		$titleText = $wikiPage->getTitle()->getText();
		$uniqueId = $wikiPage->getRevisionRecord()->getId() ?: sha1( $wikiPage->getContent()->getText() );
		return self::getLatteCachePath() . DIRECTORY_SEPARATOR . $titleText . '#' . $uniqueId . '.latte';
	}

	/**
	 * Writes the Latte template of a RawCSS style sheet and warms up the Latte cache (precompile it)
	 * @param WikiPage $wikiPage
	 * @return void
	 */
	public function writeLatteTemplate( WikiPage $wikiPage ): void {
		// implied: the namespace is correct
		$path = self::getLatteTemplatePath( $wikiPage );
		$contentText = $wikiPage->getContent()->getText();
		// force syntax to be double
		$contentText = '{syntax double}' . $contentText . '{/syntax}';
		// remove the hack of /*#*/unset/*{{$color}}*/
		$contentText = preg_replace( '%(?:/\*(.+)\*/)?unset/\*({{.+}})+\*/%m', '$1$2', $contentText );
		// remove the hack of /*{{$color}}*/
		$contentText = preg_replace( '%/\*({{.+}})+\*/%m', '$1', $contentText );
		file_put_contents( $path, $contentText );

		// pre-generate the cache
		self::createLatteEngine()->warmupCache( $path );
	}
}
