# Password Protected — Design

**Date:** 2026-07-17
**Branch (planned):** `feat/password-protected`

**Scope:**

- new `inc/PasswordProtected/*`
- one field in `inc/GeneralThemeOptions/Settings.php`
- one entry in `inc/ThemeSettingsOverview/OverviewProvider.php`
- one hook-suffix fragment in `inc/SFXBricksChildTheme.php`
- one option name in `uninstall.php`
- one explanatory comment (no registration) in `inc/ImportExport/Controller.php`
- three tests in `tests/`, one stub addition in `tests/support/`

## Goal

Protect the site's **frontend** behind a single shared password, configurable from the theme settings. Modeled on the [Password Protected](https://wordpress.org/plugins/password-protected/) plugin (v2.8.3, vendored at `~/Downloads/password-protected`) for its core protection behaviour and option set, plus one feature the plugin only ships in its Pro tier: a **bypass URL**.

Non-goal: replacing the plugin feature-for-feature. Everything beyond the core protection loop is deliberately out of scope (see [Out of Scope](#out-of-scope)).

## Threat model

Stating this up front, because it decides how much machinery is justified — and because it is the honest answer to "how protected is this, really?"

This protects **staging sites, soft launches and client previews** from casual visitors and search engines. It is a curtain, not a vault. A single shared password with no rate limiting is brute-forceable; anyone holding the password or a bypass link can pass it on. It must not be used to protect data that would harm someone if disclosed.

**What it cannot protect, by construction:** anything served without WordPress running. `/wp-content/uploads/...` is handed out by the webserver directly — no PHP, no hooks, no theme. Every image, PDF and video on the protected site stays publicly readable to anyone who knows or guesses the URL, and those URLs leak through cached HTML, emails, social previews and older crawls. This is inherent to every plugin of this kind, including the modeled one, and is **not** solved here (solving it means routing all media through PHP, a different feature with real performance cost).

Everything below follows from this: no throttling, no per-user accounts, no server-side revocation, no media protection.

## Background

The theme auto-discovers features: `SFXBricksChildTheme::auto_register_features()` globs `inc/*/Controller.php`, and for every class exposing a static `get_feature_config()` registers it in the feature registry. `load_dependencies()` then instantiates each controller — but only if the config's `activation_option_key` is truthy inside `activation_option_name`.

`inc/SecurityHeader/` is the closest analogue by *purpose* (a security feature, own settings page, no post type) and supplies the module skeleton. It is **not** followed on persistence or admin-page capability: on both counts it is the outlier among the theme's modules, and on both counts it is wrong for this feature. Details below.

Init order works out: `functions.php` runs `SFXBricksChildTheme->init()` on `after_setup_theme` priority 1, so a controller constructor can still register `init` priority 1 and `template_redirect` hooks.

## Architecture

```
inc/PasswordProtected/
  Controller.php    hooks, protection predicates, login/logout/bypass handling, get_feature_config()
  Auth.php          cookie generate / validate / set / clear
  Settings.php      defaults, normalized getters, validate_snapshot(), save_from_request()
  AdminPage.php     submenu page under sfx-theme-settings + form rendering
  login-form.php    frontend login template
  index.php         silence-is-golden stub (matches sibling modules)
```

Namespace `SFX\PasswordProtected`, `declare(strict_types=1)`. PHP `>=8.0` per `composer.json`, so the `setcookie()` array-options signature (7.3+) and `SameSite` are available.

`Settings.php` ships **no** `delete_all_options()` — see [Data deletion](#data-deletion--a-deliberate-deviation).

### The two switches, and what each one actually means

This module has two enable controls, and conflating them is how someone ends up believing a public site is protected. They are labelled to be unmistakable:

| Control | Where | Means | Default |
|---|---|---|---|
| `enable_password_protected` | General Theme Options | *Load the module and show its settings page.* Protects nothing on its own. | off |
| `status` | the module's own page | *The curtain is up right now.* | off |

The general field is therefore labelled **"Enable Password Protection module"** with the description *"Loads the password protection module and adds its settings page. The protection itself is switched on inside that page."* — not "Protect the frontend", which would be a lie about what the checkbox does.

```php
[
    'id'          => 'enable_password_protected',
    'label'       => __('Enable Password Protection module', 'sfxtheme'),
    'description' => __('Loads the password protection module and adds its settings page. The protection itself is switched on inside that page.', 'sfxtheme'),
    'type'        => 'checkbox',
    'default'     => 0,
    'group'       => 'general',
]
```

**Default off.** An accidentally-on protection feature locks people out of their own site.

`Controller::get_feature_config()` returns `activation_option_name => 'sfx_general_options'`, `activation_option_key => 'enable_password_protected'`, `hook => null`. Gate off ⇒ controller never constructed ⇒ zero hooks ⇒ any existing auth cookie is inert, since nothing reads it. ("Logout works while protection is off" below means with `status` off; with the gate off there is nothing to log out of.)

Keeping both switches is required by the theme's feature-gate architecture and by the plugin-compatible option set. The cost is this ambiguity; the labels are the mitigation.

`ThemeSettingsOverview\OverviewProvider::build_builtin_modules_group()` hardcodes its module list rather than deriving it from `get_fields()`, so `enable_password_protected` must be added there or the feature is invisible on the overview page. Its label there is **"Password Protection (module loaded)"** — the overview reports gate state, and must not imply the curtain is up when `status` is off. Its test stub (`tests/support/overview-general-theme-options-settings-stub.php`) needs the field as well.

## Three predicates, not one

An earlier draft used a single `is_active()` at every lifecycle stage. That was wrong twice — it let per-visitor exemptions disable cache protection, and it called query-dependent conditionals before the query existed. The decision is split by *what each answer depends on*:

| Predicate | Depends on | Callable from |
|---|---|---|
| `is_protection_enabled()` | one option | anywhere, incl. `init` |
| `is_visitor_exempt()` | options + current user + `REMOTE_ADDR` | after `init` |
| `is_active()` | the above + `is_feed()` / `is_robots()` | only after `wp` |

### `is_protection_enabled(): bool`

**`status` is on. That is the entire definition.**

It deliberately does *not* also require a non-empty password hash. Making the predicate depend on the hash inverts the failure mode: a corrupted `status=on, password=''` would make the predicate `false`, which switches protection **off** and serves the site to the world — the exact opposite of the intent. (An earlier draft did exactly this while claiming it "failed closed". It failed open.)

The broken state is handled separately, by fail-closed *behaviour* rather than by a disabled predicate:

- `is_configuration_broken(): bool` — `status` on and the normalized hash is exactly `''`. Nothing vaguer: `Settings::get()` forces every non-string to `''`, so this one comparison catches `null`, an array, and a missing key alike.

  It does **not** try to judge whether a *non-empty* string is a "valid" hash. Sniffing for `$P$` / `$wp$` / `$2y$` prefixes would couple this module to WordPress's hashing internals and go stale the next time core changes them. A garbage non-empty hash therefore fails closed silently: `wp_check_password()` never matches it, the curtain holds, but no notice appears. That is the accepted cost of not format-sniffing — the site stays protected either way, and the state is only reachable by editing the database by hand.
- When broken: protection stays enabled, the curtain stays up, `DONOTCACHEPAGE` stays defined, and `wp_check_password()` against an empty hash simply never succeeds — so no password opens it.
- `maybe_render_broken_state_notice()` prints a prominent, unmissable admin notice naming the problem and the fix.

Honest scope of "closed": configured **exemptions still apply** (an allowlisted IP or an admin with `allow_admins` on still gets through), and a **valid bypass key still works** — the bypass is checked against its own key, not the password. That is intentional: it leaves a working way back in for exactly the people entitled to it while the password is broken. What the broken state cannot do is admit the public.

Given the atomic save below, this state should be unreachable; a direct DB edit, a migration or a botched import can still produce it, and "silently serves the protected site to the world" is not an acceptable response to it. Recovery is never blocked — wp-admin is never protected.

### `is_visitor_exempt(): bool`

- visitor `REMOTE_ADDR` matches the allowlist
- `allow_admins` on and `current_user_can('manage_options')`
- `allow_users` on and `is_user_logged_in()`

No query state, so REST can use it. `current_user_can()` / `is_user_logged_in()` lazily resolve the current user via `wp_get_current_user()`, and REST dispatch happens after `init`, so the user is determined by then.

`REMOTE_ADDR` may be absent or non-string (CLI, odd SAPIs): treat that as **no IP exemption**, without notice or type error.

### `is_active(): bool`

`is_protection_enabled() && ! is_visitor_exempt()` and not a query-exempt route:

- `allow_feeds` on and `is_feed()`
- `is_robots()`

Passes through an `sfx_pp_is_active` filter for per-site escape hatches.

`is_feed()` / `is_robots()` are only reliable once the main query is parsed, so **`is_active()` must not be called before `wp`**. It is called from `template_redirect` and `wp` only.

**REST does not use `is_active()`.** `rest_authentication_errors` fires during `parse_request` — *before* `WP::query_posts()`, before `wp`. Calling `is_feed()` there is meaningless and trips `_doing_it_wrong`. REST uses `is_protection_enabled() && ! is_visitor_exempt()`, which is exactly the question it needs answered.

Deliberately **not** ported: the plugin's `if ( isset( $_GET['password-protected'] ) ) $is_active = true;` force-on branch, and its Elementor preview exception (this is a Bricks theme).

### Why cache suppression uses the *first* predicate only

`DONOTCACHEPAGE` is defined whenever `is_protection_enabled()` and `! is_admin()` — **not** when `is_active()`.

This is the difference between a working feature and a silent leak. Keyed off `is_active()`, an anonymous visitor from an allowlisted IP would be exempt from protection *and* their response cacheable — a URL-keyed page cache would then serve that fully-rendered protected page to everyone. Per-visitor exemptions must never switch off cache protection.

Scope of that constant, honestly: it only helps caching layers that run WordPress at least as far as `init` **and** honour the constant afterwards. A cache serving from `advanced-cache.php` before `init`, or a server-level cache (Varnish, host full-page cache), never sees it. See [Risks](#risks).

## Protection flow

Hooks registered by `Controller::__construct()`:

| Hook | Priority | Method | Guarded by |
|---|---|---|---|
| `init` | 1 | `disable_caching()` | `is_protection_enabled()` |
| `init` | 1 | `maybe_process_logout()` | — |
| `init` | 2 | `maybe_process_bypass()` | `is_protection_enabled()` |
| `init` | 2 | `maybe_process_login()` | `is_protection_enabled()` |
| `template_redirect` | -10 | `maybe_show_login()` | `is_active()` |
| `wp` | 10 | `maybe_disable_feeds()` | `is_active()` |
| `rest_authentication_errors` | 10 | `filter_rest_access()` | enabled && !exempt |
| `admin_post_sfx_pp_save` | 10 | `Settings::save_from_request()` | nonce + capability |
| `admin_notices` | 10 | `maybe_render_broken_state_notice()` | `is_configuration_broken()` |

`disable_caching()` sits at priority 1, ahead of the handlers at priority 2, so a login or bypass response — which exits before the rest of the request runs — still gets `DONOTCACHEPAGE` defined. Both handlers additionally call `nocache_headers()` before emitting `Set-Cookie`.

`maybe_process_logout()` is intentionally ungated: a visitor must be able to clear a stale cookie even while `status` is off.

### Request input handling — the rule for every handler below

Every value read from `$_GET` / `$_POST` / `$_REQUEST` / `$_SERVER` is checked with `isset()` **and** `is_string()` before being unslashed or passed anywhere. A request like `?sfx_pp_pwd[]=x` supplies an *array*; passing that to `wp_check_password()`, `hash_equals()` or `sanitize_text_field()` throws a `TypeError` and fatals the page.

A non-string where a string is expected is treated as absent (login/bypass: an ordinary error or a silent no-match; settings: the field's default). This is what makes the "malformed submissions never fatal" promise below true rather than aspirational.

### `maybe_show_login()`

```
if !is_active()            → return
if Auth::validate_cookie() → return
if request is the login screen (?sfx-protected=login) → render template; exit
otherwise → nocache_headers(); redirect to ?sfx-protected=login&redirect_to=<current URL>; exit
```

Template selection, spelled out (`load_template()` performs no discovery of its own):

```php
$file = locate_template(['sfx-password-protected-login.php']);
if (!$file) { $file = __DIR__ . '/login-form.php'; }
$file = apply_filters('sfx_pp_login_template', $file);
if (!file_exists($file)) { $file = __DIR__ . '/login-form.php'; }
load_template($file);
```

### `maybe_process_login()`

Fires on `init` when `is_protection_enabled()` and `$_POST['sfx_pp_pwd']` is a string.

1. **Nonce first, and it is a hard stop.** Extract it through the scalar rule above first — `$nonce = is_string($_POST['_wpnonce'] ?? null) ? wp_unslash($_POST['_wpnonce']) : ''` — *then* `wp_verify_nonce($nonce, 'sfx_pp_login')`. Passing `$_POST['_wpnonce']` straight in would hand an array to a function that string-casts it, which is an "Array to string conversion" warning rather than the clean handling promised above. The security outcome is the same either way; the noise is not.

   On failure — expired, missing, or malformed — record an `expired_nonce` error and **`return` immediately**. Do not check the password, do not set a cookie, do not redirect. The form re-renders with the error at `template_redirect`. (A stale login tab is normal, not an attack; it must never fatal — but it must also never authenticate.)
2. Only if the nonce verified: `wp_check_password(wp_unslash($_POST['sfx_pp_pwd']), Settings::get_password_hash())`.
   - **Pass:** `nocache_headers()`, `Auth::set_cookie($remember)`, redirect to `redirect_to`.
   - **Fail:** `Auth::clear_cookie()`, record an `incorrect_password` error.

`$remember` is forced `false` unless `allow_remember_me` is on.

**The wrong-password re-render is a required invariant, not an accident.** The form posts to the current login URL, so on the failing request `?sfx-protected=login` is still present, `is_active()` is still true (the cookie was just cleared), and `maybe_show_login()` at `template_redirect` matches the login-screen branch and renders the page with the error. Errors travel from `init` to `template_redirect` in `Controller::$errors` (a static `WP_Error`) — same request, so a static property suffices; no transient, no session.

### `maybe_process_logout()`

Fires on `init` when `$_REQUEST['sfx-protected']` is the string `logout`. Verifies an `sfx_pp_logout` nonce, clears the cookie, redirects to a validated `redirect_to`.

### `maybe_process_bypass()`

Fires on `init` when `is_protection_enabled()`, `bypass_enabled` is on, `$_GET['sfx_bypass']` is a **non-empty string**, and the stored `bypass_key` is **non-empty**.

The stored-key emptiness check is not padding: without it, an empty stored key plus `?sfx_bypass=` compares `''` to `''` and lets the world in.

On `hash_equals(stored_key, wp_unslash($_GET['sfx_bypass']))`: `nocache_headers()`, `Auth::set_cookie(false)`, redirect to `bypass_redirect` (empty ⇒ home). No match ⇒ return silently; the request continues to the normal login gate.

`nocache_headers()` before the `Set-Cookie` matters here specifically because the bypass is a **GET**: without it an intermediary may cache a response carrying a working auth cookie and keep handing out access after the key is rotated.

### Redirects

Every redirect in this module goes through:

```php
wp_safe_redirect(wp_validate_redirect($target, home_url('/')));
exit;
```

`wp_safe_redirect()` alone is **not** enough: its fallback for a rejected host is `admin_url()`, which would bounce a protected visitor into wp-admin. `wp_validate_redirect()` with an explicit fallback is what implements "falls back to the home page". `redirect_to` is attacker-controllable — a security boundary, not a nicety.

### `filter_rest_access( $access )`

Ported from the plugin's `only_allow_logged_in_rest_access()`, with one correction:

```
if is_wp_error($access) → return $access      // never mask an earlier auth failure
if !is_protection_enabled() || is_visitor_exempt() → return $access
if logged-in && (edit_posts || edit_pages)  → return $access   // block editor in wp-admin
if Auth::validate_cookie()                  → return $access
if allow_rest                               → return $access
otherwise → WP_Error('rest_cannot_access', …, ['status' => rest_authorization_required_code()])
```

Returning `$access` rather than `true` matters: another plugin's more specific authentication error must survive this filter intact.

The `edit_posts`/`edit_pages` branch exists for the **block editor**, which runs in wp-admin and calls `/wp-json/` — those REST requests are not `is_admin()` and would otherwise be refused while an editor works in a screen this feature never meant to protect.

### The Bricks builder needs the password, and that is correct

Bricks runs its builder on **frontend** requests (`?bricks=run`), which pass through `template_redirect` like any other page. So an editor with Bricks builder rights but **without** `manage_options`, on a site with `allow_users` off (the default), meets the curtain before the builder loads. Opening REST does not change that — the page request is what is blocked.

This is intended, not a defect: they enter the password once, receive the cookie, and the builder works from then on. That is the feature working as designed.

It is deliberately **not** fixed with a Bricks-specific exemption. `?bricks=run` is a query argument any anonymous visitor can append, so exempting on it alone would hand the whole site to anyone who guesses the parameter; exempting properly would mean reaching into Bricks' own builder-capability decision and coupling this module to another theme's internals — real code and a real maintenance burden, to spare an editor one password entry.

Site owners who want editors in the builder without the password have two supported answers already: turn on `allow_users`, or give the reviewer a bypass link. The manual checklist below tests both halves of the promise this design actually makes: the builder loads straight away for an **administrator** via the `allow_admins` exemption, and a **non-admin editor** is asked for the password once and works normally afterwards.

### `maybe_disable_feeds()`

When `is_active()`, feeds `wp_die()` with a link back to the site. `allow_feeds` short-circuits this through `is_active()`.

### What is *not* protected — stated policy, not oversight

- **Media and static files.** `/wp-content/uploads/...` never reaches PHP. See [Threat model](#threat-model). The biggest gap.
- **wp-admin and wp-login.php.** They have their own authentication. Protection is frontend-only, as in the plugin.
- **`admin-ajax.php` and `admin-post.php`.** `is_admin() === true` yet reachable unauthenticated, so `wp_ajax_nopriv_*` / `admin_post_nopriv_*` actions stay callable while the site is protected. They are transport endpoints, not pages: redirecting them to an HTML login screen breaks any form or fetch that uses them. The modeled plugin has the same gap. A plugin returning page content through a nopriv action can leak it past the curtain.
- **`xmlrpc.php`.** Its own authentication, unrelated to this cookie; never reaches `template_redirect`.
- **`wp-cron.php`.** Never reaches `template_redirect`.

All five are named on the admin page — individually, not summarised. Consistent with the threat model; not solved in code.

## Auth (`Auth.php`)

Two departures from the plugin, at no extra cost in code:

| | Plugin | Here |
|---|---|---|
| Password storage | `md5($password)` | `wp_hash_password()` / `wp_check_password()` |
| Cookie signature | `hash_hmac('md5', …)` | `hash_hmac('sha256', …)` + `hash_equals()` |

Cookie format `{site_id}|{expiration}|{hmac}`, mirroring WordPress core's own auth cookie scheme:

```php
$key  = wp_hash($site_id . '|' . $password_hash . '|' . $expiration, 'auth');
$hmac = hash_hmac('sha256', $site_id . '|' . $expiration, $key);
```

- `$site_id` is `'bid_' . $blog_id` (multisite-safe, as in the plugin).
- `$password_hash` is the stored `wp_hash_password()` output, baked into the signing key — so **changing the password invalidates every existing cookie** for free.
- `wp_hash()` pulls in `wp_salt('auth')`; cookies are unforgeable without the site's salts.

### `validate_cookie()`

Strict parsing, in order:

1. input is a non-empty string
2. splits into exactly 3 parts
3. `$site_id` equals the current site ID
4. `$expiration` matches `/^\d{1,12}$/` (an integer — not `"9e99"`, not padding)
5. `$expiration >= time()`
6. recomputed HMAC `hash_equals()` the presented one

Timestamps use `time()` (UTC), **not** the plugin's `current_time('timestamp')`, which is timezone-shifted and compares wrongly against a UTC lifetime.

### `set_cookie( bool $remember )` / `clear_cookie()`

- `$remember` ⇒ expiry `remember_me_lifetime` days (default 14), cookie persists.
- otherwise ⇒ browser session cookie (`$expire = 0`) with a 20-day internal validity window.
- Name `'sfx_pp_' . COOKIEHASH`; `secure` from `is_ssl()`; `httponly` true; `samesite` **`Lax`**.
- Written to `COOKIEPATH` with `COOKIE_DOMAIN`, plus `SITECOOKIEPATH` when the two differ (as the plugin does). PHP's default path scopes the cookie to the current directory, which shows up as protection "randomly" reprompting.
- `clear_cookie()` writes empty values with **identical** name/path/domain attributes for every path `set_cookie()` writes. Mismatched attributes leave a live cookie behind — a logout that does not log out.

`SameSite=Lax` does not break the bypass link: `Lax` still sends and sets cookies on top-level cross-site navigation, which is exactly what clicking a bypass URL in an email is.

### Revocation — a documented invariant

Cookies are signed against the password hash and nothing else. Therefore:

- rotating or disabling the **bypass key does not revoke** cookies already issued through it;
- switching `status` off and on again revokes nothing;
- a session cookie can be replayed for up to its 20-day internal window by a browser that restores sessions;
- a stolen cookie is replayable until it expires. There is no server-side revocation.

**Changing the password is the only way to log everyone out.** Surprising enough that the admin page says so in plain words next to the bypass block — an admin who disables a bypass link will otherwise assume the reviewer is out, and they are not. Accepted rather than solved: per-session server-side state means a session store, which this threat model does not justify.

## Settings — one array option, one save handler

### Persistence: a single array option

All settings live in **one** option, `sfx_password_protected_options`, holding an array.

This is both the theme's dominant convention and the only shape that satisfies this feature's invariants:

- `sfx_general_options`, `sfx_wpoptimizer_options`, `sfx_smooth_scroll_options`, `sfx_text_snippets_options`, `sfx_html_copy_paste_options`, `sfx_social_media_accounts_options` are all single array options. `SecurityHeader` (discrete options + `register_setting()`) is the outlier, and was the wrong module to copy on this point.
- **It makes the save atomic.** One `update_option()` is one row write. Discrete options are written sequentially with no transaction, so two concurrent saves — or a DB failure halfway through — can interleave into a state nobody submitted, including the one state this feature must never reach: `status` on with an empty password. A single row cannot half-write. Concurrent saves degrade to last-writer-wins, where each writer stores a fully validated, self-consistent snapshot.
- One entry in `uninstall.php` instead of twelve.

### Why not `register_setting()`

`options.php` processes a group's options sequentially, one `update_option()` at a time, in registration order. Beyond the atomicity problem, the cross-field rules cannot be expressed in per-option sanitize callbacks at all:

- **Double sanitization.** For an option that does not yet exist, `update_option()` sanitizes, then delegates to `add_option()`, which sanitizes *again*. The second call receives the already-hashed value while `$_POST` still holds the plaintext confirmation — they no longer match, so the callback discards the save or hashes the hash. **The password would break on the very first save.**
- **Ordering.** Status registered before password ⇒ a first-time "set password and enable" save reads the still-empty password and forces status off.
- **Cross-option writes.** A key generated by the bypass callback is overwritten when the posted empty key is processed later in the same loop.

These fields are one credential configuration. They get one option and one handler.

### `Settings::get()` — defensive normalization

The stored option can be anything after an import, a direct DB edit or a failed write: `null`, a scalar, an array with wrong-typed members. `Settings::get()` therefore:

- reads the option, and if it is not an array, treats it as `[]`
- merges over the defaults
- casts each member to its declared type; a member that cannot be sensibly cast falls back to its default

`get_password_hash(): string` always returns a string — never `null`, never an array. A **non-string** hash normalizes to `''`, which routes into the [broken-state](#is_protection_enabled-bool) path rather than into a `TypeError` inside `wp_check_password()`. Every *string* is preserved verbatim, including a garbage non-empty one: nothing here judges a hash's shape, per the definition above.

### `validate_snapshot()` — the contract

```php
Settings::validate_snapshot(array $post, array $existing): array
// returns ['values' => array, 'errors' => array<string,string>]
```

Strictly separated inputs, because conflating them is how a client-posted secret becomes trusted state:

- `$post` — **untrusted** raw request fields. Never contains a hash. Never contains a bypass key. Plaintext `password_new` / `password_confirm` and a `bypass_rotate` intent flag only.
- `$existing` — **trusted** current stored option array. The only source of an existing hash **and of the existing bypass key**.
- `values` — the normalized array to persist.
- `errors` — field ⇒ message. An offending field is not written; its trusted existing (or forced-safe) value is placed into `values` instead, and the whole normalized snapshot is then written. There is no partial-write path.

The rules, reading like what they implement:

```
password:  new empty                    → keep $existing hash
           new !== confirm              → error, keep $existing hash
           otherwise                    → wp_hash_password(new)
status:    on && resulting hash empty   → error, force status off
bypass:    rotate intent posted         → generate new key
           enabled && $existing key ''  → generate new key
           otherwise                    → keep $existing key verbatim
```

`status` is checked against the hash **resulting from this same snapshot**, not the stored one — so "set a password and enable protection" in one save works, which is what a first-time user actually does.

It is isolated and directly unit-testable with stubs — though not *pure*: it calls `wp_hash_password()` and `wp_generate_password()`, so it is neither side-effect-free nor deterministic. That is fine; it is testable, which is the property that matters here.

### Bypass key semantics — the key never comes from the request

**`bypass_key` is read exclusively from `$existing`.** The form posts no key field at all; the readonly display input carries no `name` attribute and is never submitted.

This is not fussiness. If a posted key were authoritative, a stale form silently un-rotates the key:

1. Admin A opens the settings page; the form holds key K1.
2. Admin B rotates to K2; every K1 link stops working.
3. Admin A toggles an unrelated checkbox and saves, posting the stale K1.
4. Validation "keeps the posted key" and writes K1.
5. Every supposedly revoked K1 link works again — silently, with nobody having asked for it.

It would also let any authorized request set an arbitrary key without touching the rotate control, contradicting the decision that rotation is always explicit.

So:

- Rotation happens **only** via an explicit "Generate a new key" checkbox, labelled with its consequence: the old link stops working immediately.
- Bypass enabled while the *stored* key is empty ⇒ generate (`wp_generate_password(20, false)`). First-time setup, not rotation.
- Otherwise the stored key is carried through untouched.
- Disabling bypass **preserves** the key, consistent with the configuration-preservation rationale throughout.

Generation rather than a free-text secret is deliberate: left to a human, that field becomes `test123` and the bypass is a hole rather than a feature.

### `save_from_request()` — the PRG protocol, spelled out

Hooked to `admin_post_sfx_pp_save`:

1. `check_admin_referer('sfx_pp_save')`
2. `AccessControl::die_if_unauthorized_theme()`
3. `$result = validate_snapshot(wp_unslash($_POST), Settings::get())`
4. `update_option('sfx_password_protected_options', $result['values'])` — one call
5. `add_settings_error()` per entry in `$result['errors']`. The accompanying notice is honest about mixed outcomes: a clean save says "Settings saved"; a save with errors says **"Settings saved, but some fields were rejected"** alongside the per-field errors — never an unqualified success next to a red error.
6. `set_transient('settings_errors', get_settings_errors(), 30)`
7. `wp_safe_redirect(add_query_arg('settings-updated', 'true', $page_url)); exit;`

Steps 6 and 7 are what `options.php` does, and both are load-bearing: `settings_errors()` only consumes the transient when `settings-updated` is present in the query. Omitting either means notices silently never appear.

The `settings_errors` transient name is core's and is global — a concurrent save on another settings screen can consume or clobber this one's notices within its 30-second window. A core-wide wart, not worth a bespoke notice system: the cost of hitting it is a missing notice, not a wrong write.

### Options (array keys)

| Key | Type | Default |
|---|---|---|
| `status` | bool | `false` |
| `allow_admins` | bool | `true` |
| `allow_users` | bool | `false` |
| `allow_feeds` | bool | `false` |
| `allow_rest` | bool | `false` |
| `password` | string (hash) | `''` |
| `allowed_ips` | string | `''` |
| `allow_remember_me` | bool | `false` |
| `remember_me_lifetime` | int, clamped 1–365 | `14` |
| `bypass_enabled` | bool | `false` |
| `bypass_key` | string | `''` |
| `bypass_redirect` | string (URL) | `''` |

`allow_admins` defaults **on** — the safe default for the person flipping the switch.

Booleans are cast from the snapshot (absent checkbox ⇒ `false`, never "unchanged"); `bypass_redirect` through `esc_url_raw`; `remember_me_lifetime` through `absint` then clamped. `bypass_key` is never sanitized from input because it is never read from input.

### IP allowlist

Split on newlines, `trim()`, drop entries failing `filter_var($ip, FILTER_VALIDATE_IP)` — as the plugin does, rather than storing an unvalidated blob. Matching is exact string comparison against `REMOTE_ADDR` (IPv4 and IPv6; no CIDR ranges — not requested).

The admin page warns: behind a reverse proxy or CDN, `REMOTE_ADDR` is the *proxy's* address. Allowlisting it allowlists every visitor and silently disables protection. Forwarding headers (`X-Forwarded-For`) are attacker-spoofable and deliberately **not** consulted.

### Import / Export — deliberately excluded

`ImportExport\Controller::get_settings_groups()` is an explicit registry of exportable option groups, and every sibling array option (`sfx_general_options`, `sfx_wpoptimizer_options`, `sfx_smooth_scroll_options`, …) is registered there. **`sfx_password_protected_options` is not registered**, and a comment there says why — matching the existing precedent in that file, which already excludes `sfx_webp_excluded_images` and `sfx_webp_conversion_log` with a `// Note:` line.

Two reasons, either sufficient:

- The array holds a password hash **and** a plaintext bearer token (the bypass key). Export writes both into a portable JSON file that gets emailed, dropped in Downloads, or committed to a repo. A staging password is not worth migrating; leaking one is a real cost for zero benefit.
- Import writes an option wholesale, bypassing `validate_snapshot()` entirely — the one path that enforces "status never on without a password". The spec's own broken-state handling names "a botched import" as a cause; registering the group would be building that cause on purpose.

### Data deletion — a deliberate deviation

The sibling modules pair `Settings::delete_all_options()` with a handler in `GeneralThemeOptions\Controller` (`handle_security_header()` et al.) that **wipes the feature's options the moment its toggle is switched off**.

This module does **not** join that chain, and ships no `delete_all_options()` (it would have no caller).

Reason: those options are a credential. Toggling the feature off to troubleshoot for five minutes would silently destroy the password and the bypass key; toggling it back on would leave an unprotectable site and every shared bypass link dead, with no warning and no undo. Deleting a stored secret is not a reversible side effect of a checkbox. `status` already exists as the off switch that preserves configuration.

Deletion happens only on the explicit, opt-in path: `sfx_password_protected_options` is appended to `$options_to_delete` in `uninstall.php`, gated behind the `delete_on_uninstall` general option.

## Admin page

`AdminPage.php` follows `SecurityHeader\AdminPage`'s structure (`$menu_slug = 'sfx-password-protected'`, submenu under `sfx-theme-settings`, `sfx-card` / `sfx-form-table` markup) with two differences:

- **Capability `'read'`, not `'manage_options'`.** `SFXBricksChildAdmin::register_admin_menu()` documents the convention in a comment: broad cap on the menu so role-based `SFX_THEME_ADMINS` users without `manage_options` can see it, with `AccessControl::can_access_theme_settings()` gating registration and `die_if_unauthorized_theme()` guarding render. `SecurityHeader\AdminPage` still passes `manage_options`, locking out exactly the users `AccessControl` just authorized. A stale bug in the sibling; not copied.
- **Form posts to `admin-post.php`** with `action=sfx_pp_save` + `wp_nonce_field('sfx_pp_save')`, not to `options.php`. `settings_errors()` renders the result.

Left column: Status, Permissions, Password (new + confirm, always rendered empty), IP allowlist (visitor's own IP as a hint + the reverse-proxy warning), Remember Me + lifetime, Bypass block (enabled, readonly key **without a `name`**, "Generate a new key" checkbox, Redirect To).

When a key exists, the assembled URL (`home_url('/?sfx_bypass=KEY')`) is shown readonly for copying, next to the plain-words note that rotating the key does not revoke access already granted.

Right column, tips card — the operational truths that cannot be fixed in code, each named individually:

- uploads and media are **not** protected
- `admin-ajax.php` and `admin-post.php` nopriv actions are **not** protected
- `xmlrpc.php` and `wp-cron.php` are **not** protected
- purge the page cache after enabling; server-level caches are out of reach
- there is no brute-force throttling
- the bypass key travels in a URL and will end up in logs and browser history

`SFXBricksChildTheme::enqueue_admin_scripts()` gates admin CSS on a hardcoded list of `$hook_suffix` fragments — `'sfx-password-protected'` must be added or the page renders unstyled.

## Login template

`login-form.php` prints a **minimal, self-contained document** and deliberately does **not** call `wp_head()`. `wp_head()` would drag the entire frontend head pipeline onto the login screen — theme CSS, Bricks assets, analytics, canonical and feed links, every plugin's arbitrary callbacks — which is both the opposite of the wp-login.php look and a way to leak the protected site's own metadata to an unauthenticated visitor.

Explicitly, in order:

- `nocache_headers()`, `Content-Type` from `get_bloginfo('html_type')` + charset
- `<meta name="viewport">`, `<meta name="robots" content="noindex, nofollow">`
- `wp_site_icon()` (as the plugin does)
- **`wp_admin_css('login', true)`** — the `true` is mandatory. Without `$force_echo`, `wp_admin_css()` merely *enqueues*, and since this document calls neither `wp_head()` nor `print_admin_styles()`, nothing would ever print: an unstyled login page. `$force_echo` prints the handle **and its dependencies** (`dashicons`, `buttons`, `forms`, base styles). The vendored plugin does exactly this.
- `<body class="login wp-core-ui">` — the login CSS is written against those classes; a bare `#login` wrapper does not get the look.
- `#login` → `h1` site-title link → errors → form → `exit`

Form: posts to the current login URL with `sfx_pp_pwd` (type=password, autofocus), hidden `redirect_to`, `wp_nonce_field('sfx_pp_login')`, a "Stay logged in" checkbox (only when `allow_remember_me`), submit.

Skipped from the plugin's ~230-line template: shake JS, `TEST_COOKIE` probing, iPhone branches, above/below-field text options, the `password_protected_login_*` action surface.

Accessibility is not negotiable: a real `<label>` for the password input, `role="alert"` on the error region, `<button type="submit">`.

## Testing

Following the existing convention in `tests/` (see `security-header-permissions-policy-test.php`): plain PHP scripts, no framework, stubbing the WordPress functions they need, requiring the class under test directly, asserting via a local `assert_true()` that writes to STDERR and `exit(1)`s, printing `OK`. Run with `php tests/<name>.php`.

These are unit tests over isolated logic. They cover the decisions that are silent when wrong; they do **not** cover rendering, hook lifecycle or real HTTP — those need a WordPress bootstrap this repo does not have, and are handled by the manual checklist below rather than pretended away.

**`tests/password-protected-auth-test.php`** — cookie signing (stubs `wp_hash`, `wp_salt`):

1. A cookie generated by `Auth` validates.
2. Tampered HMAC fails.
3. Tampered expiration fails.
4. Expired cookie fails.
5. Malformed cookie (wrong part count, empty, non-string) fails.
6. Non-integer expiration (`"9e99"`) fails.
7. Foreign `site_id` fails.
8. A cookie signed against a different password hash fails — changing the password logs everyone out.

**`tests/password-protected-settings-test.php`** — `validate_snapshot()` and `Settings::get()`:

1. Empty new password keeps the existing hash.
2. New password mismatching confirm keeps the existing hash and reports an error.
3. Matching new password produces a hash `wp_check_password()` accepts.
4. Feeding `values` back as `$existing` with an empty `$post` password leaves the hash byte-identical — no re-hashing, no discard.
5. Status on + password set in the *same* snapshot ⇒ status stays on.
6. Status on + no password anywhere ⇒ error and status forced off.
7. Bypass enabled with empty stored key ⇒ key generated. With a stored key ⇒ preserved byte-identical.
8. Rotate intent ⇒ new key differing from the old.
9. **Stale-form regression:** a `$post` carrying an *old* key value cannot change the stored key — `$existing`'s key survives, because no key is ever read from `$post`.
10. Disabling bypass preserves the key.
11. Unchecked checkboxes ⇒ `false`, not "unchanged".
12. `remember_me_lifetime` of `0`, `-5`, `9999` ⇒ clamped into 1–365.
13. Array-valued inputs (`password_new` as an array) ⇒ treated as absent, no `TypeError`.
14. `Settings::get()` over a stored `null`, a scalar, and an array with wrong-typed members ⇒ fully-typed defaults, `get_password_hash()` returns a string.

**`tests/password-protected-predicates-test.php`** — the exemption matrix (stubs `current_user_can`, `is_user_logged_in`, `is_feed`, `is_robots`, `$_SERVER`):

1. `is_protection_enabled()` is false when `status` is off, true when on.
2. **`is_protection_enabled()` stays true when the hash is empty** — the fail-closed invariant; the earlier draft had this inverted and it would have served the site publicly.
3. `is_configuration_broken()` true only for status-on plus a normalized-empty hash; false when status is off, and false for a non-empty hash of any shape (no format sniffing — matches the definition above).
4. Each exemption (IP, admin, logged-in user) flips `is_visitor_exempt()` only when its own option is on.
5. Absent / non-string `REMOTE_ADDR` ⇒ no IP exemption, no error.
6. `is_active()` false for an exempt visitor, true otherwise.
7. `is_active()` false when `is_robots()`; false when `is_feed()` **and** `allow_feeds` on; true when `is_feed()` and `allow_feeds` off.
8. **`is_protection_enabled()` stays true for an exempt visitor** — the invariant that keeps `DONOTCACHEPAGE` on and stops an allowlisted IP warming a public cache with protected content.
9. Bypass key comparison: correct matches; wrong, empty-submitted, empty-stored, and array-valued all fail.

**Manual verification checklist** (run once before merge; these need a live WordPress and are not automated):

1. Login page renders **styled** (the `wp_admin_css('login', true)` regression).
2. Wrong password re-renders the form with the error, and does not authenticate.
3. Expired/missing/array-valued nonce shows the error and does **not** authenticate.
4. Correct password redirects to the originally requested URL.
5. Bypass link grants access and redirects to the configured target; after rotating the key, the old link no longer works.
6. Logout returns to the login screen.
7. REST blocked for anonymous, open for an editor in the block editor; the Bricks builder loads **as an administrator** with `allow_admins` on, and asks a non-admin editor for the password once — after which the builder loads for them too.
8. `status` on with a manually emptied password (direct DB edit) ⇒ curtain stays up, admin notice appears, no password works.
9. Feed reachable with `allow_feeds` on, blocked with it off.

## Out of scope

Not implemented, not stubbed, not configured: reCAPTCHA/hCaptcha, login throttling and lockouts, the Pro multi-password feature, the login-design customizer, cache-plugin integrations, "Exclude from protection" rules, protected page content, activity report emails, Freemius, the admin bar indicator, a logout-link shortcode, CIDR ranges in the IP allowlist, media/upload protection, import/export of these settings, and server-side session revocation.

Also not carried over: the plugin's `$_GET['password-protected']` force-on branch and its Elementor preview exception.

## Risks

- **Media is public.** The single largest gap. See [Threat model](#threat-model). Stated on the admin page rather than hidden.
- **The bypass key is a bearer token in a URL.** It will persist in browser history, CDN and reverse-proxy logs, server access logs, monitoring tools and screenshots. Inherent to the settled shareable-link design; the mitigation is that it is rotatable, and that rotating it is one explicit checkbox.
- **Lockout.** Mitigated by: gate default off, `allow_admins` default on, wp-admin never protected, refusal to enable status without a password, logout reachable while status is off.
- **Cache reach.** `DONOTCACHEPAGE` only helps caches that run WordPress to `init` and honour the constant. A cache serving from `advanced-cache.php` before `init`, or Varnish / a host-level full-page cache, never sees it. Additionally, pages cached *before* protection was enabled keep being served without this theme ever running — enabling protection does not protect a warm cache. The admin page says: purge after enabling.
- **Unprotected endpoints.** `admin-ajax`, `admin-post`, `xmlrpc`, cron. See [What is not protected](#what-is-not-protected--stated-policy-not-oversight).
- **No revocation.** See [Revocation](#revocation--a-documented-invariant).
- **No throttling.** Per the threat model. Called out on the admin page.
- **Concurrent saves.** Last-writer-wins on a single row. Each writer persists a validated snapshot, so no invalid state results — but an admin can silently overwrite another's change made seconds earlier. Accepted; a locking scheme is not justified for a one-page settings screen.
