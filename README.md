# SFX Bricks Child Theme

WordPress child theme for [Bricks Builder](https://bricksbuilder.io/) with agency-focused content tools, performance toggles, and security helpers.

Most features are managed under **Global Theme Settings** in wp-admin. WP Optimizer, Image Optimizer, Security Header, and Smooth Scroll can be enabled or disabled in **General Theme Options**.

## Features

### Core

- **GitHub Theme Updater** — update checks and installs from the theme’s GitHub repository
- **Access control** — restrict Global Theme Settings and Custom Dashboard settings via `wp-config.php` constants
- **Cache helpers** — theme transients cleared on theme/plugin updates
- **Import / Export** — backup and restore theme settings and CPT data (preview, selective import, merge/replace)

### Content (custom post types)

- **Contact Infos** (`sfx_contact_info`) — `[contact_info]` shortcode; Bricks dynamic tags `{contact_info:field}` (optional location/attributes)
- **Social Media Accounts** (`sfx_social_account`) — `[social_accounts]` and `[social_account id="…"]` shortcodes; Bricks dynamic tags `{social_account:field:ID}` and `{social_accounts}` (ID suffix required for per-account fields)
- **Custom Scripts** (`sfx_custom_script`) — enqueue JS/CSS with location rules, priorities, and categories

### Optimization

- **Image Optimizer** — WebP/AVIF conversion on upload, quality and resize controls, batch tools in admin
- **Smooth Scroll** — optional Lenis-based scrolling (General Theme Options)
- **WP Optimizer** — grouped toggles for performance, security, and admin cleanup, including:
  - Revision limiting per post type (0–10, default 3) with post-save pruning
  - Hide login URL with custom slug
  - Content ordering and media replacement utilities
  - Frontend cleanup (jQuery, emoji, embeds, feeds, defer JS/CSS, and more)
  - Security hardening (XML-RPC, REST restrictions, author enumeration blocks, and more)

### Security

- **Security Header** — HSTS, CSP (optional report URI), Permissions-Policy, X-Frame-Options, and related HTTP headers

### Admin

- **Custom Dashboard** — replace wp-admin home with configurable widgets (stats, system info, tips, notes); optional **Bricks form submissions** summary when Bricks Pro’s submissions table is present
- **General Theme Options** — master switches for major features; optional disable Bricks frontend JS/CSS; delete data on uninstall

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
