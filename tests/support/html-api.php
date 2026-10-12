<?php
/**
 * WordPress' HTML tag processor for tests, which run without WordPress core.
 * tests/support/html-api/ holds the files verbatim from WordPress 7.1.3
 * (wp-includes/class-wp-token-map.php and wp-includes/html-api/), GPL like
 * WordPress; loaded in wp-settings.php's order. Refresh them as a set when
 * WordPress changes the reader the site check relies on.
 *
 * Exempt from the "declare(strict_types=1) in new PHP files" rule (owner
 * decision 2026-10-12): the copies stay byte-identical to WordPress, which
 * relies on PHP's loose type coercion, so the tests run the code a site runs.
 */

declare(strict_types=1);

if (!class_exists('WP_HTML_Tag_Processor', false)) {
    foreach ([
        'class-wp-token-map.php',
        'html5-named-character-references.php',
        'class-wp-html-attribute-token.php',
        'class-wp-html-span.php',
        'class-wp-html-doctype-info.php',
        'class-wp-html-text-replacement.php',
        'class-wp-html-decoder.php',
        'class-wp-html-tag-processor.php',
    ] as $file) {
        require __DIR__ . '/html-api/' . $file;
    }
}
