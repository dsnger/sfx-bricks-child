<?php

declare(strict_types=1);

namespace SFX\EditorProse;

/**
 * The baseline stylesheet as a template for a site's own Bricks global class:
 * same rules, but unlayered and on `%root%` (Bricks replaces it with the class
 * selector) instead of `.sfx-prose`. Generated from prose.css, never stored,
 * so the template cannot drift from the baseline.
 */
class Starter
{

    public static function from_file(): string
    {
        return self::from_path(__DIR__ . '/assets/prose.css');
    }

    public static function from_path(string $path): string
    {
        if (!is_readable($path)) {
            return '';
        }
        $css = file_get_contents($path);

        return $css === false ? '' : self::from_baseline($css);
    }

    public static function from_baseline(string $css): string
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $css = preg_replace('/@layer[^;{]+;/', '', $css) ?? '';
        $css = preg_replace('/@layer\s+sfx\.components\s*\{/', '', $css, 1) ?? '';
        $css = rtrim($css);
        if (str_ends_with($css, '}')) {
            $css = substr($css, 0, -1); // the layer block's closing brace
        }
        $css = str_replace([':where(.sfx-prose)', '.sfx-prose '], ['%root%', '%root% '], $css);
        $css = preg_replace('/^  /m', '', $css) ?? ''; // drop the layer block's indentation
        $css = preg_replace("/\n{3,}/", "\n\n", trim($css)) ?? '';

        return $css . "\n";
    }
}
