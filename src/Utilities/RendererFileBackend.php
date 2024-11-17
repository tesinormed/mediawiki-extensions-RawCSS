<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

use FileBackend;
use FileBackendGroup;
use FSFileBackend;
use MediaWiki\Config\Config;

class RendererFileBackend {
	public const CONTAINER = 'rawcss-renderer';
	private Config $mainConfig;
	private Config $extensionConfig;
	private FileBackendGroup $fileBackendGroup;

	public function __construct( Config $mainConfig, Config $extensionConfig, FileBackendGroup $fileBackendGroup ) {
		$this->mainConfig = $mainConfig;
		$this->extensionConfig = $extensionConfig;
		$this->fileBackendGroup = $fileBackendGroup;
	}

	/**
	 * Creates or gets the file backend for RawCSS
	 * @return FileBackend
	 */
	public function getFileBackend(): FileBackend {
		if ( $this->extensionConfig->has( 'RawCSSFileBackend' )
			&& !empty( $this->extensionConfig->get( 'RawCSSFileBackend' ) ) ) {
			$backend = $this->fileBackendGroup->get( $this->extensionConfig->get( 'RawCSSFileBackend' ) );
		} else {
			$backend = new FSFileBackend( [
				'name'     => 'rawcss',
				'domainId' => 'rawcss',

				'containerPaths' => [
					self::CONTAINER => $this->mainConfig->get( 'UploadDirectory' ) . '/' . self::CONTAINER
				],

				'mimeCallback' => [ $this, 'getMimeType' ],

				'directoryMode' => 0755,
				'fileMode'      => 0644,
			] );
		}

		if ( !$backend->directoryExists( [ 'dir' => $backend->getContainerStoragePath( self::CONTAINER ) ] ) ) {
			$backend->prepare( [ 'dir' => $backend->getContainerStoragePath( self::CONTAINER ) ] );
		}

		return $backend;
	}

	/**
	 * Returns the MIME type for a file in the RawCSS file backend (always <code>text/css</code>)
	 * @return string
	 */
	public function getMimeType(): string {
		return 'text/css';
	}

	/**
	 * Returns the file's URL for use in links
	 * @param string $fileName
	 * @return string
	 */
	public function getFileUrl( string $fileName ): string {
		$uploadPath = $this->mainConfig->get( 'UploadBaseUrl' )
			? $this->mainConfig->get( 'UploadBaseUrl' ) . $this->mainConfig->get( 'UploadPath' )
			: $this->mainConfig->get( 'UploadPath' );

		return $uploadPath . '/' . self::CONTAINER . '/' . $fileName;
	}
}
