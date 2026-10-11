<?php

declare(strict_types=1);

namespace SFX\SiteCheck;

/**
 * Tools → Sicherheits-Check. Registered only for users Access::allowed()
 * lets in; the page and the save handler check it again, and the handler
 * checks the nonce Access::NONCE.
 *
 * The page (spec "Page layout and guidance") is six tabs. PHP renders the
 * two panels that need the server — the .htaccess template and the settings
 * form — inside `#sfx-sc-app`; assets/site-check.js builds the tab bar, the
 * Übersicht and the three section tabs around them from script_data().
 */
final class AdminPage
{
    public static $menu_slug = 'sfx-site-check';

    private const ACTION = 'sfx_site_check_save';
    private const NOTICES = ['saved', 'busy', 'failed'];

    /** The theme settings page, where a module is switched on (its slug; no class of that module is named). */
    private const THEME_SETTINGS = 'sfx-general-theme-options';

    /**
     * WordPress' internal types, never offered in the sitemap allow list
     * (spec "Sitemap allow list"); attachments are offered as `attachments`.
     */
    private const INTERNAL_TYPES = [
        'post_type:attachment', 'post_type:revision', 'post_type:nav_menu_item', 'post_type:custom_css',
        'post_type:customize_changeset', 'post_type:oembed_cache', 'post_type:user_request', 'post_type:wp_block',
        'post_type:wp_template', 'post_type:wp_template_part', 'post_type:wp_global_styles', 'post_type:wp_navigation',
        'post_type:wp_font_family', 'post_type:wp_font_face',
        'taxonomy:nav_menu', 'taxonomy:wp_theme', 'taxonomy:wp_template_part_area', 'taxonomy:wp_pattern_category',
    ];

    /** @var array|null Probe::cleanup() of this page load (spec lifecycle step 4) */
    private static ?array $cleanup = null;

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_post_' . self::ACTION, [self::class, 'handle_save']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    /** The browser fetcher, on this page only, for allowed users only. */
    public static function enqueue_assets($hook_suffix): void
    {
        if ($hook_suffix !== 'tools_page_' . self::$menu_slug || !Access::allowed()) {
            return;
        }
        $file = '/inc/SiteCheck/assets/site-check.js';
        wp_enqueue_script(
            'sfx-site-check',
            get_stylesheet_directory_uri() . $file,
            [],
            (string) filemtime(get_stylesheet_directory() . $file),
            true
        );
        $css = '/inc/SiteCheck/assets/site-check.css';
        wp_enqueue_style('sfx-site-check', get_stylesheet_directory_uri() . $css, ['dashicons'], (string) filemtime(get_stylesheet_directory() . $css));
        wp_localize_script('sfx-site-check', 'sfxSiteCheck', self::script_data());
    }

