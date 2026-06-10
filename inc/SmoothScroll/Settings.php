<?php

declare(strict_types=1);

namespace SFX\SmoothScroll;

class Settings
{
    public const OPTION_NAME = 'sfx_smooth_scroll_options';
    public const OPTION_GROUP = 'sfx_smooth_scroll_options';
    public const DEFAULT_EASING = 'Math.min(1, 1.001 - Math.pow(2, -10 * t))';

    public static function register(): void
    {
        add_action('sfx_init_admin_features', [self::class, 'register_settings']);
    }

    public static function get_fields(): array
    {
        return [
            ['id' => 'duration', 'label' => __('Duration', 'sfxtheme'), 'type' => 'number', 'default' => '1.2', 'step' => '0.1', 'min' => '0', 'max' => '10', 'col' => 'metrics'],
            ['id' => 'mouse_multiplier', 'label' => __('Mouse Multiplier', 'sfxtheme'), 'type' => 'number', 'default' => '1', 'step' => '0.1', 'min' => '0', 'max' => '10', 'col' => 'metrics'],
            ['id' => 'touch_multiplier', 'label' => __('Touch Multiplier', 'sfxtheme'), 'type' => 'number', 'default' => '2', 'step' => '0.1', 'min' => '0', 'max' => '10', 'col' => 'metrics'],
            ['id' => 'direction', 'label' => __('Direction', 'sfxtheme'), 'type' => 'select', 'choices' => ['vertical' => 'vertical', 'horizontal' => 'horizontal'], 'default' => 'vertical', 'col' => 'metrics'],
            ['id' => 'gesture_direction', 'label' => __('Gesture Direction', 'sfxtheme'), 'type' => 'select', 'choices' => ['vertical' => 'vertical', 'horizontal' => 'horizontal'], 'default' => 'vertical', 'col' => 'easing'],
            ['id' => 'easing', 'label' => __('Easing', 'sfxtheme'), 'type' => 'text', 'default' => self::DEFAULT_EASING, 'col' => 'easing'],
            ['id' => 'smooth', 'label' => __('Smooth', 'sfxtheme'), 'type' => 'checkbox', 'default' => 1, 'col' => 'toggles'],
            ['id' => 'smooth_touch', 'label' => __('Smooth Touch', 'sfxtheme'), 'type' => 'checkbox', 'default' => 1, 'col' => 'toggles'],
            ['id' => 'infinite', 'label' => __('Infinite', 'sfxtheme'), 'type' => 'checkbox', 'default' => 0, 'col' => 'toggles'],
            ['id' => 'smooth_anchor_scroll', 'label' => __('Smooth Anchor Scroll', 'sfxtheme'), 'type' => 'checkbox', 'default' => 1, 'col' => 'toggles'],
            ['id' => 'anchor_scroll_offset', 'label' => __('Anchor Scroll Offset', 'sfxtheme'), 'type' => 'number', 'default' => '100', 'step' => '1', 'min' => '-1000', 'max' => '1000', 'col' => 'anchor'],
        ];
    }

    public static function get_defaults(): array
    {
        $defaults = [];
        foreach (self::get_fields() as $field) {
            $defaults[$field['id']] = $field['default'];
        }
        return $defaults;
    }

    public static function register_settings(): void
    {
        register_setting(self::OPTION_GROUP, self::OPTION_NAME, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_options'],
            'default' => self::get_defaults(),
        ]);
    }

    public static function sanitize_options($input): array
    {
        $i = is_array($input) ? $input : [];
        $clampf = static fn($v, $lo, $hi) => max($lo, min($hi, (float) str_replace(',', '.', (string) $v)));

        return [
            'duration' => $clampf($i['duration'] ?? 1.2, 0, 10),
            'mouse_multiplier' => $clampf($i['mouse_multiplier'] ?? 1, 0, 10),
            'touch_multiplier' => $clampf($i['touch_multiplier'] ?? 2, 0, 10),
            'direction' => in_array($i['direction'] ?? '', ['vertical', 'horizontal'], true) ? $i['direction'] : 'vertical',
            'gesture_direction' => in_array($i['gesture_direction'] ?? '', ['vertical', 'horizontal'], true) ? $i['gesture_direction'] : 'vertical',
            'easing' => self::is_valid_easing($i['easing'] ?? '') ? trim((string) $i['easing']) : self::DEFAULT_EASING,
            'smooth' => empty($i['smooth']) ? 0 : 1,
            'smooth_touch' => empty($i['smooth_touch']) ? 0 : 1,
            'infinite' => empty($i['infinite']) ? 0 : 1,
            'smooth_anchor_scroll' => empty($i['smooth_anchor_scroll']) ? 0 : 1,
            'anchor_scroll_offset' => max(-1000, min(1000, (int) ($i['anchor_scroll_offset'] ?? 100))),
        ];
    }

    private static function is_valid_easing(string $raw): bool
    {
        $e = trim(preg_replace('/\s+/', ' ', $raw) ?? '');
        if ($e === '' || strlen($e) > 200) {
            return false;
        }

        $allowed = 'min|max|pow|sqrt|cbrt|abs|sign|floor|ceil|round|trunc|exp|expm1|log|log2|log10|sin|cos|tan|asin|acos|atan|atan2|sinh|cosh|tanh|hypot|PI|E';
        $stripped = preg_replace('/Math\.(' . $allowed . ')\b/', ' ', $e) ?? '';

        if (preg_match('/[A-Za-z]\s*\(/', $stripped)) {
            return false;
        }

        return (bool) preg_match('/^[0-9t.,+\-*\/%()\s]*$/', $stripped);
    }

    public static function get_config_for_js(): array
    {
        $o = get_option(self::OPTION_NAME, self::get_defaults());

        return [
            'duration' => (float) ($o['duration'] ?? 1.2),
            'orientation' => $o['direction'] ?? 'vertical',
            'gestureOrientation' => $o['gesture_direction'] ?? 'vertical',
            'smoothWheel' => (bool) ($o['smooth'] ?? 1),
            'syncTouch' => (bool) ($o['smooth_touch'] ?? 1),
            'wheelMultiplier' => (float) ($o['mouse_multiplier'] ?? 1),
            'touchMultiplier' => (float) ($o['touch_multiplier'] ?? 2),
            'infinite' => (bool) ($o['infinite'] ?? 0),
            'easing' => (string) ($o['easing'] ?? self::DEFAULT_EASING),
            'defaultEasing' => self::DEFAULT_EASING,
            'anchors' => (bool) ($o['smooth_anchor_scroll'] ?? 1),
            'anchorOffset' => (int) ($o['anchor_scroll_offset'] ?? 100),
        ];
    }

    public static function delete_all_options(): void
    {
        delete_option(self::OPTION_NAME);
    }
}
