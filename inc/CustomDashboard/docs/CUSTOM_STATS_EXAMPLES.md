# Custom Dashboard Statistics - Developer Guide

Add custom statistics to your WordPress dashboard using the `sfx_custom_dashboard_stats` filter.

## Basic Usage

Register custom statistics in your theme's `functions.php` or a custom plugin:

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['my_stat_id'] = [
        'label' => __('My Custom Stat', 'textdomain'),
        'query_type' => 'callback',
        'callback' => function() {
            return 42; // Your count logic here
        },
        'url' => admin_url('admin.php?page=my-page'),
        'icon' => '<svg>...</svg>', // Optional SVG icon
    ];
    return $stats;
});
```

## Configuration Options

### Required Fields

| Field | Type | Description |
|-------|------|-------------|
| `label` | string | Display name for the stat card |
| `query_type` | string | Type of data query (see Query Types below) |

### Optional Fields

| Field | Type | Description |
|-------|------|-------------|
| `icon` | string | SVG icon HTML (sanitized via `wp_kses()`) |
| `url` | string | Admin URL to link to when clicking the stat card |

### Query Types

| Type | Description | Additional Config |
|------|-------------|-------------------|
| `callback` | Custom PHP function | `callback` (callable) |
| `wp_count_posts` | WordPress post count | `post_type`, `status` |
| `wp_count_comments` | WordPress comment count | `status` |
| `wp_query` | WP_Query for complex queries | `query_args` (array) |
| `user_query` | WP_User_Query for users | `query_args` (array) |
| `database` | Direct database query | `sql_callback` (callable) |
| `woocommerce` | WooCommerce queries | `wc_query_type`, `query_args` |
| `external_api` | External API calls | `api_callback` (callable) |

---

## Examples

### Example 1: WordPress Comments (Approved)

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['approved_comments'] = [
        'label' => __('Approved Comments', 'sfxtheme'),
        'query_type' => 'wp_count_comments',
        'status' => 'approved',
        'url' => admin_url('edit-comments.php?comment_status=approved'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>',
    ];
    return $stats;
});
```

### Example 2: Pending Posts

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['pending_posts'] = [
        'label' => __('Pending Posts', 'sfxtheme'),
        'query_type' => 'wp_count_posts',
        'post_type' => 'post',
        'status' => 'pending',
        'url' => admin_url('edit.php?post_status=pending'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    ];
    return $stats;
});
```

### Example 3: Draft Pages

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['draft_pages'] = [
        'label' => __('Draft Pages', 'sfxtheme'),
        'query_type' => 'wp_count_posts',
        'post_type' => 'page',
        'status' => 'draft',
        'url' => admin_url('edit.php?post_type=page&post_status=draft'),
    ];
    return $stats;
});
```

### Example 4: WooCommerce Products

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    // Only add if WooCommerce is active
    if (!function_exists('wc_get_products')) {
        return $stats;
    }

    $stats['wc_products'] = [
        'label' => __('Products', 'woocommerce'),
        'query_type' => 'woocommerce',
        'wc_query_type' => 'products',
        'query_args' => [
            'status' => 'publish',
        ],
        'url' => admin_url('edit.php?post_type=product'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" /></svg>',
    ];
    return $stats;
});
```

### Example 5: WooCommerce Completed Orders

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    if (!class_exists('WC_Order_Query')) {
        return $stats;
    }

    $stats['wc_orders_completed'] = [
        'label' => __('Completed Orders', 'woocommerce'),
        'query_type' => 'woocommerce',
        'wc_query_type' => 'orders',
        'query_args' => [
            'status' => ['completed'],
        ],
        'url' => admin_url('edit.php?post_type=shop_order&post_status=wc-completed'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>',
    ];
    return $stats;
});
```

### Example 6: WooCommerce Customers

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    if (!function_exists('wc_get_products')) {
        return $stats;
    }

    $stats['wc_customers'] = [
        'label' => __('Customers', 'woocommerce'),
        'query_type' => 'woocommerce',
        'wc_query_type' => 'customers',
        'url' => admin_url('users.php?role=customer'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>',
    ];
    return $stats;
});
```

### Example 7: Custom Database Table

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['form_submissions'] = [
        'label' => __('Form Submissions (30 days)', 'sfxtheme'),
        'query_type' => 'database',
        'sql_callback' => function() {
            global $wpdb;
            $table = $wpdb->prefix . 'form_submissions';
            
            // Check if table exists
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                return 0;
            }
            
            return (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
                    gmdate('Y-m-d', strtotime('-30 days'))
                )
            );
        },
        'url' => admin_url('admin.php?page=form-submissions'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>',
    ];
    return $stats;
});
```

