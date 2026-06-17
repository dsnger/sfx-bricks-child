<?php

declare(strict_types=1);

namespace SFX\ThemeSettingsOverview;

use SFX\AccessControl;

/**
 * Renders theme settings overview for native and custom dashboard contexts.
 */
final class OverviewRenderer
{
    /**
     * @var array<string, string>
     */
    private const BADGE_LABELS = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'partial' => 'Partial',
    ];

    /**
     * @var array<string, string>
     */
    private const INTRO_STRINGS = [
        'built_in_features' => 'These features are built into the SFX child theme — no additional plugin required.',
    ];

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
        $intro_key = $data['intro_key'] ?? '';
        if ($intro_key !== '' && isset(self::INTRO_STRINGS[$intro_key])) {
            echo '<p class="sfx-theme-overview__intro">';
            echo esc_html(__(self::INTRO_STRINGS[$intro_key], 'sfxtheme'));
            echo '</p>';
        }

        foreach ($data['groups'] ?? [] as $group) {
            self::render_group($group);
        }
    }

    /**
     * @param array<string, mixed> $group
     */
    private static function render_group(array $group): void
    {
        $group_id = esc_attr((string) ($group['id'] ?? ''));
        echo '<section class="sfx-theme-overview__group" data-group="' . $group_id . '">';
        echo '<h4 class="sfx-theme-overview__group-title">';
        echo esc_html((string) ($group['label'] ?? ''));

        if (! empty($group['badge']) && isset(self::BADGE_LABELS[$group['badge']])) {
            self::render_badge((string) $group['badge']);
        }

        echo '</h4>';

        if (! empty($group['items']) && is_array($group['items'])) {
            echo '<ul class="sfx-theme-overview__list">';
            foreach ($group['items'] as $item) {
                self::render_item($item);
            }
            echo '</ul>';
        }

        echo '</section>';
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function render_item(array $item): void
    {
        $status = (string) ($item['status'] ?? 'inactive');
        $settings_url = (string) ($item['settings_url'] ?? '');

        echo '<li class="sfx-theme-overview__item sfx-theme-overview__item--' . esc_attr($status) . '">';
        echo '<span class="sfx-theme-overview__item-label">';
        echo esc_html((string) ($item['label'] ?? ''));
        echo '</span>';

        self::render_badge($status);

        if (! empty($item['detail'])) {
            echo '<span class="sfx-theme-overview__item-detail">';
            echo esc_html((string) $item['detail']);
            echo '</span>';
        }

        if ($settings_url !== '') {
            $url = str_starts_with($settings_url, 'http') ? $settings_url : admin_url($settings_url);
            echo '<a class="sfx-theme-overview__item-link" href="' . esc_url($url) . '">';
            echo esc_html__('Settings', 'sfxtheme');
            echo '</a>';
        }

        echo '</li>';
    }

    private static function render_badge(string $status): void
    {
        if (! isset(self::BADGE_LABELS[$status])) {
            return;
        }

        echo '<span class="sfx-theme-overview__badge sfx-theme-overview__badge--' . esc_attr($status) . '">';
        echo esc_html(__(self::BADGE_LABELS[$status], 'sfxtheme'));
        echo '</span>';
    }
}
