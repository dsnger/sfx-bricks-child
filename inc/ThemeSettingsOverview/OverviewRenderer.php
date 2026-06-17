<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

use SFX\AccessControl;

/**
 * Renders theme settings overview for native and custom dashboard contexts.
 */
final class OverviewRenderer
{
    public static function render_native(): void
    {
        if (! AccessControl::can_access_theme_settings()) {
            return;
        }

        echo '<div class="sfx-theme-overview sfx-theme-overview--native">';
        self::render_inner(OverviewProvider::get_data());
        echo '</div>';
    }

    public static function render_custom_dashboard(): void
    {
        if (! AccessControl::can_access_theme_settings()) {
            return;
        }

        echo '<div class="sfx-theme-overview sfx-theme-overview--widget">';
        self::render_inner(OverviewProvider::get_data());
        echo '</div>';
    }

    /**
     * @param array{intro_key?: string, groups?: array<int, array<string, mixed>>} $data
     */
    private static function render_inner(array $data): void
    {
        self::render_intro((string) ($data['intro_key'] ?? ''));

        foreach ($data['groups'] ?? [] as $group) {
            self::render_group_accordion($group);
        }

        self::render_footer();
    }

    private static function render_intro(string $intro_key): void
    {
        if ($intro_key !== 'built_in_features') {
            return;
        }

        echo '<p class="sfx-theme-overview__intro">';
        echo esc_html__(
            'You are using the SFX Child Theme for Bricks Builder, which includes a range of useful features covering security, WordPress optimization, and image optimization. Generally, no additional optimization plugins are required. The only recommended additions are a caching plugin and an SEO plugin. The following settings and options are currently enabled; please adjust them as needed.',
            'sfxtheme'
        );
        echo '</p>';
    }