    /** Everything the page script needs. Rendered with textContent only. */
    private static function script_data(): array
    {
        $last = Runs::last();
        if ($last !== null) {
            $last = [
                'results' => $last['results'],
                'date_display' => Runs::date_display($last['date']),
                'user' => self::user_name($last['user']),
                'profile' => $last['profile'],
            ];
        }
        $done = Runs::items();
        $items = [];
        foreach (self::item_labels() as $id => $label) {
            $items[] = [
                'id' => $id,
                'label' => $label,
                'done' => isset($done[$id]),
                'meta' => isset($done[$id]) ? self::item_meta($done[$id]['user'], $done[$id]['date']) : '',
            ];
        }

        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(Access::NONCE),
            'catalogue' => self::catalogue_data(),
            'tabs' => [
                ['id' => 'uebersicht', 'title' => __('Overview', 'sfxtheme')],
                ['id' => 'sicherheit', 'title' => __('Security', 'sfxtheme'), 'section' => Catalogue::SECURITY],
                ['id' => 'live-gang', 'title' => __('Go-live', 'sfxtheme'), 'section' => Catalogue::GOLIVE],
                ['id' => 'aufraeumen', 'title' => __('Clean-up', 'sfxtheme'), 'section' => Catalogue::CLEANUP],
                ['id' => 'htaccess', 'title' => __('.htaccess template', 'sfxtheme')],
                ['id' => 'einstellungen', 'title' => __('Settings', 'sfxtheme')],
            ],
            'sections' => [
                ['id' => Catalogue::SECURITY, 'title' => __('Security', 'sfxtheme')],
                ['id' => Catalogue::GOLIVE, 'title' => __('Go-live', 'sfxtheme')],
                ['id' => Catalogue::CLEANUP, 'title' => __('Clean-up', 'sfxtheme')],
            ],
            'profiles' => self::profiles(),
            'settingsProfile' => Settings::get()['profile'],
            'last' => $last,
            'items' => $items,
            'currentUser' => self::user_name(get_current_user_id()),
            'strings' => [
                'status' => Status::labels() + [
                    'pending' => __('Not checked yet', 'sfxtheme'),
                    'checking' => __('Being checked', 'sfxtheme'),
                ],
                'perspective' => [
                    'server' => __('Server', 'sfxtheme'),
                    'browser' => __('Browser', 'sfxtheme'),
                    'loopback' => __('Loopback', 'sfxtheme'),
                ],
                'tag' => [
                    'running' => __('in progress', 'sfxtheme'),
                    'unsaved' => __('not saved', 'sfxtheme'),
                    'unknown' => __('save status unknown', 'sfxtheme'),
                ],
                /* translators: %1$s: number of findings */
                'findingOne' => _n('%1$s finding', '%1$s findings', 1, 'sfxtheme'),
                /* translators: %1$s: number of findings */
                'findingMany' => _n('%1$s finding', '%1$s findings', 2, 'sfxtheme'),
                'why' => __('Why', 'sfxtheme'),
                'recommendation' => __('Recommendation', 'sfxtheme'),
                'steps' => __('How to', 'sfxtheme'),
                'noAction' => __('Nothing to do: this is the good state.', 'sfxtheme'),
                'onlyAction' => __('Only what needs action', 'sfxtheme'),
                'notChecked' => __('Not checked yet.', 'sfxtheme'),
                'intro' => __('The check looks for exposed files, risky settings and launch mistakes, and shows what to fix first.', 'sfxtheme'),
                'noActionTab' => __('No action needed in this area.', 'sfxtheme'),
                /* translators: 1: date, 2: user, 3: site profile */
                'meta' => __('Last check: %1$s by %2$s, profile %3$s.', 'sfxtheme'),
                /* translators: %1$s: site profile */
                'nextProfile' => __('The next check uses the profile %1$s.', 'sfxtheme'),
                'change' => __('Change', 'sfxtheme'),
                'check' => __('Check now', 'sfxtheme'),
                'probe' => __('Uploads probe (active test: briefly creates a test file)', 'sfxtheme'),
                'sectionsTitle' => __('Areas', 'sfxtheme'),
                'urgentTitle' => __('Most urgent', 'sfxtheme'),
                'urgentNone' => __('Nothing urgent: no red or yellow checks.', 'sfxtheme'),
                'running' => __('Checking … keep this tab open until the results are saved.', 'sfxtheme'),
                /* translators: 1: checks done, 2: checks in this run */
                'progress' => __('%1$s of %2$s checks done', 'sfxtheme'),
                'saving' => __('Saving the results …', 'sfxtheme'),
                'saved' => __('Done. The results are saved.', 'sfxtheme'),
                /* translators: %1$s: error message */
                'startFailed' => __('The check could not start: %1$s', 'sfxtheme'),
                /* translators: %1$s: error message */
                'notSaved' => __('Not saved: %1$s The last saved check still counts; the results below are not saved.', 'sfxtheme'),
                'unknownSave' => __('Save status unknown: no answer arrived. Reload the page to see what was stored.', 'sfxtheme'),
                'statusStartFailed' => __('The check could not start.', 'sfxtheme'),
                'statusNotSaved' => __('Not saved.', 'sfxtheme'),
                'statusUnknown' => __('Save status unknown.', 'sfxtheme'),
                'retry' => __('Save again', 'sfxtheme'),
                'recheck' => __('Check again', 'sfxtheme'),
                'reload' => __('Reload page', 'sfxtheme'),
                'requestFailed' => __('Request failed.', 'sfxtheme'),
                'timeout' => __('No answer within 60 seconds.', 'sfxtheme'),
                'itemsTitle' => __('Checked by hand', 'sfxtheme'),
                /* translators: 1: user, 2: date */
                'itemDone' => __('done by %1$s on %2$s', 'sfxtheme'),
                'itemsReset' => __('Reset these items', 'sfxtheme'),
                'itemsResetConfirm' => __('Untick all four items?', 'sfxtheme'),
                'copied' => __('Copied', 'sfxtheme'),
                'copyFailed' => __('Copying is not possible here – the text is selected, copy it with Ctrl+C or Cmd+C.', 'sfxtheme'),
                'settingsDirty' => __('not saved', 'sfxtheme'),
                'tablist' => __('Areas of the security check', 'sfxtheme'),
            ],
        ];
    }

    /**
     * The catalogue as the script gets it: guidance per check, links
     * resolved to admin URLs (tab fragments stay fragments). A theme
     * module's link goes to the theme settings page while the module is off.
     *
     * @return list<array{id:string, section:string, how:string, perspective:string, title:string, why:string, recommendation:string, steps:list<string>, links:list<array{label:string, url:string}>}>
     */
    public static function catalogue_data(): array
    {
        $data = [];
        foreach (Catalogue::all() as $id => $row) {
            $data[] = [
                'id' => $id,
                'section' => $row['section'],
                'how' => $row['how'],
                'perspective' => Catalogue::perspective_for($id, $row['how']),
                'title' => $row['title'],
                'why' => $row['why'],
                'recommendation' => $row['recommendation'],
                'steps' => $row['steps'],
                'links' => array_map([self::class, 'resolve_link'], $row['links']),
            ];
        }

        return $data;
    }

    /** @return array{label:string, url:string} */
    private static function resolve_link(array $link): array
    {
        $label = (string) $link['label'];
        $url = (string) $link['url'];
        if (isset($link['module']) && !\SFX\SFXBricksChildTheme::is_general_option_enabled((string) $link['module'])) {
            $label = (string) $link['fallback_label'];
            $url = 'admin.php?page=' . self::THEME_SETTINGS;
        }

        return ['label' => $label, 'url' => $url[0] === '#' ? $url : admin_url($url)];
    }

    /** Runs the probe cleanup when the page loads, so render_page() can report it. */
    public static function on_load(): void
    {
        self::$cleanup = Probe::cleanup();
    }

    public static function add_menu(): void
    {
        if (!Access::allowed()) {
            return;
        }
        add_submenu_page(
            'tools.php',
            __('Sicherheits-Check', 'sfxtheme'),
            __('Sicherheits-Check', 'sfxtheme'),
            'manage_options',
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    /** @param array $post unslashed request fields */
    public static function collect(array $post): array
    {
        $changes = [];
        if (isset($post['profile']) && is_string($post['profile'])) {
            $changes['profile'] = $post['profile'];
        }
        // An unchecked box or an emptied list is a value, not an absent field.
        $changes['indexability_paths'] = isset($post['indexability_paths']) && is_array($post['indexability_paths']) ? $post['indexability_paths'] : [];
        $changes['sitemap_allow'] = isset($post['sitemap_allow']) && is_array($post['sitemap_allow']) ? $post['sitemap_allow'] : [];
        if (isset($post['fallback_theme']) && is_string($post['fallback_theme'])) {
            $changes['fallback_theme'] = $post['fallback_theme'];
        }

        return $changes;
    }

    public static function handle_save(): void
    {
        check_admin_referer(Access::NONCE);
        if (!Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'sfxtheme'), '', ['response' => 403]);
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            wp_die(esc_html__('This action requires a form submission.', 'sfxtheme'), '', ['response' => 405]);
        }

        $status = Settings::save_status(self::collect(wp_unslash($_POST)));

        wp_safe_redirect(add_query_arg('sfx_sc', $status, admin_url('tools.php?page=' . self::$menu_slug)) . '#einstellungen');
        exit;
    }

    public static function render_page(): void
    {
        if (!Access::allowed()) {
            wp_die(esc_html__('You are not allowed to do this.', 'sfxtheme'), '', ['response' => 403]);
        }

        $notice = isset($_GET['sfx_sc']) && is_string($_GET['sfx_sc']) ? sanitize_key(wp_unslash($_GET['sfx_sc'])) : '';

        echo '<div class="wrap"><h1>' . esc_html__('Sicherheits-Check', 'sfxtheme') . '</h1>';
        if (in_array($notice, self::NOTICES, true)) {
            $messages = [
                'saved' => [__('Settings saved.', 'sfxtheme'), 'success'],
                'busy' => [__('Busy, please try again.', 'sfxtheme'), 'warning'],
                'failed' => [__('The settings could not be saved. Nothing was changed.', 'sfxtheme'), 'error'],
            ];
            printf(
                '<div class="notice notice-%s"><p>%s</p></div>',
                esc_attr($messages[$notice][1]),
                esc_html($messages[$notice][0])
            );
        }

        self::render_cleanup(self::$cleanup);
        $nginx = Template::looks_like_nginx();
        self::render_panels(
            Template::blocks(Locations::current(), $nginx),
            $nginx,
            Settings::get(),
            self::entry_types(get_post_types([], 'objects'), get_taxonomies([], 'objects'), Runs::last()),
            self::themes()
        );
        echo '</div>';
    }

    /**
     * The app container with the two server-rendered panels; the script
     * adds the tab bar, the Übersicht and the section tabs around them.
     *
     * @param array<string,string> $offered stylesheet => name
     * @param array<string,string> $themes  stylesheet => name
     */
    public static function render_panels(array $blocks, bool $nginx, array $settings, array $offered, array $themes): void
    {
        echo '<div id="sfx-sc-app" class="sfx-sc-app">';
        echo '<div id="sfx-sc-panel-htaccess" class="sfx-sc-panel">';
        self::render_template($blocks, $nginx);
        echo '</div>';
        echo '<div id="sfx-sc-panel-einstellungen" class="sfx-sc-panel">';
        echo '<h2>' . esc_html__('Settings', 'sfxtheme') . '</h2>';
        self::render_form($settings, $offered, $themes);
        echo '</div>';
        echo '</div>';
    }

    /** Probe files the page-load cleanup kept or found unregistered (paths escaped here), and a registry it could not rewrite. */
    public static function render_cleanup(?array $cleanup): void
    {
        if (!empty($cleanup['busy'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The test files of the uploads probe could not be checked: the security check is busy right now. Reload the page to try again.', 'sfxtheme') . '</p></div>';
            return;
        }
        if (!empty($cleanup['store_failed'])) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('The record of the uploads probe\'s test files could not be updated; the cleanup tries again on the next page load.', 'sfxtheme') . '</p></div>';
        }
        $kept = $cleanup['kept'] ?? [];
        $unregistered = $cleanup['unregistered'] ?? [];
        if ($kept === [] && $unregistered === []) {
            return;
        }
        echo '<div class="notice notice-warning"><p>' . esc_html__('Test files of the uploads probe need a look. They were not deleted:', 'sfxtheme') . '</p><ul>';
        foreach ($kept as $path) {
            echo '<li>' . esc_html(sprintf(
                /* translators: %s: server path */
                __('%s — content differs from the test file or could not be deleted', 'sfxtheme'),
                (string) $path
            )) . '</li>';
        }
        foreach ($unregistered as $path) {
            echo '<li>' . esc_html(sprintf(
                /* translators: %s: server path */
                __('%s — not created by a recorded test run', 'sfxtheme'),
                (string) $path
            )) . '</li>';
        }
        echo '</ul></div>';
    }

    /**
     * The .htaccess template: steps, then each block as copy text.
     *
     * @param list<array{id:string, title:string, file:?string, note:string, text:string}> $blocks
     */
    public static function render_template(array $blocks, bool $nginx): void
    {
        echo '<h2>' . esc_html__('Template for .htaccess', 'sfxtheme') . '</h2>';
        echo '<p>' . esc_html__('The security check never writes these files. To use a block:', 'sfxtheme') . '</p><ol>';
        foreach ([
            __('Download the current .htaccess of that folder (file manager or SFTP) and keep it as a backup, or note that none exists.', 'sfxtheme'),
            __('Root folder blocks: insert them above # BEGIN WordPress, or at the top of the file when that line is missing. Uploads and .git blocks: insert them at the top of the .htaccess in their own folder; create that file if it does not exist.', 'sfxtheme'),
            __('Run the check again.', 'sfxtheme'),
            __('If the site shows a 500 error, put the backup back, or delete the file if you created it.', 'sfxtheme'),
        ] as $step) {
            echo '<li>' . esc_html($step) . '</li>';
        }
        echo '</ol>';
        echo '<p>' . esc_html__('Add one block at a time. A host can forbid some directives (AllowOverride), which shows as a 500 error. Conflicts with other rules cannot be ruled out.', 'sfxtheme') . '</p>';
        if ($nginx) {
            echo '<p><strong>' . esc_html__('This server looks like nginx. An Apache rule does nothing for files nginx serves itself; give the nginx text to the host.', 'sfxtheme') . '</strong></p>';
        }

        foreach ($blocks as $block) {
            $id = 'sfx-sc-tpl-' . $block['id'];
            echo '<h3>' . esc_html($block['title']) . '</h3>';
            if (($block['note'] ?? '') !== '') {
                echo '<p>' . esc_html($block['note']) . '</p>';
            }
            if (in_array($block['id'], ['uploads', 'git'], true)) {
                echo '<p>' . esc_html__('Where: at the top of this folder\'s own .htaccess; create the file if it does not exist.', 'sfxtheme') . '</p>';
            } elseif ($block['id'] !== 'nginx') {
                echo '<p>' . esc_html__('Where: above # BEGIN WordPress, or at the top of the file when that line is missing.', 'sfxtheme') . '</p>';
            }
            if ($block['file'] === null) {
                echo '<p>' . esc_html__('File: the folder this file belongs in could not be determined on this site; ask the host where it is.', 'sfxtheme') . '</p>';
            } elseif ($block['file'] !== '') {
                echo '<p>' . esc_html__('File:', 'sfxtheme') . ' <code>' . esc_html($block['file']) . '</code></p>';
            }
            echo '<pre id="' . esc_attr($id) . '" class="sfx-sc-template">' . esc_html($block['text']) . '</pre>';
            echo '<p><button type="button" class="button sfx-sc-copy" data-target="' . esc_attr($id) . '">' . esc_html__('Copy', 'sfxtheme') . '</button></p>';
        }
    }

    /**
     * The settings form. Entries stored in the allow list but no longer
     * offered get their own list, still ticked: they keep silencing their
     * type until the admin unticks them and saves.
     *
     * @param array<string,string> $offered id => label offered as checkboxes
     * @param array<string,string> $themes  stylesheet => name
     */
    public static function render_form(array $settings, array $offered, array $themes): void
    {
        $stale = array_values(array_diff($settings['sitemap_allow'], array_keys($offered)));
        $profiles = self::profiles();

        echo '<form id="sfx-sc-settings" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="' . esc_attr(self::ACTION) . '">';
        wp_nonce_field(Access::NONCE);
        echo '<p class="description">' . esc_html__('A check always uses the saved settings.', 'sfxtheme') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr><th scope="row"><label for="sfx-sc-profile">' . esc_html__('Site profile', 'sfxtheme') . '</label></th><td>';
        echo '<select id="sfx-sc-profile" name="profile">';
        foreach ($profiles as $value => $label) {
            echo '<option value="' . esc_attr($value) . '"' . ($settings['profile'] === $value ? ' selected' : '') . '>' . esc_html($label) . '</option>';
        }
        echo '</select></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Pages to check for indexing', 'sfxtheme') . '</th><td>';
        echo '<p class="description" id="sfx-sc-paths-help">' . esc_html__('The home page is always checked. Add up to 5 more paths, e.g. /kontakt/.', 'sfxtheme') . '</p>';
        $paths = $settings['indexability_paths'];
        for ($i = 0; $i < Settings::MAX_PATHS; $i++) {
            $path = $paths[$i] ?? '';
            $input_id = 'sfx-sc-path-' . ($i + 1);
            echo '<p><label for="' . esc_attr($input_id) . '" class="screen-reader-text">' . esc_html(sprintf(
                /* translators: %d: number of the path field, 1 to 5 */
                __('Page %d for the indexing check', 'sfxtheme'),
                $i + 1
            )) . '</label>';
            echo '<input type="text" class="regular-text" id="' . esc_attr($input_id) . '" aria-describedby="sfx-sc-paths-help" name="indexability_paths[]" value="' . esc_attr($path) . '">';
            if ($path !== '' && !Settings::valid_path($path)) {
                echo ' <strong>' . esc_html__('Rejected', 'sfxtheme') . '</strong>';
            }
            echo '</p>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Sitemap entries that are intended', 'sfxtheme') . '</th><td><fieldset>';
        foreach ($offered as $id => $label) {
            echo '<label><input type="checkbox" name="sitemap_allow[]" value="' . esc_attr($id) . '"'
                . (in_array($id, $settings['sitemap_allow'], true) ? ' checked' : '') . '> ' . esc_html($label) . '</label><br>';
        }
        echo '</fieldset>';
        if ($stale !== []) {
            echo '<p><strong>' . esc_html__('Stored, currently not offered', 'sfxtheme') . '</strong></p>';
            echo '<p class="description">' . esc_html__('These entries still silence their type. Untick and save to remove them.', 'sfxtheme') . '</p><fieldset>';
            foreach ($stale as $id) {
                echo '<label><input type="checkbox" name="sitemap_allow[]" value="' . esc_attr($id) . '" checked> ' . esc_html($id) . '</label><br>';
            }
            echo '</fieldset>';
        }
        echo '</td></tr>';

        echo '<tr><th scope="row"><label for="sfx-sc-fallback">' . esc_html__('Fallback theme', 'sfxtheme') . '</label></th><td>';
        echo '<select id="sfx-sc-fallback" name="fallback_theme"><option value="">' . esc_html__('None', 'sfxtheme') . '</option>';
        foreach ($themes as $stylesheet => $name) {
            echo '<option value="' . esc_attr($stylesheet) . '"' . ($settings['fallback_theme'] === (string) $stylesheet ? ' selected' : '') . '>' . esc_html($name) . '</option>';
        }
        echo '</select></td></tr>';

        echo '</tbody></table>';
        echo '<p class="submit"><button type="submit" id="sfx-sc-settings-save" class="button button-primary">' . esc_html__('Save settings', 'sfxtheme') . '</button>'
            . ' <span id="sfx-sc-settings-dirty" class="sfx-sc-dirty" aria-live="polite"></span></p>';
        echo '</form>';
    }

    /** @return array<string,string> */
    public static function profiles(): array
    {
        return [
            'live' => __('Live', 'sfxtheme'),
            'staging' => __('Staging', 'sfxtheme'),
            'private' => __('Private', 'sfxtheme'),
        ];
    }

    /** @return array<string,string> the four manual items (spec "Manual items") */
    private static function item_labels(): array
    {
        return [
            'contact_form' => __('Contact form sent as a guest, and the e-mail arrived.', 'sfxtheme'),
            'offsite_backup' => __('A backup exists outside the server, and a restore has been tested.', 'sfxtheme'),
            'two_factor' => __('All administrators use two-factor sign-in.', 'sfxtheme'),
            'accounts_handover' => __('Test and old accounts removed; access handed over to the client.', 'sfxtheme'),
        ];
    }

    private static function item_meta(int $user, int $date): string
    {
        /* translators: 1: user, 2: date */
        return sprintf(__('done by %1$s on %2$s', 'sfxtheme'), self::user_name($user), Runs::date_display($date));
    }

    private static function user_name(int $id): string
    {
        $user = $id > 0 ? get_userdata($id) : false;
        return $user ? (string) $user->display_name : '#' . $id;
    }

    /**
     * The sitemap allow list's offer (spec "Sitemap allow list"):
     * attachments, the author sitemap, every post type and taxonomy that is
     * public or publicly queryable, and every type the last saved
     * sitemap_entries result reported — never WordPress' internal types.
     *
     * @param array<string,object> $post_types slug => post type object
     * @param array<string,object> $taxonomies slug => taxonomy object
     * @param array|null           $last       Runs::last()
     * @return array<string,string> id => label
     */
    public static function entry_types(array $post_types, array $taxonomies, ?array $last): array
    {
        $reported = [];
        foreach ((array) ($last['results']['sitemap_entries']['findings'] ?? []) as $finding) {
            $id = substr((string) ($finding['id'] ?? ''), strlen('sitemap_entries:'));
            if (Settings::is_entry_type($id)) {
                $reported[$id] = true;
            }
        }

        $types = [
            'attachments' => __('Attachment pages', 'sfxtheme'),
            'authors' => __('Author sitemap', 'sfxtheme'),
        ];
        $add = static function (string $id, $object, string $label) use (&$types, $reported): void {
            $eligible = !empty($object->public) || !empty($object->publicly_queryable) || isset($reported[$id]);
            if ($eligible && !in_array($id, self::INTERNAL_TYPES, true) && Settings::is_entry_type($id)) {
                $types[$id] = $label;
            }
        };
        foreach ($post_types as $slug => $object) {
            /* translators: %s: post type name */
            $add('post_type:' . $slug, $object, sprintf(__('Post type: %s', 'sfxtheme'), ($object->label ?? '') ?: $slug));
        }
        foreach ($taxonomies as $slug => $object) {
            /* translators: %s: taxonomy name */
            $add('taxonomy:' . $slug, $object, sprintf(__('Taxonomy: %s', 'sfxtheme'), ($object->label ?? '') ?: $slug));
        }
        foreach (array_keys($reported) as $id) {
            if (!isset($types[$id]) && !in_array($id, self::INTERNAL_TYPES, true)) {
                $types[$id] = $id;
            }
        }

        return $types;
    }

    /** @return array<string,string> */
    private static function themes(): array
    {
        $themes = [];
        foreach (wp_get_themes() as $stylesheet => $theme) {
            $themes[(string) $stylesheet] = (string) $theme->get('Name');
        }

        return $themes;
    }
}
