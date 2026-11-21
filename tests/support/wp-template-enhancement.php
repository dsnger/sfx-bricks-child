<?php
/**
 * WordPress' template enhancement output buffer and wp_ob_end_flush_all(), for the request-level
 * Markdown test (tests/markdown-fpm-test.php), which runs without WordPress core. Copied verbatim
 * from WordPress 7.1.5: wp-includes/template.php:897-1183
 * (wp_should_output_buffer_template_for_enhancement, wp_start_template_enhancement_output_buffer,
 * wp_finalize_template_enhancement_output_buffer) and wp-includes/functions.php:5571-5583
 * (wp_ob_end_flush_all), GPL like WordPress. Refresh them together when WordPress changes them.
 *
 * Exempt from the "declare(strict_types=1) in new PHP files" rule (owner decision 2026-10-12, as
 * for html-api.php): the copies stay byte-identical to WordPress.
 */

/**
 * Checks whether the template should be output buffered for enhancement.
 *
 * By default, an output buffer is only started if a {@see 'wp_template_enhancement_output_buffer'} filter has been
 * added by the time a template is included at the {@see 'wp_before_include_template'} action. This allows template
 * responses to be streamed as much as possible when no template enhancements are registered to apply.
 *
 * @since 6.9.0
 *
 * @return bool Whether the template should be output-buffered for enhancement.
 */
function wp_should_output_buffer_template_for_enhancement(): bool {
	/**
	 * Filters whether the template should be output-buffered for enhancement.
	 *
	 * By default, an output buffer is only started if a {@see 'wp_template_enhancement_output_buffer'} filter has been
	 * added or if a plugin has added a {@see 'wp_finalized_template_enhancement_output_buffer'} action. For this
	 * default to apply, either of the hooks must be added by the time the template is included at the
	 * {@see 'wp_before_include_template'} action. This allows template responses to be streamed unless the there is
	 * code which depends on an output buffer being opened. This filter allows a site to opt in to adding such template
	 * enhancement filters later during the rendering of the template.
	 *
	 * @since 6.9.0
	 *
	 * @param bool $use_output_buffer Whether an output buffer is started.
	 */
	return (bool) apply_filters( 'wp_should_output_buffer_template_for_enhancement', has_filter( 'wp_template_enhancement_output_buffer' ) || has_action( 'wp_finalized_template_enhancement_output_buffer' ) );
}

/**
 * Starts the template enhancement output buffer.
 *
 * This function is called immediately before the template is included.
 *
 * @since 6.9.0
 *
 * @return bool Whether the output buffer successfully started.
 */
function wp_start_template_enhancement_output_buffer(): bool {
	if ( ! wp_should_output_buffer_template_for_enhancement() ) {
		return false;
	}

	$started = ob_start(
		'wp_finalize_template_enhancement_output_buffer',
		0, // Unlimited buffer size so that entire output is passed to the filter.
		/*
		 * Instead of the default PHP_OUTPUT_HANDLER_STDFLAGS (cleanable, flushable, and removable) being used for
		 * flags, the PHP_OUTPUT_HANDLER_FLUSHABLE flag must be omitted. If the buffer were flushable, then each time
		 * that ob_flush() is called, a fragment of the output would be sent into the output buffer callback. This
		 * output buffer is intended to capture the entire response for processing, as indicated by the chunk size of 0.
		 * So the buffer does not allow flushing to ensure the entire buffer can be processed, such as for optimizing an
		 * entire HTML document, where markup in the HEAD may need to be adjusted based on markup that appears late in
		 * the BODY.
		 *
		 * If this ends up being problematic, then PHP_OUTPUT_HANDLER_FLUSHABLE could be added to the $flags and the
		 * output buffer callback could check if the phase is PHP_OUTPUT_HANDLER_FLUSH and abort any subsequent
		 * processing while also emitting a _doing_it_wrong().
		 *
		 * The output buffer needs to be removable because WordPress calls wp_ob_end_flush_all() and then calls
		 * wp_cache_close(). If the buffers are not all flushed before wp_cache_close() is closed, then some output buffer
		 * handlers (e.g. for caching plugins) may fail to be able to store the page output in the object cache.
		 * See <https://github.com/WordPress/performance/pull/1317#issuecomment-2271955356>.
		 */
		PHP_OUTPUT_HANDLER_STDFLAGS ^ PHP_OUTPUT_HANDLER_FLUSHABLE
	);

	if ( $started ) {
		/**
		 * Fires when the template enhancement output buffer has started.
		 *
		 * @since 6.9.0
		 */
		do_action( 'wp_template_enhancement_output_buffer_started' );
	}

	return $started;
}