    private static function render_footer(): void
    {
        $email = 'support@smilefx.io';
        $link = sprintf(
            '<a href="%s">%s</a>',
            esc_url('mailto:' . $email),
            esc_html($email)
        );

        echo '<p class="sfx-theme-overview__footer">';
        echo wp_kses(
            sprintf(
                /* translators: %s: support email link. */
                __('If you have any questions, please contact %s.', 'sfxtheme'),
                $link
            ),
            [
                'a' => [
                    'href' => [],
                ],
            ]
        );
        echo '</p>';
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function render_group_accordion(array $group): void
    {
        $group_id = esc_attr((string) ($group['id'] ?? ''));
        $active_count = (int) ($group['active_count'] ?? 0);
        $total_count = (int) ($group['total_count'] ?? 0);
        $settings_url = (string) ($group['settings_url'] ?? '');

        echo '<details class="sfx-theme-overview__accordion" data-group="' . $group_id . '">';
        echo '<summary class="sfx-theme-overview__summary">';
        echo '<span class="sfx-theme-overview__summary-title">';
        echo esc_html((string) ($group['label'] ?? ''));
        echo '</span>';
        echo '<span class="sfx-theme-overview__summary-end">';
        echo '<span class="sfx-theme-overview__summary-count">';
        echo esc_html(sprintf('%d/%d', $active_count, $total_count));
        echo '</span>';

        if ($settings_url !== '') {
            $url = str_starts_with($settings_url, 'http') ? $settings_url : admin_url($settings_url);
            echo '<a class="sfx-theme-overview__settings-link" href="' . esc_url($url) . '"';
            echo ' onclick="event.stopPropagation();"';
            echo ' aria-label="' . esc_attr__('Settings', 'sfxtheme') . '">';
            self::render_settings_icon();
            echo '</a>';
        }

        echo '</span>';
        echo '</summary>';

        if (! empty($group['sections']) && is_array($group['sections'])) {
            echo '<div class="sfx-theme-overview__sections">';
            foreach ($group['sections'] as $section) {
                self::render_section($section);
            }
            echo '</div>';
        } elseif (! empty($group['items']) && is_array($group['items'])) {
            echo '<ul class="sfx-theme-overview__list">';
            foreach ($group['items'] as $item) {
                self::render_item($item);
            }
            echo '</ul>';
        }

        echo '</details>';
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function render_section(array $section): void
    {
        $status = (string) ($section['status'] ?? 'inactive');
        $section_id = esc_attr((string) ($section['id'] ?? ''));

        echo '<section class="sfx-theme-overview__subsection" data-section="' . $section_id . '">';
        echo '<div class="sfx-theme-overview__subsection-header">';
        echo '<h5 class="sfx-theme-overview__subsection-title">';
        echo esc_html((string) ($section['label'] ?? ''));
        echo '</h5>';
        echo '<span class="sfx-theme-overview__subsection-meta">';

        self::render_badge($status);

        if (! empty($section['detail'])) {
            echo '<span class="sfx-theme-overview__subsection-count">';
            echo esc_html((string) $section['detail']);
            echo '</span>';
        }

        echo '</span>';
        echo '</div>';

        if (! empty($section['items']) && is_array($section['items'])) {
            echo '<ul class="sfx-theme-overview__list sfx-theme-overview__list--nested">';
            foreach ($section['items'] as $item) {
                self::render_item($item, true);
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function render_item(array $item, bool $nested = false): void
    {
        $status = (string) ($item['status'] ?? 'inactive');

        echo '<li class="sfx-theme-overview__item sfx-theme-overview__item--' . esc_attr($status);
        if ($nested) {
            echo ' sfx-theme-overview__item--nested';
        }
        echo '">';
        echo '<span class="sfx-theme-overview__item-leading">';
        self::render_status_icon($status);
        echo '<span class="sfx-theme-overview__item-label">';
        echo esc_html((string) ($item['label'] ?? ''));
        echo '</span>';
        echo '</span>';
        echo '<span class="sfx-theme-overview__item-meta">';

        self::render_badge($status);

        if (! empty($item['detail'])) {
            echo '<span class="sfx-theme-overview__item-detail">';
            echo esc_html((string) $item['detail']);
            echo '</span>';
        }

        echo '</span>';
        echo '</li>';
    }

    private static function render_badge(string $status): void
    {
        $label = self::get_badge_label($status);
        if ($label === '') {
            return;
        }

        echo '<span class="sfx-theme-overview__badge sfx-theme-overview__badge--' . esc_attr($status) . '">';
        echo esc_html($label);
        echo '</span>';
    }

    private static function get_badge_label(string $status): string
    {
        switch ($status) {
            case 'active':
                return __('Active', 'sfxtheme');
            case 'inactive':
                return __('Inactive', 'sfxtheme');
            case 'partial':
                return __('Partial', 'sfxtheme');
            default:
                return '';
        }
    }

    private static function render_status_icon(string $status): void
    {
        echo '<span class="sfx-theme-overview__status-icon sfx-theme-overview__status-icon--' . esc_attr($status) . '" aria-hidden="true">';

        if ($status === 'active') {
            echo '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" focusable="false">';
            echo '<path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zm2.78 4.72a.75.75 0 0 1 0 1.06l-3.25 3.25a.75.75 0 0 1-1.06 0l-1.5-1.5a.75.75 0 1 1 1.06-1.06l.97.97 2.72-2.72a.75.75 0 0 1 1.06 0z"/>';
            echo '</svg>';
        } elseif ($status === 'partial') {
            echo '<svg width="16" height="16" viewBox="0 0 16 16" fill="currentColor" focusable="false">';
            echo '<path d="M8 1.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13zm-1.25 4.75h2.5a.75.75 0 0 1 0 1.5h-2.5a.75.75 0 0 1 0-1.5z"/>';
            echo '</svg>';
        } else {
            echo '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" focusable="false">';
            echo '<circle cx="8" cy="8" r="6.25" stroke="currentColor" stroke-width="1.5"/>';
            echo '</svg>';
        }

        echo '</span>';
    }

    private static function render_settings_icon(): void
    {
        $icon_url = get_stylesheet_directory_uri() . '/inc/ThemeSettingsOverview/assets/settings-gear.png';
        echo '<span class="sfx-theme-overview__settings-icon" style="';
        echo '-webkit-mask-image:url(' . esc_url($icon_url) . ');';
        echo 'mask-image:url(' . esc_url($icon_url) . ');';
        echo '"></span>';
    }
}
