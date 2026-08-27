<?php

/**
 * Assert the Imagick lossy path sets no libwebp encoder-effort override.
 *
 * encodeImagick() once set webp:method=6, libwebp's maximum effort level. On
 * ImageMagick 7.1.0-23 that measured 6.13s to encode a 1672x941 PNG against
 * 0.79s at the default method 4, for a file 4% smaller (84,032 vs 87,622
 * bytes). The optimizer encodes once per configured max width, and the whole
 * upload request measured ~56s end to end, overrunning the 30s mod_fastcgi
 * idle timeout on the affected host: HTTP 500, "server cannot process the
 * image", with every converted file already written to disk.
 *
 * Encode cost is invisible to a correctness test, so what is pinned here is
 * the decision. encodeImagick() needs a live \Imagick instance and cannot be
 * called from a unit test, so it sets its options from imagickOptions(), which
 * returns them as data. Asserting that seam is what makes the absent override
 * observable.
 *
 * Run: php tests/image-optimizer-webp-method-test.php
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

// The regression itself: a lossy encode must hand libwebp no options at all,
// so its own defaults (method 4) apply. Before the fix this path forced
// method 6 and cost ~8x the time for ~4% smaller output.
assert_same([], WebpEncoder::imagickOptions(80), 'lossy quality 80 must set no libwebp options');
assert_same([], WebpEncoder::imagickOptions(1), 'lossy quality 1 must set no libwebp options');
assert_same([], WebpEncoder::imagickOptions(99), 'quality 99 is still lossy and must set no options');

// Lossless must keep working: quality 100 is the one case that legitimately
// sets an option, and dropping the method override must not have touched it.
assert_same(
    ['webp:lossless' => 'true'],
    WebpEncoder::imagickOptions(100),
    'quality 100 must request lossless and nothing else'
);

// No quality may reintroduce an effort override through the seam.
foreach ([1, 50, 80, 99, 100, 120] as $q) {
    assert_same(
        false,
        array_key_exists('webp:method', WebpEncoder::imagickOptions($q)),
        "quality $q must not set a webp:method override"
    );
}

// Guard the whole class against an override reintroduced inline, bypassing the
// seam. Read string literals via the tokenizer rather than the raw source, so
// the explanatory comments above -- which necessarily name webp:method -- are
// not themselves matched.
$tokens = token_get_all(file_get_contents(dirname(__DIR__) . '/inc/ImageOptimizer/WebpEncoder.php'));
$literals = [];
foreach ($tokens as $token) {
    if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
        $literals[] = trim($token[1], "'\"");
    }
}
assert_same(
    false,
    in_array('webp:method', $literals, true),
    'WebpEncoder must contain no webp:method string literal in code'
);

echo "OK\n";
