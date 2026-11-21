<?php

declare(strict_types=1);

// Stubs copied verbatim from WordPress 7.1.4: wp-includes/http.php (wp_parse_url and its helpers)
// and wp-includes/class-wp-http.php (WP_Http::make_absolute_url only).

function wp_parse_url( $url, $component = -1 ) {
	$to_unset = array();
	$url      = (string) $url;

	if ( str_starts_with( $url, '//' ) ) {
		$to_unset[] = 'scheme';
		$url        = 'placeholder:' . $url;
	} elseif ( str_starts_with( $url, '/' ) ) {
		$to_unset[] = 'scheme';
		$to_unset[] = 'host';
		$url        = 'placeholder://placeholder' . $url;
	}

	$parts = parse_url( $url );

	if ( false === $parts ) {
		// Parsing failure.
		return $parts;
	}

	// Remove the placeholder values.
	foreach ( $to_unset as $key ) {
		unset( $parts[ $key ] );
	}

	return _get_component_from_parsed_url_array( $parts, $component );
}

/**
 * Retrieves a specific component from a parsed URL array.
 *
 * @internal
 *
 * @since 4.7.0
 * @access private
 *
 * @link https://www.php.net/manual/en/function.parse-url.php
 *
 * @param array|false $url_parts The parsed URL. Can be false if the URL failed to parse.
 * @param int         $component The specific component to retrieve. Use one of the PHP
 *                               predefined constants to specify which one.
 *                               Defaults to -1 (= return all parts as an array).
 * @return mixed False on parse failure; Array of URL components on success;
 *               When a specific component has been requested: null if the component
 *               doesn't exist in the given URL; a string or - in the case of
 *               PHP_URL_PORT - integer when it does. See parse_url()'s return values.
 *
 * @phpstan-param false|array{
 *     scheme?: string,
 *     host?: string,
 *     port?: int<0, 65535>,
 *     user?: string,
 *     pass?: string,
 *     path?: string,
 *     query?: string,
 *     fragment?: string,
 * } $url_parts
 * @phpstan-param int<-1, 7> $component
 * @phpstan-return (
 *     $component is -1
 *         ? false|array{
 *               scheme?: string,
 *               host?: string,
 *               port?: int<0, 65535>,
 *               user?: string,
 *               pass?: string,
 *               path?: string,
 *               query?: string,
 *               fragment?: string,
 *           }
 *         : (
 *             $component is 2
 *                 ? int<0, 65535>|null
 *                 : string|null
 *         )
 * )
 */
function _get_component_from_parsed_url_array( $url_parts, $component = -1 ) {
	if ( -1 === $component ) {
		return $url_parts;
	}

	$key = _wp_translate_php_url_constant_to_key( $component );
	if ( false !== $key && is_array( $url_parts ) && isset( $url_parts[ $key ] ) ) {
		return $url_parts[ $key ];
	} else {
		return null;
	}
}

/**
 * Translates a PHP_URL_* constant to the named array keys PHP uses.
 *
 * @internal
 *
 * @since 4.7.0
 * @access private
 *
 * @link https://www.php.net/manual/en/url.constants.php
 *
 * @param int $constant PHP_URL_* constant.
 * @return string|false The named key or false.
 *
 * @phpstan-param int<-1, 7> $constant
 * @phpstan-return 'scheme'|'host'|'port'|'user'|'pass'|'path'|'query'|'fragment'|false
 */
function _wp_translate_php_url_constant_to_key( $constant ) {
	$translation = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);

	return $translation[ $constant ] ?? false;
}

class WP_Http {
	/**
	 * Converts a relative URL to an absolute URL relative to a given URL.
	 *
	 * If an Absolute URL is provided, no processing of that URL is done.
	 *
	 * @since 3.4.0
	 *
	 * @param string $maybe_relative_path The URL which might be relative.
	 * @param string $url                 The URL which $maybe_relative_path is relative to.
	 * @return string An Absolute URL, in a failure condition where the URL cannot be parsed, the relative URL will be returned.
	 */
	public static function make_absolute_url( $maybe_relative_path, $url ) {
		if ( empty( $url ) ) {
			return $maybe_relative_path;
		}

		$url_parts = wp_parse_url( $url );
		if ( ! $url_parts ) {
			return $maybe_relative_path;
		}

		$relative_url_parts = wp_parse_url( $maybe_relative_path );
		if ( ! $relative_url_parts ) {
			return $maybe_relative_path;
		}

		// Check for a scheme on the 'relative' URL.
		if ( ! empty( $relative_url_parts['scheme'] ) ) {
			return $maybe_relative_path;
		}

		$absolute_path = $url_parts['scheme'] . '://';

		// Schemeless URLs will make it this far, so we check for a host in the relative URL
		// and convert it to a protocol-URL.
		if ( isset( $relative_url_parts['host'] ) ) {
			$absolute_path .= $relative_url_parts['host'];
			if ( isset( $relative_url_parts['port'] ) ) {
				$absolute_path .= ':' . $relative_url_parts['port'];
			}
		} else {
			$absolute_path .= $url_parts['host'];
			if ( isset( $url_parts['port'] ) ) {
				$absolute_path .= ':' . $url_parts['port'];
			}
		}

		// Start off with the absolute URL path.
		$path = ! empty( $url_parts['path'] ) ? $url_parts['path'] : '/';

		// If it's a root-relative path, then great.
		if ( ! empty( $relative_url_parts['path'] ) && '/' === $relative_url_parts['path'][0] ) {
			$path = $relative_url_parts['path'];

			// Else it's a relative path.
		} elseif ( ! empty( $relative_url_parts['path'] ) ) {
			// Strip off any file components from the absolute path.
			$path = substr( $path, 0, strrpos( $path, '/' ) + 1 );

			// Build the new path.
			$path .= $relative_url_parts['path'];

			// Strip all /path/../ out of the path.
			while ( strpos( $path, '../' ) > 1 ) {
				$path = preg_replace( '![^/]+/\.\./!', '', $path, -1, $segment_replacement_count );
				if ( 0 === $segment_replacement_count ) {
					break;
				}
			}

			// Strip any final leading ../ from the path.
			$path = preg_replace( '!^/(\.\./)+!', '', $path );
		}

		// Add the query string.
		if ( ! empty( $relative_url_parts['query'] ) ) {
			$path .= '?' . $relative_url_parts['query'];
		}

		// Add the fragment.
		if ( ! empty( $relative_url_parts['fragment'] ) ) {
			$path .= '#' . $relative_url_parts['fragment'];
		}

		return $absolute_path . '/' . ltrim( $path, '/' );
	}
}
