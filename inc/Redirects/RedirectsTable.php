<?php

declare(strict_types=1);

namespace SFX\Redirects;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The rules list. Rendered by AdminPage inside its own POST form to
 * admin-post.php (action sfx_redirects_bulk); see display_tablenav() for why
 * core's tablenav is replaced.
 */
final class RedirectsTable extends \WP_List_Table
{
    private const PER_PAGE = 20;
    private const SORTABLE = ['source', 'hits', 'last_hit', 'created_at'];

    /** True when the repository could not read the page; AdminPage shows an error instead. */
    public bool $load_failed = false;
    public string $orderby = '';
    public string $order = '';

    public function __construct()
    {
        parent::__construct([
            'singular' => 'sfx_redirect',
            'plural'   => 'sfx_redirects',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'cb'          => '<input type="checkbox" />',
            'source'      => __('Source', 'sfxtheme'),
            'target'      => __('Target', 'sfxtheme'),
            'status_code' => __('Code', 'sfxtheme'),
            'hits'        => __('Hits', 'sfxtheme'),
            'last_hit'    => __('Last hit', 'sfxtheme'),
            'origin'      => __('Origin', 'sfxtheme'),
            'enabled'     => __('Enabled', 'sfxtheme'),
            'created_at'  => __('Created', 'sfxtheme'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'source'   => ['source', false],
            'hits'     => ['hits', true],
            'last_hit'   => ['last_hit', true],
            'created_at' => ['created_at', true],
        ];
    }

    public function prepare_items(): void
    {
        // Explicit, not via the screen: core caches column headers per screen id.
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'source'];

        $get = wp_unslash($_GET);
        $search = isset($get['s']) && is_string($get['s']) ? trim($get['s']) : '';
        if (isset($get['orderby']) && is_string($get['orderby']) && in_array($get['orderby'], self::SORTABLE, true)) {
            $this->orderby = $get['orderby'];
            $this->order = isset($get['order']) && is_string($get['order']) && strtolower($get['order']) === 'asc' ? 'asc' : 'desc';
        }

        // Default: newest first. Repository maps orderby through its own allowlist again.
        $result = Repository::list_rules(
            $search,
            $this->orderby !== '' ? $this->orderby : 'created_at',
            $this->order !== '' ? $this->order : 'desc',
            $this->get_pagenum(),
            self::PER_PAGE
        );
        if ($result === null) {
            $this->load_failed = true;
            $this->items = [];
            return;
        }

        // A search submitted from page 2 keeps paged=2 in the GET form; an
        // out-of-range page would show an empty table beside a "1 item" count.
        $pages = (int) ceil($result['total'] / self::PER_PAGE);
        if ($result['items'] === [] && $pages > 0 && $this->get_pagenum() > $pages) {
            $_REQUEST['paged'] = $pages;
            $_GET['paged']     = $pages;
            $result = Repository::list_rules(
                $search,
                $this->orderby !== '' ? $this->orderby : 'created_at',
                $this->order !== '' ? $this->order : 'desc',
                $this->get_pagenum(),
                self::PER_PAGE
            );
            if ($result === null) {
                $this->load_failed = true;
                $this->items = [];
                return;
            }
        }

        $this->items = $result['items'];
        $this->set_pagination_args([
            'total_items' => $result['total'],
            'per_page'    => self::PER_PAGE,
        ]);
    }

    /**
     * Replaces core's tablenav: core would print its own bulk-<plural> nonce and
     * a second bulk select, and its pagination input would submit this POST
     * form. Here: one op select + button on top, nothing at the bottom;
     * pagination is printed by AdminPage in the separate GET form.
     */
    protected function display_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        $ops = [
            'enable'  => __('Enable', 'sfxtheme'),
            'disable' => __('Disable', 'sfxtheme'),
            'reset'   => __('Reset hits', 'sfxtheme'),
            'delete'  => __('Delete', 'sfxtheme'),
        ];
        ?>
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="sfx-redirects-op" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'sfxtheme'); ?></label>
                <select name="op" id="sfx-redirects-op">
                    <option value=""><?php esc_html_e('Bulk actions', 'sfxtheme'); ?></option>
                    <?php foreach ($ops as $op => $label) : ?>
                        <option value="<?php echo esc_attr($op); ?>"><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php submit_button(__('Apply', 'sfxtheme'), 'action', '', false); ?>
            </div>
            <br class="clear" />
        </div>
        <?php
    }

    /** Public entry for AdminPage's GET form (core's pagination() is protected). */
    public function print_pagination(): void
    {
        echo '<div class="tablenav top">';
        $this->pagination('top');
        echo '<br class="clear" /></div>';
    }

    public function no_items(): void
    {
        esc_html_e('No redirects yet.', 'sfxtheme');
    }

    protected function column_cb($item): string
    {
        return sprintf(
            '<label class="screen-reader-text" for="sfx-redirect-%1$d">%2$s</label><input type="checkbox" id="sfx-redirect-%1$d" name="ids[]" value="%1$d" />',
            (int) $item['id'],
            esc_html__('Select redirect', 'sfxtheme')
        );
    }

    protected function column_source(array $item): string
    {
        $id   = (int) $item['id'];
        $edit = add_query_arg('edit', (string) $id, AdminPage::page_url('redirects')) . '#sfx-redirect-form';

        $out = sprintf('<strong><a href="%s"><code>%s</code></a></strong>', esc_url($edit), esc_html($item['source']));
        if ($item['match_type'] === 'regex') {
            $out .= ' <span class="description">' . esc_html__('(regular expression)', 'sfxtheme') . '</span>';
        }
        if ($item['note'] !== '') {
            $out .= '<br /><span class="description">' . esc_html($item['note']) . '</span>';
        }

        $actions = [
            'edit' => sprintf('<a href="%s">%s</a>', esc_url($edit), esc_html__('Edit', 'sfxtheme')),
        ];
        $actions[$item['enabled'] ? 'disable' : 'enable'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::action_url($item['enabled'] ? 'disable' : 'enable', $id)),
            $item['enabled'] ? esc_html__('Disable', 'sfxtheme') : esc_html__('Enable', 'sfxtheme')
        );
        $actions['reset'] = sprintf('<a href="%s">%s</a>', esc_url(self::action_url('reset', $id)), esc_html__('Reset hits', 'sfxtheme'));
        $actions['delete'] = sprintf(
            '<a href="%s" class="submitdelete" onclick="return confirm(\'%s\');">%s</a>',
            esc_url(self::action_url('delete', $id)),
            esc_js(__('Delete this redirect?', 'sfxtheme')),
            esc_html__('Delete', 'sfxtheme')
        );

        return $out . $this->row_actions($actions);
    }

    protected function column_target(array $item): string
    {
        if ((int) $item['status_code'] === 410 || $item['target'] === '') {
            return '&mdash;';
        }

        return '<code>' . esc_html($item['target']) . '</code>';
    }

    protected function column_status_code(array $item): string
    {
        return esc_html((string) $item['status_code']);
    }

    protected function column_hits(array $item): string
    {
        return esc_html(number_format_i18n((int) $item['hits']));
    }

    protected function column_last_hit(array $item): string
    {
        if ($item['last_hit'] === null || $item['last_hit'] === '') {
            return '&mdash;';
        }

        return esc_html(get_date_from_gmt($item['last_hit'], get_option('date_format') . ' ' . get_option('time_format')));
    }

    protected function column_created_at(array $item): string
    {
        return esc_html(get_date_from_gmt((string) $item['created_at'], get_option('date_format') . ' ' . get_option('time_format')));
    }

    protected function column_origin(array $item): string
    {
        $labels = [
            'manual' => __('Manual', 'sfxtheme'),
            'auto'   => __('Automatic (slug change)', 'sfxtheme'),
            'import' => __('Import', 'sfxtheme'),
            '404'    => __('404 log', 'sfxtheme'),
        ];

        return esc_html($labels[$item['origin']] ?? $item['origin']);
    }

    protected function column_enabled(array $item): string
    {
        return $item['enabled'] ? esc_html__('Yes', 'sfxtheme') : esc_html__('No', 'sfxtheme');
    }

    /** Single-row actions: nonce'd GET links to admin-post.php, never to the page itself. */
    private static function action_url(string $op, int $id): string
    {
        return wp_nonce_url(
            add_query_arg(['action' => 'sfx_redirects_rule_action', 'op' => $op, 'id' => (string) $id], admin_url('admin-post.php')),
            'sfx_redirects_rule_action'
        );
    }
}
