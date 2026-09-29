<?php

declare(strict_types=1);

namespace SFX\Redirects;

if (!class_exists('\WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * The 404 log. Rendered by AdminPage inside its own POST form to
 * admin-post.php (action sfx_redirects_404_action); tablenav replaced for the
 * same reason as in RedirectsTable.
 */
final class NotFoundTable extends \WP_List_Table
{
    private const PER_PAGE = 20;
    private const SORTABLE = ['path', 'hits', 'last_seen'];

    /** True when the repository could not read the page; AdminPage shows an error instead. */
    public bool $load_failed = false;
    public string $orderby = '';
    public string $order = '';

    public function __construct()
    {
        parent::__construct([
            'singular' => 'sfx_redirects_404',
            'plural'   => 'sfx_redirects_404s',
            'ajax'     => false,
        ]);
    }

    public function get_columns(): array
    {
        return [
            'cb'         => '<input type="checkbox" />',
            'path'       => __('Path', 'sfxtheme'),
            'hits'       => __('Hits', 'sfxtheme'),
            'last_seen'  => __('Last seen', 'sfxtheme'),
            'first_seen' => __('First seen', 'sfxtheme'),
            'referrer'   => __('Last referrer', 'sfxtheme'),
        ];
    }

    protected function get_sortable_columns(): array
    {
        return [
            'path'      => ['path', false],
            'hits'      => ['hits', true],
            'last_seen' => ['last_seen', true],
        ];
    }

    public function prepare_items(): void
    {
        // Explicit, not via the screen: core caches column headers per screen id.
        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns(), 'path'];

        $get = wp_unslash($_GET);
        $search = isset($get['s']) && is_string($get['s']) ? trim($get['s']) : '';
        if (isset($get['orderby']) && is_string($get['orderby']) && in_array($get['orderby'], self::SORTABLE, true)) {
            $this->orderby = $get['orderby'];
            $this->order = isset($get['order']) && is_string($get['order']) && strtolower($get['order']) === 'asc' ? 'asc' : 'desc';
        }

        // Default: most recently seen first. Repository maps orderby through its own allowlist again.
        $result = Repository::list_404(
            $search,
            $this->orderby !== '' ? $this->orderby : 'last_seen',
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
            $result = Repository::list_404(
                $search,
                $this->orderby !== '' ? $this->orderby : 'last_seen',
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

    /** One op select + button on top, nothing at the bottom — see RedirectsTable::display_tablenav(). */
    protected function display_tablenav($which): void
    {
        if ($which !== 'top') {
            return;
        }
        ?>
        <div class="tablenav top">
            <div class="alignleft actions bulkactions">
                <label for="sfx-redirects-404-op" class="screen-reader-text"><?php esc_html_e('Select bulk action', 'sfxtheme'); ?></label>
                <select name="op" id="sfx-redirects-404-op">
                    <option value=""><?php esc_html_e('Bulk actions', 'sfxtheme'); ?></option>
                    <option value="delete"><?php esc_html_e('Delete', 'sfxtheme'); ?></option>
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
        esc_html_e('No 404s recorded.', 'sfxtheme');
    }

    protected function column_cb($item): string
    {
        return sprintf(
            '<label class="screen-reader-text" for="sfx-redirects-404-%1$d">%2$s</label><input type="checkbox" id="sfx-redirects-404-%1$d" name="ids[]" value="%1$d" />',
            (int) $item['id'],
            esc_html__('Select log entry', 'sfxtheme')
        );
    }

    protected function column_path(array $item): string
    {
        $id = (int) $item['id'];

        // add_query_arg() does not encode new values; the canonical path holds '%' escapes.
        $create = add_query_arg(
            ['from_404' => (string) $id, 'source' => rawurlencode($item['path'])],
            AdminPage::page_url('redirects')
        ) . '#sfx-redirect-form';
        $delete = wp_nonce_url(
            add_query_arg(['action' => 'sfx_redirects_404_action', 'op' => 'delete', 'id' => (string) $id], admin_url('admin-post.php')),
            'sfx_redirects_404_action'
        );

        $actions = [
            'create' => sprintf('<a href="%s">%s</a>', esc_url($create), esc_html__('Create redirect', 'sfxtheme')),
            'delete' => sprintf('<a href="%s" class="submitdelete">%s</a>', esc_url($delete), esc_html__('Delete', 'sfxtheme')),
        ];

        return '<strong><code>' . esc_html($item['path']) . '</code></strong>' . $this->row_actions($actions);
    }

    protected function column_hits(array $item): string
    {
        return esc_html(number_format_i18n((int) $item['hits']));
    }

    protected function column_last_seen(array $item): string
    {
        return esc_html(self::local_date($item['last_seen']));
    }

    protected function column_first_seen(array $item): string
    {
        return esc_html(self::local_date($item['first_seen']));
    }

    /** Display only — never a link: the referrer is visitor-supplied. */
    protected function column_referrer(array $item): string
    {
        return $item['referrer'] === '' ? '&mdash;' : esc_html($item['referrer']);
    }

    private static function local_date(string $utc): string
    {
        return get_date_from_gmt($utc, get_option('date_format') . ' ' . get_option('time_format'));
    }
}
