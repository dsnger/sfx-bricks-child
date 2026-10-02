<?php

declare(strict_types=1);

namespace SFX\Redirects;

/**
 * Tools → Redirects: the screen and every admin-post handler except the
 * settings save (Settings::save_from_request).
 *
 * The page lives under Tools, not the theme menu, because the theme menu is
 * only registered for SFX_THEME_ADMINS users and editors (edit_others_posts)
 * manage redirects too.
 *
 * Markup uses core admin classes (form-table, nav-tab, card, notice) plus the
 * module's own sfx-rf-* classes for the rule form (assets/redirects-admin.css,
 * enqueued on this page only), not the theme's sfx-* admin styles: those are
 * only enqueued on the theme's own screens, not on a tools.php page.
 */
final class AdminPage
{
    public const CAPABILITY = 'edit_others_posts';

    /** Per-user flash storage for this page's notices; see finish(). */
    public const NOTICE_TRANSIENT = 'sfx_redirects_notices_';

    /** Plain statics, as every module has them; wrapped in __() where rendered. */
    public static $menu_slug = 'sfx-redirects';
    public static $page_title = 'Redirects';
    public static $description = 'Manage redirects (301, 302, 307, 308, 410), log 404 errors and create redirects automatically when a page\'s address changes.';

    private const OPS         = ['enable', 'disable', 'delete', 'reset'];
    private const LOG_OPS     = ['delete', 'clear_all'];
    private const MAX_UPLOAD  = 2 * 1024 * 1024;
    private const MAX_RECORDS = 5000;
    private const LIST_LIMIT  = 20;
    private const FORM_TTL    = 5 * 60;

    private const HANDLERS = [
        'sfx_redirects_save_rule'   => 'handle_save_rule',
        'sfx_redirects_rule_action' => 'handle_rule_action',
        'sfx_redirects_bulk'        => 'handle_bulk',
        'sfx_redirects_404_action'  => 'handle_404_action',
        'sfx_redirects_import'      => 'handle_import',
        'sfx_redirects_export'      => 'handle_export',
    ];

    private const CSV_EXPORT_COLUMNS = ['source', 'target', 'status_code', 'match_type', 'enabled', 'note', 'hits', 'last_hit', 'origin'];

    /** Target picker: the type select's value for term archives (no post type can be named so). */
    /** A colon is not allowed in a post type key (sanitize_key), so no post type can collide. */
    private const PICKER_TERM = ':term';
    /**
     * Entries the picker lists at once — the whole list when the search box is
     * empty, or the matches for a search. One more is fetched to know whether
     * the list was cut. ponytail: 200 keeps the select usable and the query
     * cheap; a site with more entries narrows them with the search box.
     */
    private const PICKER_LIMIT = 200;

    /** More entries than this, and the picker also offers its search box. */
    private const PICKER_SEARCH_THRESHOLD = 20;

    /** Distinct codes per notice; keeps each add_settings_error() entry separate. */
    private static int $notice_seq = 0;

    /** add_submenu_page()'s hook suffix, so the script loads on this page only. */
    private static string $hook_suffix = '';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        foreach (self::HANDLERS as $action => $method) {
            add_action('admin_post_' . $action, [self::class, $method]);
        }
        add_action('wp_ajax_sfx_redirects_search', [self::class, 'handle_search']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_assets']);
    }

    public static function add_menu(): void
    {
        self::$hook_suffix = (string) add_submenu_page(
            'tools.php',
            __('Redirects', 'sfxtheme'),
            __('Redirects', 'sfxtheme'),
            self::CAPABILITY,
            self::$menu_slug,
            [self::class, 'render_page']
        );
    }

