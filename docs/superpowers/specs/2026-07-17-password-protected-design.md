# Password Protected — Design

**Date:** 2026-07-17
**Branch (planned):** `feat/password-protected`
**Scope:** new `inc/PasswordProtected/*` + one field in `inc/GeneralThemeOptions/Settings.php` + one hook-suffix entry in `inc/SFXBricksChildTheme.php` + option names in `uninstall.php` + one test in `tests/`

## Goal

Protect the site's **frontend** behind a single shared password, configurable from the theme settings. Modeled on the [Password Protected](https://wordpress.org/plugins/password-protected/) plugin (v2.8.3, vendored at `~/Downloads/password-protected`) for its core protection behaviour and option set, plus one feature the plugin only ships in its Pro tier: a **bypass URL**.

Non-goal: replacing the plugin feature-for-feature. Everything beyond the core protection loop is deliberately out of scope (see [Out of Scope](#out-of-scope)).

## Background

The theme auto-discovers features: `SFXBricksChildTheme::auto_register_features()` globs `inc/*/Controller.php`, and for every class exposing a static `get_feature_config()` registers it in the feature registry. `load_dependencies()` then instantiates each controller — but only if the config's `activation_option_key` is truthy inside `activation_option_name`.

`inc/SecurityHeader/` is the closest existing analogue (a security feature, own settings page, no post type) and is the structural template for this module.

Init order matters and already works out: `functions.php` runs `SFXBricksChildTheme->init()` on `after_setup_theme` priority 1, so a controller constructor can still register `init` priority 1 and `template_redirect` hooks.

## Architecture

```
inc/PasswordProtected/
  Controller.php    hooks, is_active(), login/logout/bypass handling, get_feature_config()
  Auth.php          cookie generate / validate / set / clear
  Settings.php      register_setting() calls, delete_all_options()
  AdminPage.php     submenu page under sfx-theme-settings
  login-form.php    frontend login template
  index.php         silence-is-golden stub (matches sibling modules)
```

Namespace `SFX\PasswordProtected`, `declare(strict_types=1)`, matching the SecurityHeader module.

### Feature gate

New checkbox field in `GeneralThemeOptions\Settings::get_fields()`:

```php
[
    'id'          => 'enable_password_protected',
    'label'       => __('Enable Password Protection', 'sfxtheme'),
    'description' => __('Protect the frontend of this site with a single password.', 'sfxtheme'),
    'type'        => 'checkbox',
    'default'     => 0,
    'group'       => 'general',
]
```

**Default off.** An accidentally-on protection feature locks people out of their own site.

`Controller::get_feature_config()` returns `activation_option_name => 'sfx_general_options'`, `activation_option_key => 'enable_password_protected'`, `hook => null`. Gate off ⇒ controller never constructed ⇒ zero hooks registered.

Note the two-level switch, which mirrors the plugin: the **feature gate** decides whether the code loads at all, the **status option** (`sfx_pp_status`) decides whether protection is currently enforced. Keeping them separate lets a site keep its password and settings while protection is temporarily off.

## Protection flow

Hooks registered by `Controller::__construct()`:

| Hook | Priority | Method |
|---|---|---|
| `init` | 1 | `maybe_process_logout()` |
| `init` | 1 | `maybe_process_bypass()` |
| `init` | 1 | `maybe_process_login()` |
| `init` | 1 | `disable_caching()` |
| `template_redirect` | -10 | `maybe_show_login()` |
| `rest_authentication_errors` | 10 | `filter_rest_access()` |
| `wp` | 10 | `maybe_disable_feeds()` |

### `is_active(): bool`

The single source of truth. Returns `false` (protection off for this request) when any of:

- `sfx_pp_status` is falsy
- request is for `robots.txt` (`is_robots()`)
- request is in wp-admin (`is_admin()`) — **the frontend is protected, the backend is not**; wp-admin and wp-login.php keep their own authentication
- visitor IP (`$_SERVER['REMOTE_ADDR']`) matches an entry in the allowlist
- `sfx_pp_allow_admins` is on and `current_user_can('manage_options')`
- `sfx_pp_allow_users` is on and `is_user_logged_in()`
- `sfx_pp_allow_feeds` is on and `is_feed()`

Result passes through an `sfx_pp_is_active` filter for per-site escape hatches.

Deliberately **not** ported: the plugin's `if ( isset( $_GET['password-protected'] ) ) $is_active = true;` force-on branch, and its Elementor preview exception.

### `maybe_show_login()`

```
if !is_active()            → return
if Auth::validate_cookie() → return
if request is the login screen (?sfx-protected=login) → render login-form.php; exit
otherwise → nocache_headers(); redirect to ?sfx-protected=login&redirect_to=<current URL>; exit
```

### `maybe_process_login()`

Fires on `init` when `is_active()` and `$_POST['sfx_pp_pwd']` is set. Verifies the nonce, then `wp_check_password($input, get_option('sfx_pp_password'))`.

- **Pass:** `Auth::set_cookie($remember)`, then `wp_safe_redirect()` to `redirect_to` (falling back to `home_url('/')`).
- **Fail:** `Auth::clear_cookie()`, add error to a `WP_Error` the template renders.

`$remember` is forced to `false` unless `sfx_pp_allow_remember_me` is on.

`wp_safe_redirect` (not the plugin's hand-rolled `safe_redirect`) confines redirects to this host — `redirect_to` is attacker-controllable.

### `maybe_process_bypass()`

Fires on `init` when `sfx_pp_bypass_enabled` is on and `$_GET['sfx_bypass']` is set and `sfx_pp_bypass_key` is non-empty.

`hash_equals(get_option('sfx_pp_bypass_key'), $_GET['sfx_bypass'])` ⇒ `Auth::set_cookie(false)` and `wp_safe_redirect()` to `sfx_pp_bypass_redirect` (falling back to `home_url('/')`). No match ⇒ do nothing, request proceeds and hits the normal login screen.

Empty key is checked explicitly: without that guard, an empty option plus `?sfx_bypass=` would let anyone in.

### `filter_rest_access( $access )`

Ported from the plugin's `only_allow_logged_in_rest_access()`. When `is_active()`:

- user is logged in and can `edit_posts` or `edit_pages` → allow (keeps Bricks builder and block editor working)
- valid protection cookie → allow
- `sfx_pp_allow_rest` on → allow
- otherwise → `WP_Error('rest_cannot_access', …, ['status' => rest_authorization_required_code()])`

### `maybe_disable_feeds()` / `disable_caching()`

When `is_active()`, feeds `wp_die()` with a pointer back to the site (the `sfx_pp_allow_feeds` option short-circuits this via `is_active()`), and `DONOTCACHEPAGE` is defined if not already. Without the latter, a page cache serves protected pages to everyone and the feature is decorative.

## Auth (`Auth.php`)

Two deliberate departures from the plugin, at no extra cost in code:

| | Plugin | Here |
|---|---|---|
| Password storage | `md5($password)` | `wp_hash_password()` / `wp_check_password()` |
| Cookie signature | `hash_hmac('md5', …)` | `hash_hmac('sha256', …)` + `hash_equals()` |

Cookie format is `{site_id}|{expiration}|{hmac}`, mirroring WordPress core's own auth cookie scheme:

```php
$key  = wp_hash($site_id . '|' . $password_hash . '|' . $expiration, 'auth');
$hmac = hash_hmac('sha256', $site_id . '|' . $expiration, $key);
```

- `$site_id` is `'bid_' . $blog_id` (multisite-safe, as in the plugin).
- `$password_hash` is the stored `wp_hash_password()` output. Because it is baked into the signing key, **changing the password invalidates every existing cookie** for free.
- `wp_hash()` pulls in `wp_salt('auth')`, so cookies are not forgeable without the site's salts.

`validate_cookie()`: parse into exactly 3 parts (else `false`) → reject if `$expiration < time()` → recompute HMAC → `hash_equals()`. Timestamps use `time()` (UTC), not the plugin's `current_time('timestamp')`, which is timezone-shifted and compares wrongly against a UTC cookie lifetime.

`set_cookie( bool $remember )`: expiry is `sfx_pp_remember_me_lifetime` days when remembering (default 14), else a session cookie (`$expire = 0`) with a 20-day internal validity window. Cookie name `sfx_pp_' . COOKIEHASH`; `secure` flag from `is_ssl()`; `httponly` true.

No `SameSite` attribute is set (WordPress's own auth cookies don't either) — a bypass link arriving from an external referrer must still work.

## Settings

Option group `sfx_password_protected_settings_group`. All options prefixed `sfx_pp_`:

| Option | Type | Sanitize | Default |
|---|---|---|---|
| `sfx_pp_status` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_allow_admins` | boolean | `rest_sanitize_boolean` | `true` |
| `sfx_pp_allow_users` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_allow_feeds` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_allow_rest` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_password` | string | custom (below) | `''` |
| `sfx_pp_allowed_ips` | string | `sanitize_textarea_field` | `''` |
| `sfx_pp_allow_remember_me` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_remember_me_lifetime` | integer | `absint`, clamped to 1–365 | `14` |
| `sfx_pp_bypass_enabled` | boolean | `rest_sanitize_boolean` | `false` |
| `sfx_pp_bypass_key` | string | `sanitize_text_field` | `''` |
| `sfx_pp_bypass_redirect` | string | `esc_url_raw` | `''` |

`sfx_pp_allow_admins` defaults **on** — the safe default for the person flipping the switch.

### Password sanitize callback

Two form fields (`sfx_pp_password` = new password, `sfx_pp_password_confirm` = repeat), rendered empty on every page load; only `sfx_pp_password` is a registered setting.

```
input empty           → return existing stored hash (leave password unchanged)
confirm mismatch      → add_settings_error(); return existing stored hash
otherwise             → return wp_hash_password($input)
```

The confirm field is read from `$_POST` inside the callback. Not elegant, but it is where `register_setting()` puts us, and the alternative is a hand-rolled form handler.

Turning `sfx_pp_status` on with an empty stored password triggers an `add_settings_error()` and the status is forced back off — an empty password would otherwise mean `wp_check_password('', '')` decides who gets in.

### Bypass key generation

When `sfx_pp_bypass_enabled` is saved on while `sfx_pp_bypass_key` is empty, generate `wp_generate_password(20, false)`. Left to a human, that field becomes `test123` and the bypass is a hole rather than a feature.

### Data deletion — a deliberate deviation

The sibling modules pair `Settings::delete_all_options()` with a handler in `GeneralThemeOptions\Controller` (`handle_security_header()` et al.) that **wipes the feature's options the moment its toggle is switched off**.

This module does **not** join that chain, and therefore ships no `delete_all_options()` (it would have no caller).

Reason: those options are a credential. Toggling the feature off to troubleshoot for five minutes would silently destroy the password and the bypass key; toggling it back on would leave an unprotectable site and every previously-shared bypass link dead, with no warning and no undo. Deleting a stored secret is not a reversible side effect of a checkbox. The `sfx_pp_status` option already exists as the intended "off switch" that preserves configuration.

Deletion instead happens only on the theme's explicit, opt-in path: the `sfx_pp_*` options are appended to the `$options_to_delete` list in `uninstall.php`, which is gated behind the `delete_on_uninstall` general option.

## Admin page

`AdminPage.php` mirrors `SecurityHeader\AdminPage`: `$menu_slug = 'sfx-password-protected'`, submenu under `sfx-theme-settings`, guarded by `AccessControl::can_access_theme_settings()` for menu registration and `AccessControl::die_if_unauthorized_theme()` on render. Same `sfx-card` / `sfx-form-table` markup, `settings_fields()` + `submit_button()`.

Left column: Status, Permissions, Password, IP allowlist (with the visitor's own IP shown as a hint), Remember Me + lifetime, then the Bypass URL block. Right column: tips card.

When a bypass key exists, the page shows the assembled URL (`home_url('/?sfx_bypass=KEY')`) in a readonly input for copying.

`SFXBricksChildTheme::enqueue_admin_scripts()` gates admin CSS on a hardcoded list of `$hook_suffix` fragments — `'sfx-password-protected'` must be added or the page renders unstyled.

## Login template

`login-form.php`, rendered by `load_template()` so a child site can override it via `locate_template()`. Reproduces the wp-login.php look **without** the plugin's ~230-line template: `wp_enqueue_style('login')` + `wp_head()` gives the styling for free.

Markup: `#login` wrapper → site title link (`h1`) → `WP_Error` messages via `#login_error` → form posting to the current login URL with `sfx_pp_pwd` (type=password, autofocus), hidden `redirect_to`, `wp_nonce_field('sfx_pp_login')`, a "Stay logged in" checkbox (only when `sfx_pp_allow_remember_me`), and a submit button. `nocache_headers()` first; `exit` after.

Skipping: shake JS, `TEST_COOKIE` probing, iPhone branches, the above/below-field text options, and the `password_protected_login_*` action surface.

Accessibility is not negotiable: the password input gets a real `<label>`, the error region `role="alert"`, and the submit button is a `<button type="submit">`.

## Testing

One test file, `tests/password-protected-auth-test.php`, covering the security-critical logic — cookie signing and the bypass comparison.

It follows the existing convention in `tests/` (see `security-header-permissions-policy-test.php`): a plain PHP script, no framework, that declares stubs for the WordPress functions it needs (`wp_hash`, `wp_salt`, `get_option`), requires the class under test directly, asserts via a local `assert_true()` that writes to STDERR and `exit(1)`s on failure, and prints `OK` at the end. Run with `php tests/password-protected-auth-test.php`.

Cases:

1. Cookie generated by `Auth` validates.
2. Tampered HMAC fails.
3. Tampered expiration fails.
4. Expired cookie fails.
5. Malformed cookie (wrong part count) fails.
6. Cookie signed against a different password hash fails (i.e. changing the password logs everyone out).
7. Bypass: correct key matches; wrong key, empty key, and empty stored key all fail.

Existing test conventions in `tests/` are followed; where WordPress functions are needed (`wp_hash`, `wp_salt`), they are stubbed rather than bootstrapping WordPress.

## Out of scope

Not implemented, not stubbed, not configured: reCAPTCHA/hCaptcha, login throttling and lockouts, the Pro multi-password feature, the login-design customizer, cache-plugin integrations, the "Exclude from protection" rules, protected page content, activity report emails, Freemius, and the admin bar indicator.

Also not carried over: the plugin's `$_GET['password-protected']` force-on branch, and the Elementor preview exception (this is a Bricks theme).

## Risks

- **Lockout.** Mitigated by: feature gate default off, `sfx_pp_allow_admins` default on, wp-admin never protected, refusal to enable status without a password.
- **Page caching.** `DONOTCACHEPAGE` covers cache plugins that honour it; a server-level cache (Varnish, host-level full-page cache) can still leak protected pages. Documented in the tips card, not solved in code.
- **No throttling.** A shared password with no rate limit is brute-forceable. Acceptable for the intended use (staging sites, soft launches, client previews); the site owner should not treat this as a security boundary for sensitive data. Called out on the admin page.
