<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

use Content;
use InvalidArgumentException;
use Latte\ContentType;
use Latte\Engine;
use Latte\Sandbox\SecurityPolicy;
use MediaWiki\Config\Config;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Title\Title;

class TemplateEngine {
	private Config $mainConfig;
	private Config $extensionConfig;

	public function __construct( Config $mainConfig, Config $extensionConfig ) {
		$this->mainConfig = $mainConfig;
		$this->extensionConfig = $extensionConfig;
		$latteCachePath = self::getLatteCachePath();
		if ( !file_exists( $latteCachePath ) ) {
			mkdir( $latteCachePath, permissions: 0755, recursive: true );
		}
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
		if ( $this->extensionConfig->has( 'RawCSSLatteSecurityPolicy' )
			&& !empty( $this->extensionConfig->get( 'RawCSSLatteSecurityPolicy' ) ) ) {
			return $this->extensionConfig->get( 'RawCSSLatteSecurityPolicy' );
		} else {
			return SecurityPolicy::createSafePolicy();
		}
	}

	/**
	 * Returns the correctly configured Latte engine
	 * @return Engine the Latte engine
	 */
	public function createLatteEngine(): Engine {
		$latte = new Engine();
		$latte->setPolicy( self::createLattePolicy() );
		$latte->setSandboxMode();
		$latte->setContentType( ContentType::Css );
		$latte->setTempDirectory( self::getLatteCachePath() );
		$latte->setAutoRefresh( false );
		$latte->setStrictTypes();
		$latte->setStrictParsing();
		return $latte;
	}

	/**
	 * Returns the correct path for the Latte template of a RawCSS style sheet
	 * @param Title $title
	 * @param RevisionRecord|null $identifier
	 * @return string the path of the Latte template
	 */
	public function getLatteTemplatePath( Title $title, ?RevisionRecord $identifier ): string {
		if ( $title->getNamespace() !== NS_RAWCSS ) {
			throw new InvalidArgumentException( '$title is not in the RawCSS namespace' );
		}

		$titleText = $title->getText();
		if ( $identifier !== null ) {
			$suffix = $identifier->getId();
		} else {
			$suffix = 'T' . time();
		}
		return self::getLatteCachePath() . DIRECTORY_SEPARATOR . $titleText . '#' . $suffix . '.latte';
	}

	/**
	 * Writes the Latte template of a RawCSS style sheet and warms up the Latte cache (precompile it)
	 * @param Title $title
	 * @param RevisionRecord|null $identifier
	 * @param Content $content
	 * @return void
	 */
	public function writeLatteTemplate( Title $title, ?RevisionRecord $identifier, Content $content ): void {
		$path = self::getLatteTemplatePath( $title, $identifier );
		$contentText = $content->getText();
		// replace `/*whatever1*/ unset /*{whatever2}*/` with `whatever1{whatever2}`
		$contentText = preg_replace( '%(?:/\*(.+)\*/)? ?unset ?/\*(\{.+})+\*/%m', '$1$2', $contentText );
		// replace `/*{whatever}*/ with `{whatever}`
		$contentText = preg_replace( '%/\*(\{.+})+\*/%m', '$1', $contentText );
		file_put_contents( $path, $contentText );

		// pre-generate the cache
		self::createLatteEngine()->warmupCache( $path );
	}
}
