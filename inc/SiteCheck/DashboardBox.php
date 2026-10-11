<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Read-only summary of the last saved manual run (spec "Dashboard box").
 * It never runs a check, takes the mutex or writes; it shows check names
 * and counts only, never finding labels (they may carry paths or logins).
 *
 * Two places: a native WordPress dashboard widget, and an entry for the
 * Custom Dashboard through the filter `sfx/custom_dashboard/widgets`
 * (this class names the hook, never a CustomDashboard class).
 */
final class DashboardBox
{
    public const ID = 'sfx_site_check';

    private const MAX_RED = 3;

    public static function register(): void
    {
        add_action('wp_dashboard_setup', [self::class, 'register_native']);
        add_filter('sfx/custom_dashboard/widgets', [self::class, 'widgets']);
    }

    public static function register_native(): void
    {
        if (!Access::allowed()) {
            return;
        }
        wp_add_dashboard_widget(self::ID, __('Sicherheits-Check', 'sfxtheme'), [self::class, 'render']);
    }

    /**
     * @param array<string,mixed> $widgets
     * @return array<string,mixed>
     */
    public static function widgets($widgets): array
    {
        $widgets = is_array($widgets) ? $widgets : [];
        $widgets[self::ID] = [
            'title' => __('Sicherheits-Check', 'sfxtheme'),
            'render' => [self::class, 'render'],
            'can_render' => [Access::class, 'allowed'],
        ];
        return $widgets;
    }

    public static function render(): void
    {
        if (!Access::allowed()) {
            return;
        }

        $run = Runs::last();
        $url = admin_url('tools.php?page=' . AdminPage::$menu_slug . '#uebersicht');

        echo '<div class="sfx-theme-overview sfx-theme-overview--widget sfx-site-check-box">';
        if ($run === null) {
            echo '<p class="sfx-theme-overview__intro">' . esc_html__('Not checked yet', 'sfxtheme') . '</p>';
        } else {
            self::render_summary($run);
        }
        echo '<p class="sfx-theme-overview__footer"><a href="' . esc_url($url) . '">' . esc_html__('Check now', 'sfxtheme') . '</a></p>';
        echo '</div>';
    }

    /**
     * Counts checks by their status (a check with Rot and Nicht prüfbar
     * findings is one Rot); lists each Rot check's title once, in catalogue
     * order, so Sicherheit first.
     *
     * @param array{date:int, profile:string, results:array<string,array>} $run
     */
    private static function render_summary(array $run): void
    {
        $profiles = AdminPage::profiles();
        $profile = $profiles[$run['profile']] ?? '';
        $counts = [Status::RED => 0, Status::YELLOW => 0, Status::UNKNOWN => 0];
        $red = [];
        foreach (Catalogue::all() as $id => $check) {
            $status = (string) ($run['results'][$id]['status'] ?? '');
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if ($status === Status::RED) {
                $red[] = $check['title'];
            }
        }
        $labels = Status::labels();

        $when = Runs::date_display($run['date']);
        echo '<p class="sfx-theme-overview__intro">';
        /* translators: 1: date, 2: profile */
        echo esc_html(sprintf(__('Last check: %1$s, profile %2$s', 'sfxtheme'), $when, $profile));
        echo '</p>';

        echo '<p class="sfx-theme-overview__counts">';
        foreach ($counts as $status => $count) {
            echo '<span class="sfx-theme-overview__badge sfx-theme-overview__badge--' . esc_attr($status) . '">'
                . esc_html($labels[$status]) . ': <strong data-count="' . esc_attr($status) . '">' . esc_html((string) $count) . '</strong></span> ';
        }
        echo '</p>';

        if ($red !== []) {
            echo '<ul class="sfx-theme-overview__list">';
            foreach (array_slice($red, 0, self::MAX_RED) as $title) {
                echo '<li class="sfx-theme-overview__item sfx-theme-overview__item--inactive">' . esc_html($title) . '</li>';
            }
            echo '</ul>';
        }
    }
}
