<?php

declare(strict_types=1);

namespace SFX\Admin;

final class PlaceholderColumn
{
    /**
     * @param array<int, array{label: string, shortcode: string, bricks: string}> $items
     */
    public static function render_rows(array $items): void
    {
        if ($items === []) {
            echo '<span class="sfx-placeholder-empty">&mdash;</span>';
            return;
        }

        echo '<div class="sfx-placeholder-column">';
        foreach ($items as $item) {
            self::render_row($item['label'], $item['shortcode'], $item['bricks']);
        }
        echo '</div>';
    }

    private static function render_row(string $label, string $shortcode, string $bricks): void
    {
        echo '<div class="sfx-placeholder-row">';
        echo '<span class="sfx-placeholder-label">' . esc_html($label) . '</span>';
        echo '<div class="sfx-placeholder-pair">';

        self::render_entry('shortcode', $shortcode);
        self::render_entry('bricks', $bricks);

        echo '</div></div>';
    }

    private static function render_entry(string $type, string $value): void
    {
        $copy_label = __('Copy', 'sfxtheme');
        $copied_label = __('Copied', 'sfxtheme');

        echo '<div class="sfx-placeholder-entry sfx-placeholder-entry--' . esc_attr($type) . '">';
        echo '<code class="sfx-placeholder-code">' . esc_html($value) . '</code>';
        printf(
            '<button type="button" class="button button-small sfx-copy-placeholder-btn" data-copy-value="%1$s" data-label-copy="%2$s" data-label-copied="%3$s" aria-label="%4$s">%2$s</button>',
            esc_attr($value),
            esc_attr($copy_label),
            esc_attr($copied_label),
            esc_attr(sprintf(__('Copy %1$s: %2$s', 'sfxtheme'), $type, $value))
        );
        echo '</div>';
    }
}
