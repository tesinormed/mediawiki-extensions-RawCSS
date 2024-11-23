<?php

namespace MediaWiki\Extension\RawCSS\Application;

use CssContent;
use Exception;
use JsonContent;
use Less_Parser;
use MediaWiki\Extension\RawCSS\Less\LessContent;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use stdClass;
use Throwable;

/**
 * The {@link Content} for the RawCSS application list page
 */
class ApplicationListContent extends JsonContent {
	/**
	 * @var int The application list schema version (used for the caching in {@link ApplicationRepository})
	 */
	public const SCHEMA_VERSION = 1;

	public function __construct( string $text, string $modelId = CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
		parent::__construct( $text, $modelId );
	}

	public function parse(): Status {
		// turn it into something we can read
		$data = json_decode( $this->getText() );
		// make sure the JSON is an object
		if ( !( $data instanceof stdClass ) ) {
			return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
				'.', 'object'
			);
		}

		// iterate through each application
		$applications = [];
		$status = new Status();
		foreach ( get_object_vars( $data ) as $base => $specification ) {
			// make sure the specification is an object
			if ( !( $specification instanceof stdClass ) ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
					'.' . $base, 'object'
				);
			}

			// parse the application
			$application = $this->parseApplication( $base, get_object_vars( $specification ) );
			// if there's a fatal error
			if ( !$application->isGood() ) {
				foreach ( $application->getErrors() as $error ) {
					// append the error and downgrade it from fatal to error
					$status->error( $error['message'], ...$error['params'] );
				}
			} else {
				// append to the list (no renumbering)
				$applications = $applications + $application->getValue();
			}
		}
		$status->value = $applications;
		return $status;
	}

	private function parseApplication( string $base, mixed $specification ): Status {
		// base is the template to apply these coatings to
		// ignore base of * (default for any page without any templates that are bases)
		if ( $base != '*' ) {
			// make sure the base exists, isn't external, and is in the Template namespace
			$baseTitle = Title::makeTitleSafe( NS_TEMPLATE, $base );
			if ( !$baseTitle || !$baseTitle->exists() || $baseTitle->isExternal() ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-base',
					$baseTitle->getPrefixedText(), wfEscapeWikiText( $baseTitle->getPrefixedText() )
				);
			}
			$baseArticleID = $baseTitle->getArticleID();
		} else {
			$baseArticleID = 0;
		}

		$lessParser = new Less_Parser( [ 'compress' => true, 'relativeUrls' => false ] );

		$variables = [];
		// check if the variables property exists
		if ( array_key_exists( 'variables', $specification ) ) {
			// make sure the variable property is an object
			if ( !( $specification['variables'] instanceof stdClass ) ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
					'.' . $base . '.' . 'variables', 'object'
				);
			}

			// iterate through the variable property
			foreach ( get_object_vars( $specification['variables'] ) as $name => $value ) {
				// add the variable
				$variables[$name] = $value;
			}
		}
		try {
			$lessParser->ModifyVars( $variables );
			$lessParser->Reset();
		} catch ( Exception ) {
			return Status::newFatal( 'rawcss-application-list-validation-invalid-variables',
				'.' . $base
			);
		}

		// make sure the coatings property exists
		if ( !array_key_exists( 'coatings', $specification ) ) {
			return Status::newFatal( 'rawcss-application-list-validation-missing-data',
				'.' . $base . '.' . 'coatings'
			);
		}
		// make sure the coatings property is an array
		if ( !is_array( $specification['coatings'] ) ) {
			return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
				'.' . $base . '.' . 'coatings', 'array'
			);
		}
		// make sure the coatings property has at least one element
		if ( empty( $specification['coatings'] ) ) {
			return Status::newFatal( 'rawcss-application-list-validation-missing-data',
				'.' . $base . '.' . 'coatings' . '[]'
			);
		}

		// iterate through each coating
		$revisionLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		$coatingArticleIDs = [];
		$styleSheets = [];
		foreach ( $specification['coatings'] as $coating ) {
			$coatingTitle = Title::newFromText( $coating, defaultNamespace: NS_RAWCSS );
			// make sure the coating exists and isn't outside of this wiki
			if ( !$coatingTitle || !$coatingTitle->exists() || $coatingTitle->isExternal() ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-coating',
					$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
				);
			}
			// make sure the coating is in the RawCSS or Template namespace
			if ( $coatingTitle->getNamespace() != NS_RAWCSS
				&& $coatingTitle->getNamespace() != NS_TEMPLATE ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-coating',
					$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
				);
			}

			$coatingRevisionRecord = $revisionLookup->getRevisionByTitle( $coatingTitle );
			if ( !$coatingRevisionRecord
				|| !$coatingRevisionRecord->getContent( SlotRecord::MAIN )
				|| $coatingRevisionRecord->getContent( SlotRecord::MAIN )->isEmpty() ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-coating',
					$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
				);
			}

			$coatingContent = $coatingRevisionRecord->getContent( SlotRecord::MAIN );
			if ( $coatingContent instanceof LessContent ) {
				try {
					$lessParser->ModifyVars( $variables );
					$lessParser->parse( $coatingContent->getText() );
					$styleSheets[] = $lessParser->getCss();
					$lessParser->Reset();
					$coatingArticleIDs[] = $coatingTitle->getArticleID();
				} catch ( Throwable ) {
					return Status::newFatal( 'rawcss-application-list-validation-invalid-coating',
						$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
					);
				}
			} elseif ( $coatingContent instanceof CssContent ) {
				$styleSheets[] = $coatingContent->getText();
				$coatingArticleIDs[] = $coatingTitle->getArticleID();
			} else {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-coating',
					$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
				);
			}
		}

		$preloadDirectives = [];
		// check if the preload property exists
		if ( array_key_exists( 'preload', $specification ) ) {
			// make sure the preload property is an array
			if ( !is_array( $specification['preload'] ) ) {
				return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
					'.' . $base . '.' . 'preload', 'array'
				);
			}

			// iterate through the preload property
			foreach ( $specification['preload'] as $index => $preloadDirective ) {
				// make sure the preload directive is a object
				if ( !( $preloadDirective instanceof stdClass ) ) {
					return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
						'.' . $base . '.' . 'preload' . '[' . $index . ']', 'object'
					);
				}

				// make sure the href property exists
				if ( !property_exists( $preloadDirective, 'href' ) ) {
					return Status::newFatal( 'rawcss-application-list-validation-missing-data',
						'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'href'
					);
				}
				// sanitise the URL
				$href = filter_var( $preloadDirective->{'href'}, FILTER_SANITIZE_URL );
				// validate the URL
				if ( filter_var( $href, FILTER_VALIDATE_URL ) === false ) {
					return Status::newFatal( 'rawcss-application-list-validation-invalid-data-type',
						'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'href', 'URL'
					);
				}

				// make sure the as property exists
				if ( !property_exists( $preloadDirective, 'as' ) ) {
					return Status::newFatal( 'rawcss-application-list-validation-missing-data',
						'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'as'
					);
				}
				$preloadDirectives[$href]['as'] = $preloadDirective->as;

				if ( property_exists( $preloadDirective, 'type' ) ) {
					$preloadDirectives[$href]['type'] = $preloadDirective->type;
				}
				if ( property_exists( $preloadDirective, 'media' ) ) {
					$preloadDirectives[$href]['media'] = $preloadDirective->media;
				}
				if ( property_exists( $preloadDirective, 'crossorigin' ) && $preloadDirective->crossorigin ) {
					$preloadDirectives[$href]['crossorigin'] = 'anonymous';
				}
			}
		}

		return Status::newGood( [ $baseArticleID => [
			'styles' => $styleSheets,
			'coatings' => $coatingArticleIDs,
			'variables' => $variables,
			'preload' => $preloadDirectives
		] ] );
	}
}