### Example 8: User Meta Query (Premium Members)

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['premium_users'] = [
        'label' => __('Premium Members', 'sfxtheme'),
        'query_type' => 'user_query',
        'query_args' => [
            'meta_key' => 'subscription_type',
            'meta_value' => 'premium',
        ],
        'url' => admin_url('users.php?subscription=premium'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z" /></svg>',
    ];
    return $stats;
});
```

### Example 9: WP_Query with Post Meta

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['featured_posts'] = [
        'label' => __('Featured Posts', 'sfxtheme'),
        'query_type' => 'wp_query',
        'query_args' => [
            'post_type' => 'post',
            'post_status' => 'publish',
            'meta_query' => [
                [
                    'key' => '_is_featured',
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ],
        'url' => admin_url('edit.php?featured=1'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 00-2.456 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z" /></svg>',
    ];
    return $stats;
});
```

### Example 10: External API (Newsletter Subscribers)

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['newsletter_subscribers'] = [
        'label' => __('Newsletter Subscribers', 'sfxtheme'),
        'query_type' => 'external_api',
        'api_callback' => function() {
            // Use longer caching for external APIs
            $transient_key = 'sfx_newsletter_count';
            $cached = get_transient($transient_key);
            
            if ($cached !== false) {
                return (int) $cached;
            }
            
            $api_key = get_option('mailchimp_api_key', '');
            $list_id = get_option('mailchimp_list_id', '');
            
            if (empty($api_key) || empty($list_id)) {
                return 0;
            }
            
            $response = wp_remote_get(
                "https://us1.api.mailchimp.com/3.0/lists/{$list_id}",
                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $api_key,
                    ],
                    'timeout' => 10,
                ]
            );
            
            if (is_wp_error($response)) {
                return 0;
            }
            
            $data = json_decode(wp_remote_retrieve_body($response), true);
            $count = $data['stats']['member_count'] ?? 0;
            
            // Cache for 1 hour
            set_transient($transient_key, $count, HOUR_IN_SECONDS);
            
            return (int) $count;
        },
        'url' => 'https://mailchimp.com/dashboard',
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>',
    ];
    return $stats;
});
```

### Example 11: Simple Callback

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['active_plugins'] = [
        'label' => __('Active Plugins', 'sfxtheme'),
        'query_type' => 'callback',
        'callback' => function() {
            $active_plugins = get_option('active_plugins', []);
            return count($active_plugins);
        },
        'url' => admin_url('plugins.php?plugin_status=active'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.96.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z" /></svg>',
    ];
    return $stats;
});
```

### Example 12: Users by Role

```php
add_filter('sfx_custom_dashboard_stats', function($stats) {
    $stats['editors'] = [
        'label' => __('Editors', 'sfxtheme'),
        'query_type' => 'user_query',
        'query_args' => [
            'role' => 'editor',
        ],
        'url' => admin_url('users.php?role=editor'),
        'icon' => '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>',
    ];
    return $stats;
});
```

---

## Security Notes

1. **Callbacks are executed in WordPress context** with proper user capabilities
2. **Database queries via `sql_callback`** should always use `$wpdb->prepare()` for parameters
3. **External API calls** should implement their own caching (recommended: 1 hour minimum)
4. **Icons are sanitized** using `wp_kses()` with allowed SVG tags
5. **All outputs are escaped** in the renderer via `esc_html()`, `esc_url()`, etc.

## Caching

- Custom stats are cached for **5 minutes** using WordPress transients
- Cache is **automatically cleared** on content changes (post publish/update/delete, user add/delete)
- External APIs should implement their own **longer caching** (1 hour recommended) inside the callback

## Enabling Custom Stats

After registering custom stats via the filter:

1. Go to **Dashboard Settings** → **Stats** tab
2. Your custom stats will appear in the list with a "Custom" badge
3. **Enable** the checkbox next to each stat you want to display
4. **Drag to reorder** stats as needed
5. Click **Save Changes**

## Troubleshooting

### Stat not appearing in admin

- Ensure the filter is hooked before `admin_init`
- Check that `label` is set (required field)
- Verify the stat ID is unique

### Count shows 0

- Check callback is returning an integer
- For database queries, verify the table exists
- For WooCommerce, ensure WooCommerce is active
- Check error logs for exceptions

### Cache not updating

- Cache expires after 5 minutes automatically
- Content changes (posts, users) trigger cache clear
- For external APIs, check your custom transient expiry

## Filter Reference

```php
/**
 * Filter to register custom dashboard statistics
 *
 * @param array $stats Array of custom stat configurations
 * @return array Modified stats array
 */
apply_filters('sfx_custom_dashboard_stats', []);
```
