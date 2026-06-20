<?php
/**
 * Lightweight CSS / JS / HTML minification.
 *
 * JS minification is deliberately conservative (comment stripping and
 * whitespace collapse with full string/regex/template-literal awareness).
 * Aggressive transforms aren't worth broken sites.
 *
 * @package PageSpeedPlus
 */

defined( 'ABSPATH' ) || exit;

class PSP_Minifier {

	/**
	 * Minify CSS.
	 *
	 * @param string $css Raw CSS.
	 * @return string
	 */
	public static function css( $css ) {
		// Remove comments (keep /*! important */ ones).
		$css = preg_replace( '~/\*(?!\!)(?:.|\n)*?\*/~', '', $css );
		// Collapse whitespace.
		$css = preg_replace( '/\s+/', ' ', $css );
		// Drop space around symbols.
		$css = preg_replace( '/\s*([{};:>+,~])\s*/', '$1', $css );
		// But keep space in "and (" media queries and calc() — restore the cases the rule above breaks.
		$css = preg_replace( '/\band\(/', 'and (', $css );
		// Remove trailing semicolons before closing brace.
		$css = str_replace( ';}', '}', $css );
		// Shorten zero units.
		$css = preg_replace( '/(?<=[:\s,(])0(?:px|em|rem|ex|ch|vw|vh|vmin|vmax|%|pt|pc|in|cm|mm)/', '0', $css );
		return trim( $css );
	}

	/**
	 * Conservatively minify JS: strip comments, collapse blank lines and
	 * indentation. Strings, template literals and regex literals are preserved.
	 *
	 * @param string $js Raw JS.
	 * @return string
	 */
	public static function js( $js ) {
		$out       = '';
		$len       = strlen( $js );
		$i         = 0;
		$last_code = ''; // Last meaningful char, used to disambiguate regex vs division.

		while ( $i < $len ) {
			$c    = $js[ $i ];
			$next = ( $i + 1 < $len ) ? $js[ $i + 1 ] : '';

			// String literals.
			if ( '"' === $c || "'" === $c || '`' === $c ) {
				$quote = $c;
				$out  .= $c;
				$i++;
				while ( $i < $len ) {
					$out .= $js[ $i ];
					if ( '\\' === $js[ $i ] && $i + 1 < $len ) {
						$out .= $js[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( $js[ $i ] === $quote ) {
						$i++;
						break;
					}
					$i++;
				}
				$last_code = $quote;
				continue;
			}

			// Line comment.
			if ( '/' === $c && '/' === $next ) {
				while ( $i < $len && "\n" !== $js[ $i ] ) {
					$i++;
				}
				continue;
			}

			// Block comment (keep /*! license */).
			if ( '/' === $c && '*' === $next ) {
				$end     = strpos( $js, '*/', $i + 2 );
				$end     = ( false === $end ) ? $len : $end + 2;
				$comment = substr( $js, $i, $end - $i );
				if ( 0 === strpos( $comment, '/*!' ) ) {
					$out .= $comment;
				} else {
					$out .= ' '; // A comment can act as a token separator.
				}
				$i = $end;
				continue;
			}

			// Regex literal: a "/" where an expression is expected.
			if ( '/' === $c && preg_match( '/[\(\[{=,:;!&|?+\-*%~^<>]|^$|return|typeof|case|in|of|new|delete|void|do|else/', $last_code ) ) {
				$out .= $c;
				$i++;
				$in_class = false;
				while ( $i < $len ) {
					$out .= $js[ $i ];
					if ( '\\' === $js[ $i ] && $i + 1 < $len ) {
						$out .= $js[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( '[' === $js[ $i ] ) {
						$in_class = true;
					} elseif ( ']' === $js[ $i ] ) {
						$in_class = false;
					} elseif ( '/' === $js[ $i ] && ! $in_class ) {
						$i++;
						break;
					} elseif ( "\n" === $js[ $i ] ) {
						$i++;
						break; // Not a regex after all; bail without damage.
					}
					$i++;
				}
				$last_code = '/';
				continue;
			}

			$out .= $c;
			if ( ! ctype_space( $c ) ) {
				// Track the trailing word for keyword-based regex detection.
				if ( ctype_alnum( $c ) || '_' === $c || '$' === $c ) {
					$last_code = preg_match( '/[a-zA-Z0-9_$]+$/', substr( $out, -10 ), $m ) ? $m[0] : $c;
				} else {
					$last_code = $c;
				}
			}
			$i++;
		}

		// Collapse indentation and blank lines. Newlines are kept — they can be
		// semantically meaningful (ASI), so we never join lines.
		$out = preg_replace( '/[ \t]+/', ' ', $out );
		$out = preg_replace( '/ ?\n ?/', "\n", $out );
		$out = preg_replace( '/\n{2,}/', "\n", $out );

		return trim( $out );
	}

	/**
	 * Minify HTML. Preserves <pre>, <textarea>, <script>, <style> contents.
	 *
	 * @param string $html Raw HTML.
	 * @return string
	 */
	public static function html( $html ) {
		$preserved = array();

		// Pull out blocks whose whitespace matters.
		$html = preg_replace_callback(
			'#<(pre|textarea|script|style)\b[^>]*>.*?</\1>#is',
			function ( $m ) use ( &$preserved ) {
				$key               = '<!--PSP-PRESERVE-' . count( $preserved ) . '-->';
				$preserved[ $key ] = $m[0];
				return $key;
			},
			$html
		);

		// Remove HTML comments (keep conditional comments and our placeholders).
		$html = preg_replace( '/<!--(?!\[if|<!|PSP-PRESERVE)(?!.*?(noptimize|noindex)).*?-->/s', '', $html );
		// Collapse whitespace between tags and runs of whitespace.
		$html = preg_replace( '/>\s+</', '> <', $html );
		$html = preg_replace( '/\s{2,}/', ' ', $html );

		// Restore preserved blocks.
		if ( $preserved ) {
			$html = strtr( $html, $preserved );
		}

		return trim( $html );
	}
}