/**
 * Finalizes the template enhancement output buffer.
 *
 * Checks to see if the output buffer is complete and contains HTML. If so, runs the content through
 * the `wp_template_enhancement_output_buffer` filter.  If not, the original content is returned.
 *
 * @since 6.9.0
 *
 * @see wp_start_template_enhancement_output_buffer()
 *
 * @param string $output Output buffer.
 * @param int    $phase  Phase.
 * @return string Finalized output buffer.
 */
function wp_finalize_template_enhancement_output_buffer( string $output, int $phase ): string {
	// When the output is being cleaned (e.g. pending template is replaced with error page), do not send it through the filter.
	if ( ( $phase & PHP_OUTPUT_HANDLER_CLEAN ) !== 0 ) {
		return $output;
	}

	// Detect if the response is an HTML content type.
	$is_html_content_type = null;
	$html_content_types   = array( 'text/html', 'application/xhtml+xml' );
	foreach ( headers_list() as $header ) {
		$header_parts = explode( ':', strtolower( $header ), 2 );
		if (
			count( $header_parts ) === 2 &&
			'content-type' === $header_parts[0]
		) {
			/*
			 * This is looking for very specific content types, therefore it
			 * doesn’t need to fully parse the header’s value. Instead, it needs
			 * only assert that the content type is one of the static HTML types.
			 *
			 * Example:
			 *
			 *     Content-Type: text/html; charset=utf8
			 *     Content-Type: text/html  ;charset=latin4
			 *     Content-Type:application/xhtml+xml
			 */
			$media_type           = trim( strtok( $header_parts[1], ';' ), " \t" );
			$is_html_content_type = in_array( $media_type, $html_content_types, true );
			break; // PHP only sends the first Content-Type header in the list.
		}
	}
	if ( null === $is_html_content_type ) {
		$is_html_content_type = in_array( ini_get( 'default_mimetype' ), $html_content_types, true );
	}

	// If the content type is not HTML, short-circuit since it is not relevant for enhancement.
	if ( ! $is_html_content_type ) {
		/** This action is documented in wp-includes/template.php */
		do_action( 'wp_finalized_template_enhancement_output_buffer', $output );
		return $output;
	}

	$filtered_output = $output;

	$did_just_catch = false;

	$error_log = array();
	set_error_handler(
		static function ( int $level, string $message, ?string $file = null, ?int $line = null ) use ( &$error_log, &$did_just_catch ) {
			// Switch a user error to an exception so that it can be caught and the buffer can be returned.
			if ( E_USER_ERROR === $level ) {
				throw new Exception( __( 'User error triggered:' ) . ' ' . $message );
			}

			// Display a caught exception as an error since it prevents any of the output buffer filters from applying.
			if ( $did_just_catch ) {
				$level = E_USER_ERROR;
			}

			// Capture a reported error to be displayed by appending to the processed output buffer if display_errors is enabled.
			if ( error_reporting() & $level ) {
				$error_log[] = compact( 'level', 'message', 'file', 'line' );
			}
			return false;
		}
	);
	$original_display_errors = ini_get( 'display_errors' );
	if ( $original_display_errors ) {
		ini_set( 'display_errors', 0 );
	}

	try {
		/**
		 * Filters the template enhancement output buffer prior to sending to the client.
		 *
		 * This filter only applies the HTML output of an included template. This filter is a progressive enhancement
		 * intended for applications such as optimizing markup to improve frontend page load performance. Sites must not
		 * depend on this filter applying since they may opt to stream the responses instead. Callbacks for this filter
		 * are highly discouraged from using regular expressions to do any kind of replacement on the output. Use the
		 * HTML API (either `WP_HTML_Tag_Processor` or `WP_HTML_Processor`), or else use {@see DOM\HtmlDocument} as of
		 * PHP 8.4 which fully supports HTML5.
		 *
		 * Do not print any output during this filter. While filters normally don't print anything, this is especially
		 * important since this applies during an output buffer callback. Prior to PHP 8.5, the output will be silently
		 * omitted, whereas afterward a deprecation notice will be emitted.
		 *
		 * Important: Because this filter is applied inside an output buffer callback (i.e. display handler), any
		 * callbacks added to the filter must not attempt to start their own output buffers. Otherwise, PHP will raise a
		 * fatal error: "Cannot use output buffering in output buffering display handlers."
		 *
		 * @since 6.9.0
		 *
		 * @param string $filtered_output HTML template enhancement output buffer.
		 * @param string $output          Original HTML template output buffer.
		 */
		$filtered_output = (string) apply_filters( 'wp_template_enhancement_output_buffer', $filtered_output, $output );
	} catch ( Throwable $throwable ) {
		// Emit to the error log as a warning not as an error to prevent halting execution.
		$did_just_catch = true;
		trigger_error(
			sprintf(
				/* translators: %s is the throwable class name */
				__( 'Uncaught "%s" thrown:' ),
				get_class( $throwable )
			) . ' ' . $throwable->getMessage(),
			E_USER_WARNING
		);
		$did_just_catch = false;
	}

	try {
		/**
		 * Fires after the template enhancement output buffer has been finalized.
		 *
		 * This happens immediately before the template enhancement output buffer is flushed. No output may be printed
		 * at this action; prior to PHP 8.5, the output will be silently omitted, whereas afterward a deprecation notice
		 * will be emitted. Nevertheless, HTTP headers may be sent, which makes this action complimentary to the
		 * {@see 'send_headers'} action, in which headers may be sent before the template has started rendering. In
		 * contrast, this `wp_finalized_template_enhancement_output_buffer` action is the possible point at which HTTP
		 * headers can be sent. This action does not fire if the "template enhancement output buffer" was not started.
		 * This output buffer is automatically started if this action is added before
		 * {@see wp_start_template_enhancement_output_buffer()} runs at the {@see 'wp_before_include_template'} action
		 * with priority 1000. Before this point, the output buffer will also be started automatically if there was a
		 * {@see 'wp_template_enhancement_output_buffer'} filter added, or if the
		 * {@see 'wp_should_output_buffer_template_for_enhancement'} filter is made to return `true`.
		 *
		 * Important: Because this action fires inside an output buffer callback (i.e. display handler), any callbacks
		 * added to the action must not attempt to start their own output buffers. Otherwise, PHP will raise a fatal
		 * error: "Cannot use output buffering in output buffering display handlers."
		 *
		 * @since 6.9.0
		 *
		 * @param string $output Finalized output buffer.
		 */
		do_action( 'wp_finalized_template_enhancement_output_buffer', $filtered_output );
	} catch ( Throwable $throwable ) {
		// Emit to the error log as a warning not as an error to prevent halting execution.
		$did_just_catch = true;
		trigger_error(
			sprintf(
				/* translators: %s is the class name */
				__( 'Uncaught "%s" thrown:' ),
				get_class( $throwable )
			) . ' ' . $throwable->getMessage(),
			E_USER_WARNING
		);
		$did_just_catch = false;
	}

	// Append any errors to be displayed before returning flushing the buffer.
	if ( $original_display_errors && 'stderr' !== $original_display_errors ) {
		foreach ( $error_log as $error ) {
			switch ( $error['level'] ) {
				case E_USER_NOTICE:
					$type = 'Notice';
					break;
				case E_USER_DEPRECATED:
					$type = 'Deprecated';
					break;
				case E_USER_WARNING:
					$type = 'Warning';
					break;
				default:
					$type = 'Error';
			}

			if ( ini_get( 'html_errors' ) ) {
				/*
				 * Adapted from PHP internals: <https://github.com/php/php-src/blob/a979e9f897a90a580e883b1f39ce5673686ffc67/main/main.c#L1478>.
				 * The self-closing tags are a vestige of the XHTML past!
				 */
				$format = "%s<br />\n<b>%s</b>:  %s in <b>%s</b> on line <b>%s</b><br />\n%s";
			} else {
				// Adapted from PHP internals: <https://github.com/php/php-src/blob/a979e9f897a90a580e883b1f39ce5673686ffc67/main/main.c#L1492>.
				$format = "%s\n%s: %s in %s on line %s\n%s";
			}
			$filtered_output .= sprintf(
				$format,
				ini_get( 'error_prepend_string' ),
				$type,
				$error['message'],
				$error['file'],
				$error['line'],
				ini_get( 'error_append_string' )
			);
		}

		ini_set( 'display_errors', $original_display_errors );
	}

	restore_error_handler();

	return $filtered_output;
}

/**
 * Flushes all output buffers for PHP 5.2.
 *
 * Make sure all output buffers are flushed before our singletons are destroyed.
 *
 * @since 2.2.0
 */
function wp_ob_end_flush_all() {
	$levels = ob_get_level();
	for ( $i = 0; $i < $levels; $i++ ) {
		ob_end_flush();
	}
}
