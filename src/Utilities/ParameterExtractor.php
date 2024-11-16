<?php

namespace MediaWiki\Extension\RawCSS\Utilities;

use PPFrame;

class ParameterExtractor {
	/**
	 * Takes an array of raw parameters (<code>['a=b', 'c=d', 'e']</code>) and turns it into
	 * a map of parameter names to values (<code>['a' => 'b', 'c' => 'd', 'e' => true]</code>)
	 * @param array $input
	 * @param PPFrame $frame
	 * @return array
	 */
	public static function extractParameters( array $input, PPFrame $frame ): array {
		$output = [];
		foreach ( $input as $rawParameter ) {
			$splitParameter = array_map( 'trim', explode( '=', trim( $frame->expand( $rawParameter ) ), limit: 2 ) );
			switch ( count( $splitParameter ) ) {
				case 0:
					continue 2;
				case 1:
					$output[$splitParameter[0]] = true;
					break;
				case 2:
					$output[$splitParameter[0]] = $splitParameter[1];
					break;
			}
		}
		return $output;
	}
}
