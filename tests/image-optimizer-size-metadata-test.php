<?php

/**
 * Image Optimizer must store real height/width on custom sizes.
 *
 * Storing 0 on the unconstrained axis makes WordPress emit height="1"
 * (wp_constrain_dimensions) and causes cumulative layout shift.
 *
 * Run: php tests/image-optimizer-size-metadata-test.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../inc/ImageOptimizer/ImageConversionService.php';

use SFX\ImageOptimizer\ImageConversionService;

function assert_true($condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function assert_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$width_mode = ImageConversionService::proportionalSize(1200, 'width', 1920, 1080);
assert_same(1200, $width_mode['width'], 'Width-mode custom size should keep the target width');
assert_same(675, $width_mode['height'], 'Width-mode custom size should derive height from the full aspect ratio (1920x1080 -> 1200x675)');

$height_mode = ImageConversionService::proportionalSize(720, 'height', 1920, 1080);
assert_same(1280, $height_mode['width'], 'Height-mode custom size should derive width from the full aspect ratio (1920x1080 -> 1280x720)');
assert_same(720, $height_mode['height'], 'Height-mode custom size should keep the target height');

$missing_file = ImageConversionService::dimensionsForCustomSize('/tmp/does-not-exist.webp', 600, 'width', 1920, 1080);
assert_same(600, $missing_file['width'], 'Missing size file should fall back to the target width');
assert_same(338, $missing_file['height'], 'Missing size file should fall back to proportional height');

$broken = [
    'width' => 1920,
    'height' => 1080,
    'sizes' => [
        'custom-1200' => [
            'file' => 'photo-1200.webp',
            'width' => 1200,
            'height' => 0,
            'mime-type' => 'image/webp',
        ],
        'custom-600' => [
            'file' => 'photo-600.webp',
            'width' => 600,
            'height' => 1,
            'mime-type' => 'image/webp',
        ],
        'thumbnail' => [
            'file' => 'photo-150x150.webp',
            'width' => 150,
            'height' => 150,
            'mime-type' => 'image/webp',
        ],
    ],
];

$repaired = ImageConversionService::repairMissingSizeDimensions($broken);
assert_same(675, $repaired['sizes']['custom-1200']['height'], 'Stored height 0 should be repaired to the proportional height');
assert_same(338, $repaired['sizes']['custom-600']['height'], 'Stored height 1 (WordPress clamp) should be repaired to the proportional height');
assert_same(150, $repaired['sizes']['thumbnail']['height'], 'Cropped thumbnail dimensions should be left alone');

$height_broken = [
    'width' => 1920,
    'height' => 1080,
    'sizes' => [
        'custom-720' => [
            'file' => 'photo-720.webp',
            'width' => 0,
            'height' => 720,
            'mime-type' => 'image/webp',
        ],
    ],
];

$height_repaired = ImageConversionService::repairMissingSizeDimensions($height_broken);
assert_same(1280, $height_repaired['sizes']['custom-720']['width'], 'Stored width 0 in height mode should be repaired to the proportional width');

$healthy = [
    'width' => 1920,
    'height' => 1080,
    'sizes' => [
        'custom-1200' => [
            'file' => 'photo-1200.webp',
            'width' => 1200,
            'height' => 675,
            'mime-type' => 'image/webp',
        ],
    ],
];

$unchanged = ImageConversionService::repairMissingSizeDimensions($healthy);
assert_same(675, $unchanged['sizes']['custom-1200']['height'], 'Already-correct size metadata should stay unchanged');

echo "OK\n";
