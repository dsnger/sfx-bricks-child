<?php

/**
 * Assert WebpEncoder::webpArg() never evaluates IMG_WEBP_LOSSLESS itself.
 *
 * GD is compiled per host: a build can support WebP output yet not define
 * IMG_WEBP_LOSSLESS. Observed on PHP 8.4 at IONOS, while PHP 8.5 under MAMP
 * defines it — so the crash reproduces on a client site while local runs and CI
 * stay green. Naming an undefined constant is a fatal Error in PHP 8, and this
 * one sat on the upload path: every image upload returned HTTP 500, the file
 * landed in uploads/ and no attachment row was ever created.
 *
 * webpArg() takes the already-resolved marker, so no argument any caller can
 * pass makes it evaluate a constant that may not exist. That also makes the
 * missing-constant case testable on a host where the constant does exist.
 *
 * Run: php tests/image-optimizer-webp-lossless-test.php
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/inc/ImageOptimizer/WebpEncoder.php';

use SFX\ImageOptimizer\WebpEncoder;

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf(
            "FAIL: %s\n  expected: %s\n  actual:   %s\n",
            $message,
            var_export($expected, true),
            var_export($actual, true)
        ));
        exit(1);
    }
}

// The regression itself: quality 100 on a GD build without the constant must
// resolve to plain lossy quality 100. Before the fix this expression named
// IMG_WEBP_LOSSLESS unconditionally — returning 101 where the constant exists,
// and fatally where it does not. Either way this assertion fails.
assert_same(
    100,
    WebpEncoder::webpArg(100, null),
    'quality 100 with no lossless marker must fall back to lossy quality 100'
);

// Where the build does expose lossless, the marker must still win — the fix
// must not quietly downgrade hosts that were already working. The marker is
// passed as a literal so this holds on every host, including the ones that
// cannot define the constant.
assert_same(
    101,
    WebpEncoder::webpArg(100, 101),
    'quality 100 must select the lossless marker when the caller supplies one'
);

// Below the lossless threshold the marker is ignored either way.
assert_same(85, WebpEncoder::webpArg(85, null), 'quality 85 is lossy with no marker');
assert_same(85, WebpEncoder::webpArg(85, 101), 'quality 85 stays lossy even where lossless exists');

// isLosslessQuality is the threshold webpArg depends on; pin it so a change
// there cannot silently move which qualities consult the marker.
assert_same(true, WebpEncoder::isLosslessQuality(100), 'quality 100 is the lossless threshold');
assert_same(false, WebpEncoder::isLosslessQuality(99), 'quality 99 is below the lossless threshold');

// The production seam: encodeGd is private and needs a GD resource, so what is
// pinned here is the contract it relies on — that webpArg names no constant.
// Confirmed by reading the source rather than executing it.
$source = file_get_contents(dirname(__DIR__) . '/inc/ImageOptimizer/WebpEncoder.php');
assert_same(
    true,
    str_contains($source, "self::webpArg(\$quality, defined('IMG_WEBP_LOSSLESS') ? IMG_WEBP_LOSSLESS : null)"),
    'encodeGd must resolve the marker behind its own defined() check'
);

echo "OK\n";
