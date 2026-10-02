<?php

declare(strict_types=1);

namespace SFX\WPOptimizer\classes;

/**
 * Guest REST policy (spec docs/superpowers/specs/2026-10-02-rest-guest-access-design.md).
 * Every read of the four rest_guest_* keys goes through the normalisers below: Import/Export
 * can write the option while the module is off, so stored values may be anything.
 */
final class RestGuestAccess
{
    public const OPTION = 'sfx_wpoptimizer_options';
    public const MODES = ['open', 'allowlist', 'closed'];
    public const INTERNAL_NAMESPACE = 'sfx-guest/v1';
    public const DEFAULTS = [
        'bricks/v1'         => 'all',
        'oembed/1.0'        => 'get',
        'contact-form-7/v1' => 'all',
        'fluentform/v1'     => 'all',
        'burst/v1'          => 'all',
    ];
    public const ADMIN_NAMESPACES = [
        'wp-site-health/v1', 'wp-block-editor/v1', 'wp-abilities/v1',
        'fluent-smtp', 'fluent-snippets', 'core-framework/v2',
    ];

    public static function option(): array
    {
        $o = get_option(self::OPTION, []);
        return is_array($o) ? $o : [];
    }

    /** Characters that survive sanitize_text_field() and wp_kses unchanged, so an import cannot alter an identity. */
    public static function supported($ns): bool
    {
        if (!is_string($ns) || !preg_match('#\A[A-Za-z0-9._~-]+(/[A-Za-z0-9._~-]+)*\z#', $ns)) {
            return false;
        }
        foreach (explode('/', $ns) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    public static function is_form_save(): bool
    {
        return isset($_POST['option_page']) && $_POST['option_page'] === self::OPTION; // phpcs:ignore WordPress.Security.NonceVerification -- options.php verified the nonce
    }

    /** The raw posted form, read the same way on every sanitizer invocation (add_option can run it twice). */
    public static function raw_form(): array
    {
        $raw = isset($_POST[self::OPTION]) ? wp_unslash($_POST[self::OPTION]) : []; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- sanitized below
        return is_array($raw) ? $raw : [];
    }

    public static function form_complete(array $raw): bool
    {
        return ($raw['sfx_wpo_form_start'] ?? null) === '1' && ($raw['sfx_wpo_form_end'] ?? null) === '1';
    }

    /** Writes the four rest_guest_* keys into $output from $source. */
    public static function sanitize_into(array $source, array $output, bool $form, array $stored): array
    {
        $output['rest_guest_mode'] = self::mode($source);
        $rows = self::namespaces($source, $form);
        $output['rest_guest_namespaces'] = ($rows === null && $form) ? [] : $rows;
        $output['rest_guest_hide_index'] = self::hide_index($source) ? 1 : 0;
        if ($form) {
            $displayed = self::seen(['rest_guest_seen' => $source['rest_guest_displayed'] ?? []]);
            $output['rest_guest_seen'] = array_values(array_unique(array_merge(self::seen($stored), $displayed)));
        } else {
            $output['rest_guest_seen'] = self::seen($source);
        }
        return $output;
    }

    /** One allowlist row as form inputs. The hidden 0 must precede the checkbox so an unticked row still posts allowed. */
    public static function row_inputs(int $i, string $ns, bool $allowed, string $method): string
    {
        $base = esc_attr(self::OPTION . '[rest_guest_namespaces][' . $i . ']');
        $label = esc_attr(sprintf(__('Allow %s for guests', 'sfxtheme'), $ns));
        $html = '<input type="hidden" name="' . $base . '[namespace]" value="' . esc_attr($ns) . '">';
        $html .= '<input type="hidden" name="' . $base . '[allowed]" value="0">';
        $html .= '<input type="checkbox" name="' . $base . '[allowed]" value="1"' . ($allowed ? ' checked' : '') . ' aria-label="' . $label . '">';
        $html .= '<select name="' . $base . '[method]">';
        $html .= '<option value="all"' . ($method === 'all' ? ' selected' : '') . '>' . esc_html__('All methods', 'sfxtheme') . '</option>';
        $html .= '<option value="get"' . ($method === 'get' ? ' selected' : '') . '>' . esc_html__('GET only', 'sfxtheme') . '</option>';
        return $html . '</select>';
    }

    public static function mode(array $o): string
    {
        $mode = $o['rest_guest_mode'] ?? null;
        if (is_string($mode) && in_array($mode, self::MODES, true)) {
            return $mode;
        }
        // Read-time migration of the old all-or-nothing switch.
        if (!array_key_exists('rest_guest_mode', $o) && !empty($o['disable_rest_api_non_authenticated'])) {
            return 'closed';
        }
        return 'open';
    }

    /** @return list<array{namespace:string, method:string}>|null  null = never saved */
    public static function namespaces(array $o, bool $from_form = false): ?array
    {
        if (!array_key_exists('rest_guest_namespaces', $o) || $o['rest_guest_namespaces'] === null) {
            return null;
        }
        $rows = $o['rest_guest_namespaces'];
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !self::supported($row['namespace'] ?? null) || $row['namespace'] === self::INTERNAL_NAMESPACE) {
                continue;
            }
            // A form row is a grant only with an explicit allowed=1 (a cut-off row has none).
            if ($from_form && ($row['allowed'] ?? null) !== '1') {
                continue;
            }
            $method = ($row['method'] ?? null) === 'get' ? 'get' : 'all';
            $out[$row['namespace']] = ['namespace' => $row['namespace'], 'method' => $method];
        }
        return array_values($out);
    }

