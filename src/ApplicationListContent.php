<?php

namespace MediaWiki\Extension\RawCSS;

use JsonContent;
use MediaWiki\Title\Title;
use StatusValue;
use stdClass;

class ApplicationListContent extends JsonContent {
	public const SCHEMA_VERSION = 0;

	public function __construct( string $text, string $modelId = CONTENT_MODEL_RAWCSS_APPLICATION_LIST ) {
		parent::__construct( $text, $modelId );
	}

	public function parse(): StatusValue {
		return self::parseJson( $this->getText() );
	}

	public static function parseJson( string $json ): StatusValue {
		// turn it into something we can read
		$data = json_decode( $json );

		// make sure the JSON is an object
		if ( !( $data instanceof stdClass ) ) {
			return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
				'.', 'object'
			);
		}

		// iterate through each application
		$applications = [];
		foreach ( get_object_vars( $data ) as $base => $specification ) {
			// base is the template to apply these coatings to
			// ignore base of * (default for any page without any templates that are bases)
			if ( $base != '*' ) {
				// make sure the base exists, isn't external, and is in the Template namespace
				$baseTitle = Title::newFromText( $base, defaultNamespace: NS_TEMPLATE );
				if ( !$baseTitle || !$baseTitle->exists()
					|| $baseTitle->isExternal()
					|| $baseTitle->getNamespace() != NS_TEMPLATE ) {
					return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-base',
						$baseTitle->getPrefixedText(), wfEscapeWikiText( $baseTitle->getPrefixedText() )
					);
				}
				$basePageId = $baseTitle->getArticleID();
			} else {
				$basePageId = 0;
			}

			// make sure the specification is an object
			if ( !( $specification instanceof stdClass ) ) {
				return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
					'.' . $base, 'object'
				);
			}

			$specification = get_object_vars( $specification );

			// make sure the coatings property exists
			if ( !array_key_exists( 'coatings', $specification ) ) {
				return StatusValue::newFatal( 'rawcss-application-list-validation-missing-data',
					'.' . $base . '.' . 'coatings'
				);
			}

			// make sure the coatings property is an array
			if ( !is_array( $specification['coatings'] ) ) {
				return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
					'.' . $base . '.' . 'coatings', 'array'
				);
			}

			// make sure the coatings property has at least one element
			if ( count( $specification['coatings'] ) === 0 ) {
				return StatusValue::newFatal( 'rawcss-application-list-validation-missing-data',
					'.' . $base . '.' . 'coatings' . '[]'
				);
			}

			// iterate through each coating
			$coatings = [];
			foreach ( $specification['coatings'] as $coating ) {
				// make sure the coating exists, isn't external, is in either the RawCSS or Template namespace, and has the correct content model
				$coatingTitle = Title::newFromText( $coating, defaultNamespace: NS_RAWCSS );
				if ( !$coatingTitle || !$coatingTitle->exists()
					|| $coatingTitle->isExternal()
					|| (
						$coatingTitle->getNamespace() != NS_RAWCSS
						&& $coatingTitle->getNamespace() != NS_TEMPLATE
					)
					|| $coatingTitle->getContentModel() != CONTENT_MODEL_CSS
				) {
					return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-coating',
						$coatingTitle->getPrefixedText(), wfEscapeWikiText( $coatingTitle->getPrefixedText() )
					);
				}

				// add the coating
				$coatings[] = $coatingTitle->getPrefixedText();
			}

			$variables = [];
			// check if the variables property exists
			if ( array_key_exists( 'variables', $specification ) ) {
				// make sure the variable property is an object
				if ( !( $specification['variables'] instanceof stdClass ) ) {
					return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
						'.' . $base . '.' . 'variables', 'object'
					);
				}

				// iterate through the variable property
				foreach ( get_object_vars( $specification['variables'] ) as $name => $value ) {
					// make sure the variable name is valid for CSS
					if ( !preg_match( '/^[a-z-0-9,\s]+$/m', $name ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-variable-name',
							'.' . $base, $name
						);
					}
					// make sure the variable name doesn't start with --
					if ( str_starts_with( $name, '--' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-variable-name',
							'.' . $base, $name
						);
					}

					// make sure the variable value doesn't contain ;
					if ( str_contains( $value, ';' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-variable-value',
							'.' . $base, $value
						);
					}
					// make sure the variable value doesn't contain */
					if ( str_contains( $value, '*/' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-variable-value',
							'.' . $base, $value
						);
					}
					// make sure the variable value doesn't contain /*
					if ( str_contains( $value, '/*' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-variable-value',
							'.' . $base, $value
						);
					}

					// add the variable
					$variables[$name] = $value;
				}
			}

			$preloadDirectives = [];
			// check if the preload property exists
			if ( array_key_exists( 'preload', $specification ) ) {
				// make sure the preload property is an array
				if ( !is_array( $specification['preload'] ) ) {
					return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
						'.' . $base . '.' . 'preload', 'array'
					);
				}

				// iterate through the preload property
				foreach ( $specification['preload'] as $index => $preloadDirective ) {
					// make sure the preload directive is a object
					if ( !( $preloadDirective instanceof stdClass ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
							'.' . $base . '.' . 'preload' . '[' . $index . ']', 'object'
						);
					}

					// make sure the href property exists
					if ( !property_exists( $preloadDirective, 'href' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-missing-data',
							'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'href'
						);
					}
					// sanitise the URL
					$href = filter_var( $preloadDirective->{'href'}, FILTER_SANITIZE_URL );
					// validate the URL
					if ( filter_var( $href, FILTER_VALIDATE_URL ) === false ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-invalid-data-type',
							'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'href', 'URL'
						);
					}

					// make sure the as property exists
					if ( !property_exists( $preloadDirective, 'as' ) ) {
						return StatusValue::newFatal( 'rawcss-application-list-validation-missing-data',
							'.' . $base . '.' . 'preload' . '[' . $index . ']' . '.' . 'as'
						);
					}

					// add the preload directive
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

			$applications[] = [
				'base' => $basePageId,
				'coatings' => $coatings,
				'variables' => $variables,
				'preload' => $preloadDirectives
			];
		}
		return StatusValue::newGood( $applications );
	}
}
