# SFX Bricks Child Theme

WordPress child theme for [Bricks Builder](https://bricksbuilder.io/) with agency-focused content tools, performance toggles, and security helpers.

Most features are managed under **Global Theme Settings** in wp-admin. WP Optimizer, Image Optimizer, Security Header, Smooth Scroll, Password Protection, Redirects, Editor Prose, and the Menu Items query type can be enabled or disabled in **General Theme Options**.

## Features

### Core

- **GitHub Theme Updater** — update checks and installs from the theme’s GitHub repository
- **Access control** — lock Global Theme Settings and Custom Dashboard behind `wp-config.php` constants
- **Import / Export** — back up and restore settings and CPT data (selective, merge/replace)

### Content (custom post types)

- **Contact Infos** (`sfx_contact_info`) — `[contact_info]` shortcode and `{contact_info:field}` Bricks tags
- **Social Media Accounts** (`sfx_social_account`) — shortcodes, `{social_account:…}` Bricks tags, and a sortable Bricks query loop
- **Custom Scripts** (`sfx_custom_script`) — enqueue JS/CSS with location, priority, and category rules

### Optimization

- **Image Optimizer** — WebP/AVIF conversion on upload, quality/resize controls, batch tools
- **Smooth Scroll** — optional Lenis-based scrolling
- **WP Optimizer** — grouped performance, security, and cleanup toggles: revision limiting, hide-login URL, content ordering, media replacement, frontend cleanup, and hardening

### Security

- **Security Header** — HSTS, CSP, Permissions-Policy, X-Frame-Options, and related HTTP headers
- **Password Protection** — gate the frontend behind one shared password (wp-login-style prompt), with a shareable `?access=` bypass link for clients, IP allowlist, role/feed/REST exemptions, and per-link session revocation

### SEO

- **Redirects** — Tools → Redirects (editors included): exact and regex rules with 301/302/307/308 and 410, hit counter, a privacy-minded 404 log with "create redirect", automatic redirects when a published slug changes, and CSV import/export

### Admin

- **Custom Dashboard** — configurable wp-admin home (stats, system info, tips, notes; optional Bricks form submissions)
- **General Theme Options** — master switches for the toggleable modules; delete data on uninstall
- **Editor Prose** — the block editor shows Gutenberg content like the Bricks frontend: set the prose class(es) and wrapper element (Rich Text or Post Content) under Global Theme Settings → Editor Prose. Everything in the class is mirrored; spacing between blocks comes from the Bricks theme style. Optional token-based baseline (`sfx-prose` class) built on your Core Framework / Bricks variables. Requires Bricks 2.4+.

## Editor Prose: authoring notes

- Put prose styling in the **Bricks global class**, not on the element: settings on the wrapper element itself (compiled to its ID) are not mirrored.
- Write the class CSS nested under the class (or `%root%`). Avoid braces inside `content:` strings — Bricks' editor scoper can break on them.
- **Not mirrored:** rules depending on elements outside the content (`body.single-post …`, variables set on a surrounding section); selectors on wrapper attributes other than class; relative `url()`s (they resolve against the admin URL); editor-only structure (`:last-child` next to the block appender, zoom-mode separators); class settings driven by dynamic data.
- **Breakpoints** follow the editor canvas width, not the content width. Use the editor's device preview to check tablet/mobile rules — in a narrow browser window WordPress may only scale the Tablet preview visually (the canvas keeps its desktop width, so tablet rules do not apply); the Mobile preview or a wider window changes the real canvas width. Percentage spacing and container queries also need the same content width.
- **Cascade differences you can meet:** the editor prefix makes prose rules one class stronger than on the frontend, so a prose rule that loses to a WordPress block style live (e.g. the large quote) can win in the editor; in Bricks' Post Content mode, theme-style link colours in the editor are more specific than live; against component classes Bricks also loads into the editor, the prose CSS always comes later. Where it matters, give the prose rule one more class of specificity.
- **Several prose classes:** list them in the order they have on the frontend element. With Bricks' Class Manager load order off, the frontend order is page-wide (first encounter anywhere on the page), so a class used earlier elsewhere can reorder them.
- A class reused on other element types while Bricks' class chaining is off gets element-specific rules there that the editor does not mirror.
- **Starting from scratch:** either tick *Baseline* and add `sfx-prose` to the wrapper, or copy the *Starter* from the settings page into a new Bricks global class and adjust it there.
- Import in **merge** mode keeps existing values where the import is empty; use **replace** for an exact copy of another site's settings.
- Requires Bricks theme styles in the block editor (Bricks setting). Options that remove Bricks or block CSS on the frontend only (WP Optimizer) make frontend and editor differ by design.
- **Trust:** whoever can edit Bricks global classes can put CSS into the editor of every covered post — give that permission only to people trusted with all drafts.
- **Baseline (`sfx-prose`):** layered and token-based (`--text-*`, `--space-*`, `--link`, `--caption-*`, `--table-*`, `--quote-*` …, each with a Core Framework token and a literal as fallback). Anything unlayered wins: the theme style, Core Framework, your prose class, WordPress block styles. Spacing between blocks and list items is the theme style's contextual spacing; with "remove default padding" on, list indent and quote padding are the theme style's job.

## Requirements

- WordPress with **Bricks** parent theme
- **PHP 8+**
- Run `composer install` in the theme root (autoloader; admin notice shown if missing)

## Development mode

Disable GitHub update checks during local development.

1. Create `.env.local` in the theme root:

   ```bash
   SFX_THEME_DEV_MODE=true
   ```

2. `.env.local` is gitignored (via `.env.*`).

3. For production, delete the file or set `SFX_THEME_DEV_MODE=false`.

When enabled, `SFX\Environment::is_dev_mode()` is true and the GitHub updater is not initialized.

## GitHub updater authentication

Shared hosting may hit GitHub’s unauthenticated API limit (60 requests/hour per IP). Set a token in `wp-config.php` for 5,000 requests/hour:

```php
define('SFX_GITHUB_TOKEN', 'ghp_your_token_here');
```

Create a [classic personal access token](https://github.com/settings/tokens) with `public_repo` scope.

Debug page: `/wp-admin/themes.php?page=theme-updater-debug`

## Build a release zip

From the theme root:

```bash
./build-theme.sh
```

Creates `sfx-bricks-child-v{VERSION}.zip` using the version from `style.css`, excluding dev files (`.git`, `node_modules`, `.env`, etc.).

For versioned releases with changelog and tagging, use `./release.sh <version>` (see `.cursor/rules/publish-release.mdc`).

## Restricting settings access

Define in `wp-config.php`. **If constants are missing, access is locked.**

```php
// Global Theme Settings — role or capability
define('SFX_THEME_ADMINS', 'administrator');  // or 'manage_options'

// Custom Dashboard settings — comma-separated usernames
define('SFX_THEME_DASHBOARD', 'agency_user,agency_dev');
```

| `SFX_THEME_ADMINS` | `SFX_THEME_DASHBOARD` | Theme settings | Dashboard settings |
|--------------------|-----------------------|----------------|--------------------|
| Not defined        | Not defined           | Locked         | Locked             |
| Defined            | Not defined           | By role/cap    | Locked             |
| Not defined        | Defined               | Locked         | By username        |
| Defined            | Defined               | By role/cap    | By username        |
