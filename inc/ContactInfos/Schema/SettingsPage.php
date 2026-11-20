<?php

declare(strict_types=1);

namespace SFX\ContactInfos\Schema;

use SFX\ContactInfos\PostType;

/**
 * Settings screen for the structured data (spec § "Settings and purge"): the switch, the type (C4),
 * the organization and exclusions (C5), and what the next front-end request would do with them.
 */
final class SettingsPage
{
    public const SLUG = 'sfx-contact-schema';
    private const ACTION = 'sfx_contact_schema_save';
    private const NOTICES = ['saved', 'saved_no_choice', 'invalid_choice'];

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_save']);
    }

    public static function add_menu(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . PostType::$post_type,
            __('Structured data', 'sfxtheme'),
            __('Structured data', 'sfxtheme'),
            'manage_options',
            self::SLUG,
            [self::class, 'render_page']
        );
    }

    public static function handle_save(): void
    {
        check_admin_referer(self::ACTION);
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'sfxtheme'), '', ['response' => 403]);
        }
        $post = wp_unslash($_POST);

        Settings::update([
            'enabled' => !empty($post['enabled']),
            'type'    => is_string($post['type'] ?? null) ? $post['type'] : '',
        ]);

        $organization_id = is_scalar($post['organization_id'] ?? null) ? (int) $post['organization_id'] : 0;
        $excluded = array_map('intval', array_filter((array) ($post['excluded'] ?? []), 'is_scalar'));
        if ($organization_id <= 0) {
            $notice = 'saved_no_choice';
        } else {
            $notice = Selection::save_choice($organization_id, $excluded) ? 'saved' : 'invalid_choice';
        }

        wp_safe_redirect(add_query_arg('sfx_schema', $notice, self::url()));
        exit;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to do this.', 'sfxtheme'), '', ['response' => 403]);
        }
        $settings = Settings::get();
        $selection = Selection::resolve();
        $mode = Mode::detect();
        $graph = GraphBuilder::organization($selection, $settings['type']);
        $contacts = self::published_contacts();

        echo '<div class="wrap"><h1>' . esc_html__('Structured data', 'sfxtheme') . '</h1>';
        self::render_notice();
        echo '<p>' . esc_html__('Publishes the company from Contact Infos as schema.org structured data (JSON-LD), or adds it to the entry of a supported SEO plugin.', 'sfxtheme') . '</p>';

        self::render_status($mode, $selection, $graph);
        self::render_form($settings, $selection, $contacts);
        echo '</div>';
    }

    private static function render_notice(): void
    {
        $code = isset($_GET['sfx_schema']) && is_string($_GET['sfx_schema']) ? sanitize_key(wp_unslash($_GET['sfx_schema'])) : '';
        if (!in_array($code, self::NOTICES, true)) {
            return;
        }
        $messages = [
            'saved'           => [__('Settings saved.', 'sfxtheme'), 'success'],
            'saved_no_choice' => [__('Settings saved. No organization was chosen, so the organization and the exclusions were not changed.', 'sfxtheme'), 'warning'],
            'invalid_choice'  => [__('The organization was not saved: it must be a published contact of type Main. The organization and the exclusions were not changed; the switch and the type were saved.', 'sfxtheme'), 'error'],
        ];
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($messages[$code][1]), esc_html($messages[$code][0]));
    }

    /** What the next front-end request would do, and what is missing for it (spec § "Mode selection"). */
    private static function render_status(array $mode, array $selection, array $graph): void
    {
        echo '<h2>' . esc_html__('Current state', 'sfxtheme') . '</h2>';
        $plugins = implode(', ', $mode['plugins']);
        $reasons = [
            'disabled'                 => __('Off. Nothing is published, the per-page structured data included.', 'sfxtheme'),
            /* translators: %s: plugin names */
            'other_plugin'             => sprintf(__('Another SEO or schema plugin is active (%s). The theme stays out: it publishes nothing and adds nothing.', 'sfxtheme'), $plugins),
            /* translators: %s: plugin names */
            'several_seo_plugins'      => sprintf(__('More than one SEO plugin is active (%s). The theme stays out: it publishes nothing and adds nothing.', 'sfxtheme'), $plugins),
            'yoast_company'            => __('Yoast SEO: the theme adds the Contact Infos data to Yoast\'s organization entry.', 'sfxtheme'),
            'yoast_not_company'        => __('Yoast SEO is set to a person — nothing is added.', 'sfxtheme'),
            'rank_math_company'        => __('Rank Math: the theme adds the Contact Infos data to Rank Math\'s organization entry.', 'sfxtheme'),
            'rank_math_not_company'    => __('Rank Math is set to a person — nothing is added.', 'sfxtheme'),
            'rank_math_schema_off'     => __('Rank Math\'s schema module is off — nothing is added.', 'sfxtheme'),
            /* translators: the quoted labels are The SEO Framework's own and stay in its wording */
            'tsf_knowledge_output_on'  => __('The SEO Framework publishes its own organization entry, so the theme publishes nothing. To let the theme publish the organization: in The SEO Framework settings, in the "Schema.org Settings" box, untick "Add authorized presence?".', 'sfxtheme'),
            'tsf_knowledge_output_off' => __('The SEO Framework\'s organization output is off: the theme publishes its own organization entry on every page.', 'sfxtheme'),
            'no_seo_plugin'            => __('No SEO plugin: the theme publishes its own organization entry on every page.', 'sfxtheme'),
        ];
        echo '<p><strong>' . esc_html($reasons[$mode['reason']] ?? $mode['reason']) . '</strong></p>';

        $notes = [];
        if ($mode['reason'] === 'yoast_company' || $mode['reason'] === 'rank_math_company') {
            $notes[] = __('Extension configured. Whether the data actually appears in a page is confirmed only by the release check.', 'sfxtheme');
            $notes[] = __('From Contact Infos: name, address, telephone, email, contact points and locations; social profiles are added to the plugin\'s. An empty Contact Infos value keeps the plugin\'s value. The type, URL and logo stay the plugin\'s.', 'sfxtheme');
            $notes[] = __('The organization\'s own opening hours are not added in this mode; locations keep theirs. They are published only when the theme publishes its own entry with the type LocalBusiness.', 'sfxtheme');
        }
        if ($mode['reason'] === 'yoast_company') {
            $notes[] = __('Yoast emits its organization entry only when it is set to represent a company and has a company name and a logo.', 'sfxtheme');
        }
        if ($mode['reason'] === 'rank_math_company') {
            $notes[] = __('The place\'s coordinates are kept in Rank Math; the theme only updates the address.', 'sfxtheme');
        }
        if ($mode['mode'] === 'own' && $graph['downgraded']) {
            $notes[] = __('Type LocalBusiness needs the organization\'s street and city. Until they are filled in, it is published as Organization.', 'sfxtheme');
        }
        self::render_list($notes);

        $problems = [
            'marked_invalid'       => __('The contact chosen as the organization is no longer a published contact of type Main. Nothing is published or added until you choose one below.', 'sfxtheme'),
            'multiple_marked'      => __('Several contacts are marked as the organization. Nothing is published or added until you choose one below.', 'sfxtheme'),
            'deleted_after_choice' => __('The contact chosen as the organization no longer exists. Nothing is published or added until you choose one below.', 'sfxtheme'),
            'no_main'              => __('There is no published contact of type Main. Nothing is published or added until one exists.', 'sfxtheme'),
        ];
        if (isset($problems[$selection['status']])) {
            echo '<div class="notice notice-warning inline"><p>' . esc_html($problems[$selection['status']]) . '</p>';
            self::render_contacts($selection['marked_ids']);
            echo '</div>';
        } elseif ($selection['status'] === 'none_marked_unconfigured') {
            /* translators: %s: contact title */
            echo '<p>' . esc_html(sprintf(__('No organization has been chosen yet. Until you save a choice, the first contact of type Main is used: %s.', 'sfxtheme'), self::title($selection['organization_id']))) . '</p>';
        }

        if ($graph['incomplete'] !== []) {
            echo '<h3>' . esc_html__('Incomplete, left out', 'sfxtheme') . '</h3><ul>';
            $labels = [
                'name'               => __('name', 'sfxtheme'),
                'address'            => __('street and city', 'sfxtheme'),
                'address_parts'      => __('street, zip, city and country (only the formatted address is filled in)', 'sfxtheme'),
                'area'               => __('area label', 'sfxtheme'),
                'telephone_or_email' => __('telephone or email', 'sfxtheme'),
            ];
            foreach ($graph['incomplete'] as $entry) {
                $missing = array_map(static fn(string $m): string => $labels[$m] ?? $m, $entry['missing']);
                /* translators: %s: list of missing fields */
                echo '<li>' . self::contact_link($entry['id']) . ' — ' . esc_html(sprintf(__('missing: %s', 'sfxtheme'), implode(', ', $missing))) . '</li>';
            }
            echo '</ul>';
        }

        if ($selection['unused_main_ids'] !== []) {
            echo '<h3>' . esc_html__('Not used (one organization only)', 'sfxtheme') . '</h3>';
            self::render_contacts($selection['unused_main_ids']);
        }
    }

    /** @param array{main:list<\WP_Post>, other:list<\WP_Post>} $contacts */
    private static function render_form(array $settings, array $selection, array $contacts): void
    {
        $chosen = in_array($selection['status'], ['ok', 'none_marked_unconfigured'], true) ? $selection['organization_id'] : 0;

        echo '<h2>' . esc_html__('Settings', 'sfxtheme') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(self::ACTION);
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row">' . esc_html__('Structured data', 'sfxtheme') . '</th><td><label>'
            . '<input type="checkbox" name="enabled" value="1"' . ($settings['enabled'] ? ' checked' : '') . '> '
            . esc_html__('Publish the company from Contact Infos', 'sfxtheme') . '</label></td></tr>';

        echo '<tr><th scope="row"><label for="sfx-schema-type">' . esc_html__('Type', 'sfxtheme') . '</label></th><td>'
            . '<select id="sfx-schema-type" name="type">';
        foreach (['Organization' => __('Organization', 'sfxtheme'), 'LocalBusiness' => __('LocalBusiness', 'sfxtheme')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . ($settings['type'] === $value ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Used only when the theme publishes its own entry. With an SEO plugin, the plugin\'s type stays.', 'sfxtheme') . '</p></td></tr>';

        echo '<tr><th scope="row"><label for="sfx-schema-organization">' . esc_html__('Organization', 'sfxtheme') . '</label></th><td>'
            . '<select id="sfx-schema-organization" name="organization_id">';
        if ($chosen === 0) {
            echo '<option value="0">' . esc_html__('— Choose a contact —', 'sfxtheme') . '</option>';
        }
        foreach ($contacts['main'] as $post) {
            echo '<option value="' . esc_attr((string) $post->ID) . '"' . ($post->ID === $chosen ? ' selected' : '') . '>' . esc_html($post->post_title) . '</option>';
        }
        echo '</select><p class="description">' . esc_html__('Published contacts of type Main.', 'sfxtheme') . '</p></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Leave out', 'sfxtheme') . '</th><td>';
        if ($contacts['other'] === []) {
            echo '<p class="description">' . esc_html__('No published locations or contact points.', 'sfxtheme') . '</p>';
        } else {
            echo '<fieldset><legend class="screen-reader-text">' . esc_html__('Leave out', 'sfxtheme') . '</legend>';
            foreach ($contacts['other'] as $post) {
                $role = self::type($post->ID) === 'branch' ? __('Location', 'sfxtheme') : __('Contact point', 'sfxtheme');
                echo '<label><input type="checkbox" name="excluded[]" value="' . esc_attr((string) $post->ID) . '"'
                    . (get_post_meta($post->ID, Selection::EXCLUDED_META, true) === '1' ? ' checked' : '') . '> '
                    . esc_html($post->post_title) . ' <span class="description">(' . esc_html($role) . ')</span></label><br>';
            }
            echo '</fieldset><p class="description">' . esc_html__('Locations and contact points are published unless left out here.', 'sfxtheme') . '</p>';
        }
        echo '</td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" class="button button-primary">' . esc_html__('Save settings', 'sfxtheme') . '</button></p>';
        echo '</form>';
    }

    /** @return array{main:list<\WP_Post>, other:list<\WP_Post>} published contacts, main and branch/contact point */
    private static function published_contacts(): array
    {
        $posts = get_posts([
            'post_type'   => PostType::$post_type,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby'     => ['menu_order' => 'ASC', 'date' => 'ASC'],
        ]);
        $contacts = ['main' => [], 'other' => []];
        foreach ($posts as $post) {
            if ($post->post_status === 'publish') {
                $contacts[self::type($post->ID) === 'main' ? 'main' : 'other'][] = $post;
            }
        }
        return $contacts;
    }

    /** Same rule as Selection: anything but branch or contact_point counts as main. */
    private static function type(int $id): string
    {
        $type = (string) get_post_meta($id, '_contact_type', true);
        return in_array($type, ['branch', 'contact_point'], true) ? $type : 'main';
    }

    /** @param list<string> $items */
    private static function render_list(array $items): void
    {
        if ($items === []) {
            return;
        }
        echo '<ul>';
        foreach ($items as $item) {
            echo '<li>' . esc_html($item) . '</li>';
        }
        echo '</ul>';
    }

    /** @param list<int> $ids */
    private static function render_contacts(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        echo '<ul>';
        foreach ($ids as $id) {
            echo '<li>' . self::contact_link($id) . '</li>';
        }
        echo '</ul>';
    }

    /** The contact's title, escaped, linked to its edit screen when there is one. */
    private static function contact_link(int $id): string
    {
        $link = get_edit_post_link($id);
        $title = esc_html(self::title($id));
        return is_string($link) && $link !== '' ? '<a href="' . esc_url($link) . '">' . $title . '</a>' : $title;
    }

    private static function title(int $id): string
    {
        $post = get_post($id);
        $title = $post instanceof \WP_Post ? $post->post_title : '';
        /* translators: %d: contact ID */
        return $title !== '' ? $title : sprintf(__('Contact #%d', 'sfxtheme'), $id);
    }

    private static function url(): string
    {
        return admin_url('edit.php?post_type=' . PostType::$post_type . '&page=' . self::SLUG);
    }
}