    /** @return list<string> */
    public static function seen(array $o): array
    {
        $list = $o['rest_guest_seen'] ?? [];
        if (!is_array($list)) {
            return [];
        }
        return array_values(array_unique(array_filter($list, static fn($v) => is_string($v) && $v !== '' && $v !== self::INTERNAL_NAMESPACE)));
    }

    public static function hide_index(array $o): bool
    {
        $v = $o['rest_guest_hide_index'] ?? null;
        if ($v === null) {
            return true;
        }
        return !in_array($v, [0, '0', false], true);
    }

    /** @param list<array{namespace:string, method:string}> $rows @return array<string,string> */
    public static function to_map(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $map[$row['namespace']] = $row['method'];
        }
        return $map;
    }

    public static function enforcing(array $o): bool
    {
        return empty($o['disable_wp_optimizer']) && self::mode($o) !== 'open';
    }

    /** @return list<string> */
    public static function live_namespaces(): array
    {
        $out = [];
        foreach (rest_get_server()->get_namespaces() as $ns) {
            $ns = (string) $ns; // core keys namespaces in an array: "123" comes back as int
            if ($ns !== self::INTERNAL_NAMESPACE) {
                $out[] = $ns;
            }
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /** @param list<string> $live @return array<string,string> */
    public static function effective_map(array $o, array $live): array
    {
        $rows = self::namespaces($o);
        if ($rows !== null) {
            return self::to_map($rows);
        }
        return array_intersect_key(self::DEFAULTS, array_flip($live));
    }

    /** @return array{kind:string, namespace:?string} */
    public static function classify($request, string $route, array $handler, ?array $route_options): array
    {
        $cb = $handler['callback'] ?? null;
        if (is_array($cb) && ($cb[0] ?? null) instanceof \WP_REST_Server) {
            if ($cb[1] === 'get_index') {
                return ['kind' => 'index', 'namespace' => null];
            }
            if ($cb[1] === 'get_namespace_index') {
                $param = $request['namespace'];
                if ($param !== null && $param !== '') {
                    // A request override must be a string (core reads it as the namespace to list).
                    return is_string($param)
                        ? ['kind' => 'discovery', 'namespace' => $param]
                        : ['kind' => 'unknown', 'namespace' => null];
                }
                $own = $route_options['namespace'] ?? '';
                return (is_string($own) || is_int($own)) && (string) $own !== ''
                    ? ['kind' => 'discovery', 'namespace' => (string) $own]
                    : ['kind' => 'unknown', 'namespace' => null];
            }
        }
        $ns = $route_options['namespace'] ?? '';
        if ((is_string($ns) || is_int($ns)) && (string) $ns !== '') {
            return ['kind' => 'namespace', 'namespace' => (string) $ns];
        }
        return ['kind' => 'unknown', 'namespace' => null];
    }

    /** @param array<string,string> $allowed */
    public static function decide(string $kind, ?string $ns, string $method, string $mode, array $allowed, bool $hide): bool
    {
        if ($mode === 'open') {
            return true;
        }
        if ($mode !== 'allowlist') {
            return false;
        }
        switch ($kind) {
            case 'index':
                return !$hide;
            case 'discovery':
                return !$hide && $ns !== null && isset($allowed[$ns]);
            case 'namespace':
                if ($ns === null || !isset($allowed[$ns])) {
                    return false;
                }
                return $allowed[$ns] === 'all' || in_array(strtoupper($method), ['GET', 'HEAD'], true);
            default:
                return false;
        }
    }

    /** rest_dispatch_request, priority PHP_INT_MAX: runs after authentication, validation and permission_callback. */
    public static function gate($result, $request, string $route, array $handler)
    {
        if (!wp_is_serving_rest_request() || get_current_user_id() > 0) {
            return $result;
        }
        $o = self::option();
        if (!self::enforcing($o)) {
            return $result;
        }
        if ($route === '/' . self::INTERNAL_NAMESPACE . '/probe') {
            return $result;
        }
        $server = rest_get_server();
        $options = method_exists($server, 'get_route_options') ? $server->get_route_options($route) : null;
        $c = self::classify($request, $route, $handler, is_array($options) ? $options : null);
        $allowed = self::allowed_map($o);
        $ok = (bool) apply_filters(
            'sfx/rest_guest_is_allowed',
            self::decide($c['kind'], $c['namespace'], (string) $request->get_method(), self::mode($o), $allowed, self::hide_index($o)),
            $request
        );
        return $ok ? $result : self::forbidden($c);
    }

    /** The map enforcement uses (defaults resolved, filter applied) — also used by the probe and the notice. @return array<string,string> */
    public static function allowed_map(array $o): array
    {
        $map = self::effective_map($o, self::namespaces($o) === null ? self::live_namespaces() : []);
        $filtered = apply_filters('sfx/rest_guest_allowed_namespaces', $map);
        return is_array($filtered) ? $filtered : $map;
    }

    /** @param array{kind:string, namespace:?string} $c */
    private static function forbidden(array $c): \WP_Error
    {
        if ($c['kind'] === 'index') {
            $message = __('The REST API index is not available to guests.', 'sfxtheme');
        } elseif ($c['kind'] === 'unknown') {
            $message = __('This REST route is not available to guests.', 'sfxtheme');
        } else {
            /* translators: %s: REST namespace, e.g. wp/v2 */
            $message = sprintf(__('The REST namespace %s is not available to guests.', 'sfxtheme'), (string) $c['namespace']);
        }
        return new \WP_Error('rest_forbidden_guest', $message, ['status' => 401]);
    }

    public static function register_probe_route(): void
    {
        register_rest_route(self::INTERNAL_NAMESPACE, '/probe', [
            'methods'             => 'GET',
            'callback'            => [self::class, 'probe'],
            'permission_callback' => '__return_true',
        ]);
    }

    /** Reports the saved policy for one namespace (GET) or the index; never runs another route. */
    public static function probe($request)
    {
        $o = self::option();
        $live = self::live_namespaces();
        $is_index = $request->get_param('target') === 'index';
        $ns = $request->get_param('namespace');
        if (!$is_index && (!is_string($ns) || !in_array($ns, $live, true))) {
            return new \WP_Error('rest_no_route', __('No route was found matching the URL and request method.', 'sfxtheme'), ['status' => 404]);
        }
        if (!self::enforcing($o)) {
            return ['state' => 'open', 'method' => null];
        }
        $allowed = self::allowed_map($o);
        $ok = $is_index
            ? self::decide('index', null, 'GET', self::mode($o), $allowed, self::hide_index($o))
            : self::decide('namespace', $ns, 'GET', self::mode($o), $allowed, self::hide_index($o));
        return ['state' => $ok ? 'allowed' : 'blocked', 'method' => $ok && !$is_index ? ($allowed[$ns] ?? null) : null];
    }

    public static function remove_discovery_links(): void
    {
        if (get_current_user_id() > 0) {
            return;
        }
        $o = self::option();
        if (!self::enforcing($o)) {
            return;
        }
        if (self::mode($o) === 'closed' || self::hide_index($o)) {
            remove_action('wp_head', 'rest_output_link_wp_head', 10);
            remove_action('template_redirect', 'rest_output_link_header', 11);
        }
    }

    /** @param list<string> $live @param list<string> $seen @param array<string,string> $allowed @return list<string> */
    public static function new_blocked(array $live, array $seen, array $allowed): array
    {
        return array_values(array_filter($live, static fn($ns) => !in_array($ns, $seen, true) && !isset($allowed[$ns])));
    }

    /** Called on init (priority 1) by the WP Optimizer controller. */
    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_probe_route']);
        if (!self::enforcing(self::option())) {
            return;
        }
        add_filter('rest_dispatch_request', [self::class, 'gate'], PHP_INT_MAX, 4);
        add_action('wp', [self::class, 'remove_discovery_links'], 0);
    }
}