    /** The rule form's stylesheet and the target picker's script, on the Redirects page only (spec A3). */
    public static function enqueue_assets($hook_suffix): void
    {
        if (self::$hook_suffix === '' || $hook_suffix !== self::$hook_suffix) {
            return;
        }

        $css = '/inc/Redirects/assets/redirects-admin.css';
        wp_enqueue_style(
            'sfx-redirects-admin',
            get_stylesheet_directory_uri() . $css,
            [],
            (string) filemtime(get_stylesheet_directory() . $css)
        );

        $file = '/inc/Redirects/assets/redirects-admin.js';
        wp_enqueue_script(
            'sfx-redirects-admin',
            get_stylesheet_directory_uri() . $file,
            [],
            (string) filemtime(get_stylesheet_directory() . $file),
            true
        );
        wp_localize_script('sfx-redirects-admin', 'sfxRedirectsPicker', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('sfx_redirects_search'),
            'searchThreshold' => self::PICKER_SEARCH_THRESHOLD,
            'i18n'    => [
                'loading'   => __('Loading…', 'sfxtheme'),
                'more'      => __('Only the first entries are shown — type in the search box to narrow them down.', 'sfxtheme'),
                'noResults' => __('No results.', 'sfxtheme'),
                'choose'    => __('Choose a result', 'sfxtheme'),
                'error'     => __('The search failed. Try again.', 'sfxtheme'),
            ],
        ]);
    }

    public static function page_url(string $tab = ''): string
    {
        return admin_url('tools.php?page=' . self::$menu_slug . ($tab !== '' ? '&tab=' . rawurlencode($tab) : ''));
    }

    /**
     * Nonce AND capability, before any input is read or written (invariant 2).
     * check_admin_referer() dies on its own when the nonce fails. The settings
     * gate adds theme-settings access, so that tab only lives on a page the
     * user can open in the first place.
     */
    public static function guard(string $action, bool $settings = false): void
    {
        check_admin_referer($action);

        if (!current_user_can(self::CAPABILITY)
            || ($settings && !\SFX\AccessControl::can_access_theme_settings())) {
            wp_die(esc_html__('You are not allowed to do this.', 'sfxtheme'), '', ['response' => 403]);
        }
    }

    // ------------------------------------------------------------------
    // Handlers
    // ------------------------------------------------------------------

    public static function handle_save_rule(): void
    {
        self::guard('sfx_redirects_save_rule');
        self::require_ready('redirects');

        $post     = wp_unslash($_POST);
        $id       = self::id_value($post['id'] ?? '0');
        $from_404 = self::id_value($post['from_404'] ?? '0');

        $form = [
            'id'       => $id ?? 0,
            'from_404' => $from_404 ?? 0,
            'enabled'  => isset($post['enabled']) && is_string($post['enabled']) && $post['enabled'] !== '' && $post['enabled'] !== '0',
        ];
        foreach (['source', 'match_type', 'target', 'status_code', 'note'] as $field) {
            // Non-strings are passed on as they are: Rule::validate() rejects them with a message.
            $form[$field] = $post[$field] ?? '';
        }

        if ($id === null || $from_404 === null) {
            self::notice(__('The form was submitted with an invalid rule id.', 'sfxtheme'));
            self::finish('redirects');
        }

        $checked = Rule::validate($form);
        if ($checked['errors'] !== []) {
            foreach ($checked['errors'] as $message) {
                self::notice($message);
            }
            self::keep_form($form);
            self::finish('redirects');
        }

        // A from_404 id counts only when that log row exists and logs exactly the
        // path this rule now redirects. An editor who changed the source (or a
        // stale link) must not mark the rule "404" or delete an unrelated row.
        if ($from_404 > 0) {
            $logged = $checked['rule']['match_type'] === 'exact' ? Repository::log_path($from_404) : null;
            if ($logged === false) {
                // Unreadable is not "missing": save nothing rather than guess the origin.
                self::notice(__('A database error occurred; the change was not saved.', 'sfxtheme'));
                self::keep_form($form);
                self::finish('redirects');
            }
            if ($logged !== $checked['rule']['source']) {
                $from_404 = 0;
            }
        }

        $result = Repository::save($checked['rule'], $id, $from_404 > 0 ? '404' : 'manual');

        switch ($result['status']) {
            case 'created':
                self::notice(__('Redirect saved.', 'sfxtheme'), 'success');
                break;
            case 'updated':
                self::notice(__('Redirect updated.', 'sfxtheme'), 'success');
                break;
            case 'unchanged':
                self::notice(__('Nothing to change — the redirect was already saved like this.', 'sfxtheme'), 'info');
                break;
            default:
                self::notice($result['message'] ?? __('A database error occurred; the change was not saved.', 'sfxtheme'));
                self::keep_form($form);
                self::finish('redirects');
        }

        // Only after the rule was written, and only that one row.
        if ($from_404 > 0 && Repository::delete_404([$from_404]) === false) {
            self::notice(__('The redirect was saved, but its 404 log entry could not be removed.', 'sfxtheme'), 'warning');
        }

        self::finish('redirects');
    }

    /** Single-row actions arrive as wp_nonce_url links (GET to admin-post.php). */
    public static function handle_rule_action(): void
    {
        self::guard('sfx_redirects_rule_action');
        self::require_ready('redirects');

        $request = wp_unslash($_GET);
        $op      = self::op_value($request['op'] ?? null, self::OPS);
        $id      = self::id_value($request['id'] ?? null);
        if ($op === null || $id === null || $id === 0) {
            self::notice(__('Unknown action.', 'sfxtheme'));
            self::finish('redirects');
        }

        $result = Repository::apply_op($op, [$id]);
        if ($result['missing'] > 0) {
            /* translators: %d: rule id */
            self::notice(sprintf(__('Rule #%d no longer exists.', 'sfxtheme'), $id));
            $result['missing'] = 0;
        }
        self::report_op($op, $result);
        self::finish('redirects');
    }

    public static function handle_bulk(): void
    {
        self::guard('sfx_redirects_bulk');
        self::require_ready('redirects');

        $post = wp_unslash($_POST);
        $op   = self::op_value($post['op'] ?? null, self::OPS);
        $ids  = self::ids_value($post['ids'] ?? []);
        if ($op === null) {
            self::notice(__('Choose a bulk action.', 'sfxtheme'));
            self::finish('redirects');
        }
        if ($ids === null) {
            self::notice(__('The selection was not valid.', 'sfxtheme'));
            self::finish('redirects');
        }
        if ($ids === []) {
            self::notice(__('Select at least one redirect.', 'sfxtheme'), 'warning');
            self::finish('redirects');
        }

        $result = Repository::apply_op($op, $ids);
        if ($result['missing'] > 0) {
            self::notice(sprintf(
                /* translators: %d: number of rules */
                _n('%d selected rule no longer exists.', '%d selected rules no longer exist.', $result['missing'], 'sfxtheme'),
                $result['missing']
            ), 'warning');
            $result['missing'] = 0;
        }
        self::report_op($op, $result);
        self::finish('redirects');
    }

    /** Bulk form (POST, ids[]) and row links (GET, id) share one action. */
    public static function handle_404_action(): void
    {
        self::guard('sfx_redirects_404_action');
        self::require_ready('404');

        $request = wp_unslash(isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET);
        $op      = self::op_value($request['op'] ?? null, self::LOG_OPS);
        if ($op === null) {
            self::notice(__('Choose a bulk action.', 'sfxtheme'));
            self::finish('404');
        }

        if ($op === 'clear_all') {
            // Emptying the whole log is a form action only; links carry single-row ops.
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
                self::notice(__('The selection was not valid.', 'sfxtheme'));
                self::finish('404');
            }
            if (Repository::clear_404()) {
                self::notice(__('The 404 log was cleared.', 'sfxtheme'), 'success');
            } else {
                self::notice(__('A database error occurred; the log was not cleared.', 'sfxtheme'));
            }
            self::finish('404');
        }

        // ids[] (several rows) only from the bulk form; links carry one id.
        if (isset($request['ids']) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $ids = self::ids_value($request['ids']);
        } else {
            $id  = self::id_value($request['id'] ?? null);
            $ids = $id === null ? null : array_filter([$id]);
        }
        if ($ids === null) {
            self::notice(__('The selection was not valid.', 'sfxtheme'));
            self::finish('404');
        }
        if ($ids === []) {
            self::notice(__('Select at least one log entry.', 'sfxtheme'), 'warning');
            self::finish('404');
        }

        $deleted = Repository::delete_404($ids);
        if ($deleted === false) {
            self::notice(__('A database error occurred; nothing was deleted.', 'sfxtheme'));
        } elseif ($deleted === 0) {
            self::notice(__('Nothing was deleted — the entries no longer exist.', 'sfxtheme'), 'info');
        } else {
            self::notice(sprintf(
                /* translators: %d: number of log entries */
                _n('%d log entry deleted.', '%d log entries deleted.', $deleted, 'sfxtheme'),
                $deleted
            ), 'success');
        }
        self::finish('404');
    }

    /**
     * Upload checks in the spec's order; the file is read from its tmp path and
     * never stored. The whole file is read and counted before the first write,
     * so an oversized file changes nothing. Rows are then written one by one
     * (Repository::import); a request that dies mid-file leaves the earlier
     * rows written, and re-importing is safe because every row is an upsert.
     */
    public static function handle_import(): void
    {
        self::guard('sfx_redirects_import');
        self::require_ready('import');

        $file = $_FILES['file'] ?? null;
        if (!is_array($file)
            || !isset($file['name'], $file['tmp_name'], $file['error'], $file['size'])
            || !is_string($file['name']) || !is_string($file['tmp_name'])
            || !is_int($file['error']) || !is_int($file['size'])) {
            self::notice(__('Choose a CSV file to import.', 'sfxtheme'));
            self::finish('import');
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            self::notice(self::upload_error_message($file['error']));
            self::finish('import');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            self::notice(__('The upload could not be verified. Try again.', 'sfxtheme'));
            self::finish('import');
        }
        if ($file['size'] > self::MAX_UPLOAD) {
            self::notice(__('The file is larger than 2 MB. Split it into smaller files.', 'sfxtheme'));
            self::finish('import');
        }
        // The client-supplied MIME type is not trusted; the name is only a hint for the user.
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            self::notice(__('Only .csv files can be imported.', 'sfxtheme'));
            self::finish('import');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(120);
        }

        $handle = fopen($file['tmp_name'], 'rb');
        if ($handle === false) {
            self::notice(__('The uploaded file could not be read.', 'sfxtheme'));
            self::finish('import');
        }

        // Consume a UTF-8 BOM before the parser sees it: left in the stream it
        // turns a quoted first header cell ("source") into a literal name.
        if (fread($handle, 3) !== "\xEF\xBB\xBF") {
            rewind($handle);
        }

        // Record numbers count every CSV record, blank ones included; the header is 1.
        $header  = null;
        $records = [];
        $number  = 0;
        $too_many = false;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $number++;
            if ($row === [null]) {
                continue; // blank line
            }
            if ($header === null) {
                $header = $row;
                continue;
            }
            if (count($records) === self::MAX_RECORDS) {
                $too_many = true;
                break;
            }
            $records[] = [$number, $row];
        }
        fclose($handle);

        if ($header === null) {
            self::notice(__('The file is empty.', 'sfxtheme'));
            self::finish('import');
        }
        if ($too_many) {
            /* translators: %d: maximum number of records */
            self::notice(sprintf(__('The file has more than %d records. Nothing was imported; split the file.', 'sfxtheme'), self::MAX_RECORDS));
            self::finish('import');
        }

        // A mixed header comes back as an error message: the whole file is rejected.
        $dialect = Rule::csv_dialect($header);
        if ($dialect !== 'ours' && $dialect !== 'redirection') {
            self::notice($dialect);
            self::notice(__('Nothing was imported.', 'sfxtheme'));
            self::finish('import');
        }
        $redirection = $dialect === 'redirection';

        $mapped = $redirection ? Rule::redirection_header_map($header) : Rule::csv_header_map($header);
        if ($mapped['errors'] !== []) {
            foreach ($mapped['errors'] as $message) {
                self::notice($message);
            }
            self::notice(__('Nothing was imported.', 'sfxtheme'));
            self::finish('import');
        }

        $valid    = [];
        $skipped  = [];
        $home_url = home_url();
        foreach ($records as [$record, $row]) {
            $input = $redirection
                ? Rule::redirection_record($row, $mapped['map'], $home_url)
                : Rule::csv_record($row, $mapped['map']);
            if (is_string($input)) {
                $skipped[] = self::record_message($record, $input);
                continue;
            }
            $checked = Rule::validate($input);
            if ($checked['errors'] !== []) {
                $skipped[] = self::record_message($record, implode(' ', $checked['errors']));
                continue;
            }
            $valid[] = ['record' => $record, 'rule' => $checked['rule']];
        }

        $result = Repository::import($valid);
        $clean  = $skipped === [] && $result['conflicts'] === [] && $result['failed'] === 0;

        self::notice($redirection
            ? __('File format: Redirection plugin export.', 'sfxtheme')
            : __('File format: this module\'s CSV.', 'sfxtheme'), 'info');

        self::notice(sprintf(
            /* translators: 1: created, 2: updated, 3: unchanged, 4: skipped (invalid), 5: not imported (conflict), 6: failed (database error) */
            __('Import finished: %1$d created, %2$d updated, %3$d unchanged, %4$d skipped as invalid, %5$d not imported because of a conflict, %6$d failed.', 'sfxtheme'),
            $result['created'],
            $result['updated'],
            $result['unchanged'],
            count($skipped),
            count($result['conflicts']),
            $result['failed']
        ), $clean ? 'success' : 'warning');

        if ($skipped !== []) {
            self::list_notice(__('Skipped records:', 'sfxtheme'), $skipped, 'warning');
        }
        if ($result['conflicts'] !== []) {
            self::list_notice(__('Records not imported:', 'sfxtheme'), $result['conflicts'], 'warning');
        }
        if ($result['failed'] > 0) {
            self::notice(sprintf(
                /* translators: %d: number of records */
                _n('%d record failed because of a database error.', '%d records failed because of a database error.', $result['failed'], 'sfxtheme'),
                $result['failed']
            ));
        }

        self::finish('import');
    }

    /**
     * The one handler that does not redirect on success: it streams the file
     * and exits. Every failure is detected before the first header is sent, so
     * it can still redirect with a notice.
     */
    public static function handle_export(): void
    {
        self::guard('sfx_redirects_export');
        self::require_ready('import');

        $rows = Repository::export_rows();
        if ($rows === null) {
            self::notice(__('A database error occurred; the export was not created.', 'sfxtheme'));
            self::finish('import');
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=sfx-redirects-' . gmdate('Y-m-d') . '.csv');

        $out = fopen('php://output', 'w');
        fputcsv($out, self::CSV_EXPORT_COLUMNS, ',', '"', '');
        foreach ($rows as $row) {
            $cells = [
                $row['source'],
                $row['target'],
                (string) $row['status_code'],
                $row['match_type'],
                $row['enabled'] ? '1' : '0',
                $row['note'],
                (string) $row['hits'],
                (string) ($row['last_hit'] ?? ''),
                $row['origin'],
            ];
            fputcsv($out, array_map([Rule::class, 'csv_escape_cell'], $cells), ',', '"', '');
        }
        fclose($out);
        exit;
    }

    /**
     * Target picker list/search (admin-ajax, GET). A read, but it discloses
     * titles and paths, so nonce AND capability come first (spec A3). A q of
     * fewer than 2 characters lists the type's entries alphabetically, 2–100
     * characters search, anything longer returns nothing.
     * Returns {items: at most PICKER_LIMIT {label, path}, more: bool}; labels
     * are plain text, the page puts them into the DOM with textContent.
     */
    public static function handle_search(): void
    {
        check_ajax_referer('sfx_redirects_search');
        if (!current_user_can(self::CAPABILITY)) {
            wp_send_json_error(['message' => __('You are not allowed to do this.', 'sfxtheme')], 403);
        }

        $request = wp_unslash($_GET);
        $type    = $request['type'] ?? null;
        if (!is_string($type) || ($type !== self::PICKER_TERM && !isset(self::picker_post_types()[$type]))) {
            wp_send_json_error(['message' => __('Unknown content type.', 'sfxtheme')], 400);
        }
        $q = isset($request['q']) && is_string($request['q']) ? trim($request['q']) : '';
        if (mb_strlen($q) > 100) {
            wp_send_json_success(['items' => [], 'more' => false]);
        }
        // Fewer than two characters list instead of searching: WordPress's search
        // APIs treat "0" as empty, and one letter narrows nothing useful anyway.
        if (mb_strlen($q) < 2) {
            $q = '';
        }

        $home_url = home_url();
        $items    = [];

        if ($type === self::PICKER_TERM) {
            $taxonomies = get_taxonomies(['public' => true]);
            // An empty taxonomy list would make get_terms() search every taxonomy.
            $args = [
                'taxonomy'   => array_values($taxonomies),
                'hide_empty' => false,
                'orderby'    => 'name',
                'order'      => 'ASC',
                'number'     => self::PICKER_LIMIT + 1,
                // Otherwise a hierarchical taxonomy makes get_terms() load the
                // whole tree and slice afterwards, ignoring "number" in SQL.
                'hierarchical' => false,
            ];
            if ($q !== '') {
                $args['search'] = $q;
            }
            $terms = $taxonomies === [] ? [] : get_terms($args);
            if (is_wp_error($terms)) {
                wp_send_json_error(['message' => __('The search failed.', 'sfxtheme')], 500);
            }
            $fetched = count($terms);
            foreach (array_slice($terms, 0, self::PICKER_LIMIT) as $term) {
                $link = get_term_link($term);
                if (is_wp_error($link) || !is_string($link) || $link === '') {
                    continue;
                }
                $taxonomy = get_taxonomy($term->taxonomy);
                $name     = self::plain_text((string) $term->name);
                $items[]  = [
                    'label' => $taxonomy ? $name . ' (' . self::plain_text((string) $taxonomy->labels->singular_name) . ')' : $name,
                    'path'  => self::picker_path($link, $home_url),
                ];
            }
        } else {
            $args = [
                'post_type'           => $type,
                'post_status'         => 'publish',
                'posts_per_page'      => self::PICKER_LIMIT + 1,
                'no_found_rows'       => true,
                'suppress_filters'    => false,
                'ignore_sticky_posts' => true,
            ];
            if ($q !== '') {
                $args['s'] = $q; // search keeps WordPress's relevance order
            } else {
                $args['orderby'] = 'title';
                $args['order']   = 'ASC';
            }
            $query   = new \WP_Query($args);
            $fetched = count($query->posts);
            foreach (array_slice($query->posts, 0, self::PICKER_LIMIT) as $post) {
                $link = get_permalink($post);
                if (!is_string($link) || $link === '') {
                    continue;
                }
                $label = self::plain_text((string) get_the_title($post));
                $items[] = [
                    /* translators: %d: post id */
                    'label' => $label !== '' ? $label : sprintf(__('(no title) #%d', 'sfxtheme'), (int) $post->ID),
                    'path'  => self::picker_path($link, $home_url),
                ];
            }
        }

        // "More" is decided by what the query found, not by what survived the
        // link filter: a skipped entry must not hide that the list was cut.
        wp_send_json_success(['items' => $items, 'more' => $fetched > self::PICKER_LIMIT]);
    }

    // ------------------------------------------------------------------
    // Handler helpers
    // ------------------------------------------------------------------

    /** Handlers refuse and report while the schema is not installed. */
    private static function require_ready(string $tab): void
    {
        if (!Repository::ready()) {
            self::notice(self::not_ready_message());
            self::finish($tab);
        }
    }

    /**
     * Notices are stored as plain text and escaped only in render_notices(), at
     * the echo site (invariant 3). Several carry user input (CSV cells, rule
     * sources), and core's settings_errors() prints messages unescaped — so it
     * is never used for this group.
     */
    private static function notice(string $message, string $type = 'error'): void
    {
        add_settings_error('sfx_redirects', 'sfx_redirects_' . (++self::$notice_seq), $message, $type);
    }

    /** A heading plus the first LIST_LIMIT items, one per line (plain text). */
    private static function list_notice(string $heading, array $items, string $type): void
    {
        $lines = array_slice($items, 0, self::LIST_LIMIT);
        if (count($items) > count($lines)) {
            /* translators: %d: number of further entries not listed */
            $lines[] = sprintf(__('… and %d more.', 'sfxtheme'), count($items) - count($lines));
        }
        self::notice($heading . "\n" . implode("\n", array_map('strval', $lines)), $type);
    }

    /**
     * Prints this page's notices (including the Settings handler's), escaping
     * each line here — the only place they are ever rendered.
     */
    private static function render_notices(): void
    {
        $key     = self::NOTICE_TRANSIENT . get_current_user_id();
        $pending = get_transient($key);
        delete_transient($key);
        $notices = array_merge(is_array($pending) ? $pending : [], get_settings_errors('sfx_redirects'));

        foreach ($notices as $notice) {
            if (!is_array($notice)) {
                continue;
            }
            $type = (string) ($notice['type'] ?? 'error');
            $type = $type === 'updated' ? 'success' : $type;
            if (!in_array($type, ['error', 'success', 'warning', 'info'], true)) {
                $type = 'error';
            }
            $lines = array_map('esc_html', explode("\n", (string) ($notice['message'] ?? '')));
            echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>'
                . implode('<br>', $lines) // each line escaped just above
                . '</p></div>';
        }
    }

    /**
     * Post/Redirect/Get with this module's own per-user notice transient — NOT
     * core's site-global 'settings_errors' one. Notices here are plain text that
     * can carry CSV cells; core's settings_errors() prints any screen's pending
     * notices unescaped, so parking them in its transient would let an editor's
     * upload execute on an administrator's settings screen.
     */
    public static function finish(string $tab): void
    {
        set_transient(self::NOTICE_TRANSIENT . get_current_user_id(), get_settings_errors('sfx_redirects'), 60);
        wp_safe_redirect(self::page_url($tab));
        exit;
    }

    /** The submitted form comes back once, so a rejected save does not lose input. */
    private static function keep_form(array $form): void
    {
        foreach (['source', 'match_type', 'target', 'status_code', 'note'] as $field) {
            if (!is_string($form[$field])) {
                $form[$field] = '';
            }
        }
        set_transient('sfx_redirects_form_' . get_current_user_id(), $form, self::FORM_TTL);
    }

    /** A digit string (or empty) → int; anything else → null (rejected). */
    private static function id_value($value): ?int
    {
        if ($value === '') {
            return 0;
        }

        return is_string($value) && ctype_digit($value) ? absint($value) : null;
    }

    /** Every element a digit string, zeros dropped; anything else → null (rejected). */
    private static function ids_value($value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $ids = [];
        foreach ($value as $item) {
            if (!is_string($item) || !ctype_digit($item)) {
                return null;
            }
            $id = absint($item);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function op_value($value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    /** @param array{done:int, unchanged:int, missing:int, conflicts:list<string>, failed:int, locked:bool} $result */
    private static function report_op(string $op, array $result): void
    {
        if ($result['locked']) {
            self::notice(__('Another redirect change is in progress, try again.', 'sfxtheme'));
            return;
        }

        if ($result['done'] > 0) {
            switch ($op) {
                case 'enable':
                    /* translators: %d: number of rules */
                    $message = _n('%d redirect enabled.', '%d redirects enabled.', $result['done'], 'sfxtheme');
                    break;
                case 'disable':
                    /* translators: %d: number of rules */
                    $message = _n('%d redirect disabled.', '%d redirects disabled.', $result['done'], 'sfxtheme');
                    break;
                case 'delete':
                    /* translators: %d: number of rules */
                    $message = _n('%d redirect deleted.', '%d redirects deleted.', $result['done'], 'sfxtheme');
                    break;
                default:
                    /* translators: %d: number of rules */
                    $message = _n('Hit counter reset for %d redirect.', 'Hit counter reset for %d redirects.', $result['done'], 'sfxtheme');
            }
            self::notice(sprintf($message, $result['done']), 'success');
        }
        if ($result['unchanged'] > 0) {
            self::notice(sprintf(
                /* translators: %d: number of rules */
                _n('%d redirect was already in that state.', '%d redirects were already in that state.', $result['unchanged'], 'sfxtheme'),
                $result['unchanged']
            ), 'info');
        }
        if ($result['missing'] > 0) {
            self::notice(sprintf(
                /* translators: %d: number of rules */
                _n('%d selected rule no longer exists.', '%d selected rules no longer exist.', $result['missing'], 'sfxtheme'),
                $result['missing']
            ), 'warning');
        }
        if ($result['conflicts'] !== []) {
            self::list_notice(__('Not enabled:', 'sfxtheme'), $result['conflicts'], 'warning');
        }
        if ($result['failed'] > 0) {
            self::notice(sprintf(
                /* translators: %d: number of rules */
                _n('%d redirect could not be changed because of a database error.', '%d redirects could not be changed because of a database error.', $result['failed'], 'sfxtheme'),
                $result['failed']
            ));
        }
    }

    private static function record_message(int $record, string $message): string
    {
        /* translators: 1: CSV record number, 2: reason the record was skipped */
        return sprintf(__('Record %1$d: %2$s', 'sfxtheme'), $record, $message);
    }

    private static function upload_error_message(int $code): string
    {
        switch ($code) {
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                return __('The file is larger than the server accepts.', 'sfxtheme');
            case UPLOAD_ERR_PARTIAL:
                return __('The file was only partially uploaded. Try again.', 'sfxtheme');
            case UPLOAD_ERR_NO_FILE:
                return __('Choose a CSV file to import.', 'sfxtheme');
            default:
                /* translators: %d: PHP upload error code */
                return sprintf(__('The server could not store the upload (error %d).', 'sfxtheme'), $code);
        }
    }

    private static function not_ready_message(): string
    {
        return __('The redirect database tables are not installed.', 'sfxtheme');
    }

    /**
     * Viewable post types for the picker, attachments excluded: an attachment of
     * a draft or private post has status "inherit" and would leak through.
     *
     * @return array<string,string> name => label
     */
    private static function picker_post_types(): array
    {
        $types = [];
        foreach (get_post_types(['public' => true], 'objects') as $name => $object) {
            if ($name === 'attachment' || !is_post_type_viewable($object)) {
                continue;
            }
            $types[(string) $name] = (string) ($object->labels->singular_name ?? $name);
        }

        return $types;
    }

    /**
     * A permalink or term link → what the target field stores: home-relative
     * when scheme, host and effective port equal home's and the path lies
     * inside the home path (whole segment, case-sensitive: it is a destination);
     * otherwise the absolute URL unchanged.
     */
    private static function picker_path(string $link, string $home_url): string
    {
        $parts = wp_parse_url($link);
        $home  = wp_parse_url($home_url);
        if (!is_array($parts) || !is_array($home)) {
            return $link;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $port   = static fn(array $p, string $s): int => isset($p['port']) ? (int) $p['port'] : ($s === 'https' ? 443 : 80);
        if ($scheme === '' || $scheme !== strtolower((string) ($home['scheme'] ?? ''))
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($home['host'] ?? ''))
            || $port($parts, $scheme) !== $port($home, $scheme)
        ) {
            return $link;
        }

        $path      = (string) ($parts['path'] ?? '');
        $home_path = rtrim((string) ($home['path'] ?? ''), '/');
        if ($home_path !== '') {
            // Case-sensitive: this becomes a destination the browser requests.
            if (rtrim($path, '/') === $home_path) {
                $path = substr($path, strlen($home_path));
            } elseif (str_starts_with($path, $home_path . '/')) {
                $path = substr($path, strlen($home_path));
            } else {
                return $link;
            }
        }
        if ($path === '') {
            $path = '/';
        }

        return $path
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
    }

    /** Titles and term names may carry markup and entities; the picker wants plain text. */
    private static function plain_text(string $value): string
    {
        return trim(html_entity_decode(wp_strip_all_tags($value), ENT_QUOTES, 'UTF-8'));
    }

    // ------------------------------------------------------------------
    // Screen
    // ------------------------------------------------------------------

    public static function render_page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('You are not allowed to access this page.', 'sfxtheme'), '', ['response' => 403]);
        }

        $tabs = [
            'redirects' => __('Redirects', 'sfxtheme'),
            '404'       => __('404 Log', 'sfxtheme'),
            'import'    => __('Import / Export', 'sfxtheme'),
        ];
        if (\SFX\AccessControl::can_access_theme_settings()) {
            $tabs['settings'] = __('Settings', 'sfxtheme');
        }
        $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if (!isset($tabs[$tab])) {
            $tab = 'redirects';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Redirects', 'sfxtheme'); ?></h1>
            <?php self::render_notices(); ?>

            <nav class="nav-tab-wrapper wp-clearfix">
                <?php foreach ($tabs as $key => $label) : ?>
                    <a href="<?php echo esc_url(self::page_url((string) $key)); ?>" class="nav-tab<?php echo (string) $key === $tab ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php
            if (!Repository::ready()) {
                self::render_not_ready();
            } elseif ($tab === '404') {
                self::render_404_tab();
            } elseif ($tab === 'import') {
                self::render_import_tab();
            } elseif ($tab === 'settings') {
                self::render_settings_tab();
            } else {
                self::render_redirects_tab();
            }
            ?>
        </div>
        <?php
    }

    private static function render_not_ready(): void
    {
        $error = Repository::install_error();
        ?>
        <div class="notice notice-error inline">
            <p><strong><?php echo esc_html(self::not_ready_message()); ?></strong></p>
            <?php if ($error !== '') : ?>
                <p><?php echo esc_html($error); ?></p>
            <?php endif; ?>
            <p><?php esc_html_e('The installation is retried on the next admin page load.', 'sfxtheme'); ?></p>
        </div>
        <?php
    }

    private static function render_redirects_tab(): void
    {
        $form = self::form_values();
        $editing = $form['id'] > 0;
        $statuses = [
            '301' => __('301 Moved Permanently', 'sfxtheme'),
            '302' => __('302 Found (temporary)', 'sfxtheme'),
            '307' => __('307 Temporary Redirect', 'sfxtheme'),
            '308' => __('308 Permanent Redirect', 'sfxtheme'),
            '410' => __('410 Gone', 'sfxtheme'),
        ];
        $cache  = Settings::get()['permanent_cache'];
        $labels = Settings::permanent_cache_labels();
        ?>
        <div class="card sfx-rf" style="max-width: none;">
            <h2 id="sfx-redirect-form">
                <?php
                if ($editing) {
                    /* translators: %d: rule id */
                    echo esc_html(sprintf(__('Edit redirect #%d', 'sfxtheme'), $form['id']));
                } else {
                    esc_html_e('Add redirect', 'sfxtheme');
                }
                ?>
            </h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sfx_redirects_save_rule" />
                <input type="hidden" name="id" value="<?php echo esc_attr((string) $form['id']); ?>" />
                <input type="hidden" name="from_404" value="<?php echo esc_attr((string) $form['from_404']); ?>" />
                <?php wp_nonce_field('sfx_redirects_save_rule'); ?>

                <?php // Three blocks: where the request comes from, where it goes, how it is answered. ?>
                <fieldset class="sfx-rf-section">
                    <legend><?php esc_html_e('From', 'sfxtheme'); ?></legend>
                    <div class="sfx-rf-row">
                        <label class="sfx-rf-label" for="sfx-redirects-source"><?php esc_html_e('Source', 'sfxtheme'); ?></label>
                        <div class="sfx-rf-field">
                            <div class="sfx-rf-inline">
                                <input type="text" class="regular-text code" id="sfx-redirects-source" name="source" value="<?php echo esc_attr($form['source']); ?>" placeholder="/old-page" required />
                                <label for="sfx-redirects-match-type" class="screen-reader-text"><?php esc_html_e('Match type', 'sfxtheme'); ?></label>
                                <select id="sfx-redirects-match-type" name="match_type">
                                    <option value="exact" <?php selected($form['match_type'], 'exact'); ?>><?php esc_html_e('Exact path', 'sfxtheme'); ?></option>
                                    <option value="regex" <?php selected($form['match_type'], 'regex'); ?>><?php esc_html_e('Regular expression', 'sfxtheme'); ?></option>
                                </select>
                            </div>
                            <details class="sfx-rf-more">
                                <summary><?php esc_html_e('The path only, e.g. /old-page.', 'sfxtheme'); ?> <span><?php esc_html_e('Learn more', 'sfxtheme'); ?></span></summary>
                                <p><?php esc_html_e('The path only, e.g. /old-page — case and a trailing slash do not matter. For a regular expression, the pattern without delimiters, e.g. ^/blog/(\d+)/(.*)$ (write ~ as \~).', 'sfxtheme'); ?></p>
                                <p><?php esc_html_e('Feeds, sitemaps, robots.txt and favicon.ico are never redirected, so a rule for a feed URL has no effect.', 'sfxtheme'); ?></p>
                            </details>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="sfx-rf-section">
                    <legend><?php esc_html_e('To', 'sfxtheme'); ?></legend>
                    <div class="sfx-rf-row">
                        <label class="sfx-rf-label" id="sfx-redirects-target-label" for="sfx-redirects-target"><?php esc_html_e('Target', 'sfxtheme'); ?></label>
                        <div class="sfx-rf-field">
                            <div class="sfx-rf-inline">
                                <?php // Shown by the script: without JavaScript the target is a plain text field. ?>
                                <span id="sfx-redirects-picker" hidden>
                                    <label for="sfx-redirects-picker-type" class="screen-reader-text"><?php esc_html_e('Target type', 'sfxtheme'); ?></label>
                                    <select id="sfx-redirects-picker-type">
                                        <option value=""><?php esc_html_e('Custom URL', 'sfxtheme'); ?></option>
                                        <?php foreach (self::picker_post_types() as $name => $label) : ?>
                                            <option value="<?php echo esc_attr($name); ?>"><?php echo esc_html($label); ?></option>
                                        <?php endforeach; ?>
                                        <option value="<?php echo esc_attr(self::PICKER_TERM); ?>"><?php esc_html_e('Term archive', 'sfxtheme'); ?></option>
                                    </select>
                                </span>
                                <input type="text" class="regular-text code" id="sfx-redirects-target" name="target" value="<?php echo esc_attr($form['target']); ?>" placeholder="/new-page/" />
                                <span id="sfx-redirects-picker-search" hidden>
                                    <label for="sfx-redirects-picker-results" class="screen-reader-text"><?php esc_html_e('Entry', 'sfxtheme'); ?></label>
                                    <select id="sfx-redirects-picker-results"></select>
                                </span>
                            </div>
                            <div id="sfx-redirects-picker-filter" class="sfx-rf-inline" hidden>
                                <label for="sfx-redirects-picker-q" class="screen-reader-text"><?php esc_html_e('Search', 'sfxtheme'); ?></label>
                                <input type="search" id="sfx-redirects-picker-q" class="regular-text" maxlength="100" autocomplete="off" placeholder="<?php esc_attr_e('Search…', 'sfxtheme'); ?>" />
                            </div>
                            <details class="sfx-rf-more" id="sfx-redirects-target-help">
                                <summary><?php esc_html_e('A path like /new-page/ or a full URL.', 'sfxtheme'); ?> <span><?php esc_html_e('Learn more', 'sfxtheme'); ?></span></summary>
                                <p><?php esc_html_e('A path on this site starting with / or a full http(s) URL. Regular expressions can use $1 to $9 in the path. Ignored for 410.', 'sfxtheme'); ?></p>
                            </details>
                            <p class="description" id="sfx-redirects-target-address" hidden><?php esc_html_e('Address:', 'sfxtheme'); ?> <code></code></p>
                        </div>
                    </div>
                </fieldset>

                <fieldset class="sfx-rf-section">
                    <legend><?php esc_html_e('Response', 'sfxtheme'); ?></legend>
                    <div class="sfx-rf-row">
                        <label class="sfx-rf-label" for="sfx-redirects-status"><?php esc_html_e('Status code', 'sfxtheme'); ?></label>
                        <div class="sfx-rf-field">
                            <div class="sfx-rf-inline">
                                <select id="sfx-redirects-status" name="status_code">
                                    <?php foreach ($statuses as $code => $label) : ?>
                                        <option value="<?php echo esc_attr((string) $code); ?>" <?php selected($form['status_code'], (string) $code); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label><input type="checkbox" name="enabled" value="1" <?php checked($form['enabled']); ?> /> <?php esc_html_e('Active', 'sfxtheme'); ?></label>
                            </div>
                            <details class="sfx-rf-more">
                                <summary><?php
                                if ($cache > 0) {
                                    /* translators: %s: cache time, e.g. "1 hour" */
                                    echo esc_html(sprintf(__('Browsers may keep a 301 or 308 for up to %s — use 302 while testing.', 'sfxtheme'), $labels[$cache] ?? (string) $cache));
                                } else {
                                    esc_html_e('Browsers may keep a 301 or 308 indefinitely — use 302 while testing.', 'sfxtheme');
                                }
                                ?> <span><?php esc_html_e('Learn more', 'sfxtheme'); ?></span></summary>
                                <p><?php esc_html_e('The cache time applies to responses sent from now on: a browser that already cached a 301 or 308 keeps it for the time it was given (without a header, possibly indefinitely). When this module sends the header, it also tells shared caches and CDNs not to store the redirect.', 'sfxtheme'); ?></p>
                                <p><?php esc_html_e('A page cache or CDN in front of WordPress may keep serving the old page until it is purged.', 'sfxtheme'); ?></p>
                                <p><?php esc_html_e('The cache time is set on the Settings tab.', 'sfxtheme'); ?></p>
                            </details>
                        </div>
                    </div>
                    <div class="sfx-rf-row">
                        <label class="sfx-rf-label" for="sfx-redirects-note"><?php esc_html_e('Note', 'sfxtheme'); ?></label>
                        <div class="sfx-rf-field">
                            <input type="text" class="regular-text" id="sfx-redirects-note" name="note" value="<?php echo esc_attr($form['note']); ?>" maxlength="255" placeholder="<?php esc_attr_e('Optional, only visible here', 'sfxtheme'); ?>" />
                        </div>
                    </div>
                </fieldset>

                <p class="submit">
                    <?php submit_button($editing ? __('Update redirect', 'sfxtheme') : __('Add redirect', 'sfxtheme'), 'primary', 'submit', false); ?>
                    <?php if ($editing || $form['from_404'] > 0) : ?>
                        <a class="button" href="<?php echo esc_url(self::page_url('redirects')); ?>"><?php esc_html_e('Cancel', 'sfxtheme'); ?></a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <?php
        if (!class_exists(RedirectsTable::class)) {
            require_once __DIR__ . '/RedirectsTable.php';
        }
        $table = new RedirectsTable();
        $table->prepare_items();
        self::render_list($table, 'redirects', 'sfx_redirects_bulk', __('Search redirects', 'sfxtheme'));
    }

    private static function render_404_tab(): void
    {
        if (!Settings::get()['log_404']) {
            ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e('404 logging is switched off. Existing entries are kept, but no new 404s are recorded.', 'sfxtheme'); ?>
                <?php if (\SFX\AccessControl::can_access_theme_settings()) : ?>
                    <a href="<?php echo esc_url(self::page_url('settings')); ?>"><?php esc_html_e('Change this in the settings.', 'sfxtheme'); ?></a>
                <?php endif; ?>
                </p>
            </div>
            <?php
        }

        if (!class_exists(NotFoundTable::class)) {
            require_once __DIR__ . '/NotFoundTable.php';
        }
        $table = new NotFoundTable();
        $table->prepare_items();
        self::render_list($table, '404', 'sfx_redirects_404_action', __('Search paths', 'sfxtheme'));
        ?>
        <form class="sfx-redirects-clear-log" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete every entry in the 404 log?', 'sfxtheme')); ?>');">
            <input type="hidden" name="action" value="sfx_redirects_404_action" />
            <input type="hidden" name="op" value="clear_all" />
            <?php wp_nonce_field('sfx_redirects_404_action'); ?>
            <?php submit_button(__('Clear log', 'sfxtheme'), 'delete', 'submit', false); ?>
        </form>
        <?php
    }

    /**
     * Two forms per list, never nested: a GET form for search and pagination
     * (read-only, no nonce), and the POST form carrying the table and exactly
     * one op select + one nonce. Pagination sits in the GET form so its
     * page-number input can never submit the bulk form; the bottom pagination
     * has no input (core prints links only) and sits after both forms.
     *
     * @param RedirectsTable|NotFoundTable $table
     */
    private static function render_list($table, string $tab, string $action, string $search_label): void
    {
        if ($table->load_failed) {
            ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('The list could not be loaded because of a database error.', 'sfxtheme'); ?></p></div>
            <?php
            return;
        }
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('tools.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::$menu_slug); ?>" />
            <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" />
            <?php if ($table->orderby !== '') : ?>
                <input type="hidden" name="orderby" value="<?php echo esc_attr($table->orderby); ?>" />
                <input type="hidden" name="order" value="<?php echo esc_attr($table->order); ?>" />
            <?php endif; ?>
            <?php // search_box() would echo raw $_REQUEST orderby/order hidden fields too; ours are the allowlisted ones. ?>
            <?php self::search_field($search_label, $tab); ?>
            <?php $table->print_pagination(); ?>
        </form>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>" />
            <?php wp_nonce_field($action); ?>
            <?php $table->display(); ?>
        </form>
        <?php // Long lists: page links again below the table, outside both forms. ?>
        <?php $table->print_pagination('bottom'); ?>
        <?php
    }

    private static function search_field(string $label, string $tab): void
    {
        $search = isset($_GET['s']) && is_string($_GET['s']) ? wp_unslash($_GET['s']) : '';
        $id = 'sfx-redirects-search-' . $tab;
        ?>
        <p class="search-box">
            <label class="screen-reader-text" for="<?php echo esc_attr($id); ?>"><?php echo esc_html($label); ?></label>
            <input type="search" id="<?php echo esc_attr($id); ?>" name="s" value="<?php echo esc_attr($search); ?>" />
            <?php submit_button($label, '', '', false); ?>
        </p>
        <?php
    }

    private static function render_import_tab(): void
    {
        ?>
        <div class="card" style="max-width: none;">
            <h2><?php esc_html_e('Import', 'sfxtheme'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                <input type="hidden" name="action" value="sfx_redirects_import" />
                <?php wp_nonce_field('sfx_redirects_import'); ?>
                <p>
                    <label for="sfx-redirects-file"><?php esc_html_e('CSV file', 'sfxtheme'); ?></label><br />
                    <input type="file" id="sfx-redirects-file" name="file" accept=".csv,text/csv" required />
                </p>
                <p class="description"><?php esc_html_e('At most 2 MB and 5000 records per file. Larger files — including an export of more than 5000 redirects — have to be split.', 'sfxtheme'); ?></p>
                <?php submit_button(__('Import CSV', 'sfxtheme'), 'primary', 'submit', false); ?>
            </form>

            <h3><?php esc_html_e('Format', 'sfxtheme'); ?></h3>
            <p><?php esc_html_e('UTF-8, comma-separated, fields in double quotes where needed. The first line names the columns:', 'sfxtheme'); ?></p>
            <pre class="code"><?php echo esc_html("source,target,status_code,match_type,enabled,note\n/old-page,/new-page,301,exact,1,\n^/blog/(.*)$,/news/$1,301,regex,1,Blog moved\n/gone,,410,exact,1,"); ?></pre>
            <ul class="ul-disc">
                <li><?php esc_html_e('"source" is required; "target" is required unless every row is a 410. Unknown columns are ignored.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('Empty cells use the defaults: status code 301, match type exact, enabled 1, empty note. Enabled accepts 1/0, yes/no, true/false.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('A rule whose source already exists is updated; its hit counter is kept. Invalid rows are skipped and listed.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('Cells starting with = + - @ are exported with a leading \' so a spreadsheet does not run them as formulas; the import removes it again. A spreadsheet that re-saves the file may drop these quotes.', 'sfxtheme'); ?></li>
            </ul>

            <h3><?php esc_html_e('Redirection plugin exports', 'sfxtheme'); ?></h3>
            <p><?php esc_html_e('A CSV exported by the Redirection plugin is recognised by its header and imported as far as it means the same here. A file mixing both formats\' column names is rejected.', 'sfxtheme'); ?></p>
            <pre class="code"><?php echo esc_html('source,target,regex,code,type,hits,title,status'); ?></pre>
            <ul class="ul-disc">
                <li><?php esc_html_e('Imported: redirects with 301, 302, 307 or 308 and 410 rules; the title becomes the note, a disabled rule stays disabled. Hits are not imported — new rules start at 0, an updated rule keeps its counters.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('Skipped with a reason: other actions and codes, regular expressions that do not match the whole path (^…$), \\1 or ${1} references, dynamic target tags, and sources outside this site\'s home path.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('Rules here match the canonical path — lowercase, without a trailing slash, percent-encoding normalised. An exact rule without a query string matches any query string and passes it on; an exact rule with a query string matches only that query; a regular expression never sees the query. An imported rule can therefore match more, fewer or differently-cased requests than it did in Redirection, and regex captures are lowercased before they enter the target.', 'sfxtheme'); ?></li>
                <li><?php esc_html_e('Groups, conditions, per-rule query and case settings and logs are not part of the export and are not imported. Exports from Yoast or Rank Math are not supported.', 'sfxtheme'); ?></li>
            </ul>
        </div>

        <div class="card" style="max-width: none;">
            <h2><?php esc_html_e('Export', 'sfxtheme'); ?></h2>
            <p><?php esc_html_e('Downloads every redirect as CSV, including hits, last hit and origin (these extra columns are ignored on import).', 'sfxtheme'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="sfx_redirects_export" />
                <?php wp_nonce_field('sfx_redirects_export'); ?>
                <?php submit_button(__('Download CSV', 'sfxtheme'), 'secondary', 'submit', false); ?>
            </form>
        </div>
        <?php
    }

    private static function render_settings_tab(): void
    {
        // The tab is only listed for the settings gate; this re-check covers a typed ?tab=settings.
        if (!\SFX\AccessControl::can_access_theme_settings()) {
            return;
        }
        $settings = Settings::get();
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="sfx_redirects_save_settings" />
            <?php wp_nonce_field('sfx_redirects_save_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('404 log', 'sfxtheme'); ?></th>
                    <td>
                        <label><input type="checkbox" name="log_404" value="1" <?php checked($settings['log_404']); ?> /> <?php esc_html_e('Record 404 errors', 'sfxtheme'); ?></label>
                        <p class="description"><?php esc_html_e('One entry per path with hit count and last visit. No IP addresses, user agents or query strings are stored.', 'sfxtheme'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Referrer', 'sfxtheme'); ?></th>
                    <td>
                        <label><input type="checkbox" name="log_referrer" value="1" <?php checked($settings['log_referrer']); ?> /> <?php esc_html_e('Store where the visitor came from (without its query string)', 'sfxtheme'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sfx-redirects-retention"><?php esc_html_e('Log retention (days)', 'sfxtheme'); ?></label></th>
                    <td>
                        <input type="number" id="sfx-redirects-retention" name="log_retention_days" min="1" max="365" value="<?php echo esc_attr((string) $settings['log_retention_days']); ?>" />
                        <p class="description"><?php esc_html_e('Entries not seen for longer are deleted (1–365).', 'sfxtheme'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sfx-redirects-max-rows"><?php esc_html_e('Maximum log entries', 'sfxtheme'); ?></label></th>
                    <td>
                        <input type="number" id="sfx-redirects-max-rows" name="log_max_rows" min="100" max="50000" value="<?php echo esc_attr((string) $settings['log_max_rows']); ?>" />
                        <p class="description"><?php esc_html_e('The oldest entries are deleted beyond this number (100–50000).', 'sfxtheme'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Automatic redirects', 'sfxtheme'); ?></th>
                    <td>
                        <label><input type="checkbox" name="auto_slug_redirect" value="1" <?php checked($settings['auto_slug_redirect']); ?> /> <?php esc_html_e('Create a redirect when the address of a published post or page changes', 'sfxtheme'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="sfx-redirects-permanent-cache"><?php esc_html_e('Browser cache for 301 and 308', 'sfxtheme'); ?></label></th>
                    <td>
                        <select id="sfx-redirects-permanent-cache" name="permanent_cache">
                            <?php foreach (Settings::permanent_cache_labels() as $seconds => $label) : ?>
                                <option value="<?php echo esc_attr((string) $seconds); ?>" <?php selected($settings['permanent_cache'], $seconds); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('How long a visitor\'s browser may keep a permanent redirect. Without a cache header, browsers may keep a 301 or 308 indefinitely, so a mistaken one sticks. Not sent to logged-in users.', 'sfxtheme'); ?></p>
                        <p class="description"><?php esc_html_e('The time applies to responses sent from now on: a browser that already cached a 301 or 308 keeps it for the time it was given (without a header, possibly indefinitely). When this module sends the header, it also tells shared caches and CDNs not to store the redirect; a page cache in front of WordPress may still apply its own rules.', 'sfxtheme'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Where the form's values come from, in order: a rejected save (read once),
     * ?edit=<id>, a "Create redirect" link from the 404 log, else empty.
     *
     * @return array{id:int, from_404:int, source:string, match_type:string, target:string, status_code:string, enabled:bool, note:string}
     */
    private static function form_values(): array
    {
        $form = [
            'id'          => 0,
            'from_404'    => 0,
            'source'      => '',
            'match_type'  => 'exact',
            'target'      => '',
            'status_code' => '301',
            'enabled'     => true,
            'note'        => '',
        ];

        $key    = 'sfx_redirects_form_' . get_current_user_id();
        $stored = get_transient($key);
        if (is_array($stored)) {
            delete_transient($key);
            foreach ($form as $field => $default) {
                if (!isset($stored[$field])) {
                    continue;
                }
                if (is_int($default)) {
                    $form[$field] = absint($stored[$field]);
                } elseif (is_bool($default)) {
                    $form[$field] = (bool) $stored[$field];
                } elseif (is_string($stored[$field])) {
                    $form[$field] = $stored[$field];
                }
            }
            return $form;
        }

        $get = wp_unslash($_GET);

        if (isset($get['edit']) && is_string($get['edit']) && ctype_digit($get['edit']) && absint($get['edit']) > 0) {
            $id     = absint($get['edit']);
            $result = Repository::get($id);
            if ($result['status'] === 'found') {
                $row = $result['row'];
                return [
                    'id'          => $id,
                    'from_404'    => 0,
                    'source'      => $row['source'],
                    'match_type'  => $row['match_type'],
                    'target'      => $row['target'],
                    'status_code' => (string) $row['status_code'],
                    'enabled'     => $row['enabled'],
                    'note'        => $row['note'],
                ];
            }
            $message = $result['status'] === 'missing'
                /* translators: %d: rule id */
                ? sprintf(__('Rule #%d no longer exists.', 'sfxtheme'), $id)
                : __('A database error occurred; the rule could not be loaded.', 'sfxtheme');
            ?>
            <div class="notice notice-error inline"><p><?php echo esc_html($message); ?></p></div>
            <?php
            return $form;
        }

        if (isset($get['from_404']) && is_string($get['from_404']) && ctype_digit($get['from_404'])) {
            $form['from_404'] = absint($get['from_404']);
            if (isset($get['source']) && is_string($get['source'])) {
                $form['source'] = $get['source'];
            }
        }

        return $form;
    }
}
