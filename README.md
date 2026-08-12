# SFX Bricks Child Theme

WordPress child theme for [Bricks Builder](https://bricksbuilder.io/) with agency-focused content tools, performance toggles, and security helpers.

Most features are managed under **Global Theme Settings** in wp-admin. WP Optimizer, Image Optimizer, Security Header, Smooth Scroll, Password Protection, and the Menu Items query type can be enabled or disabled in **General Theme Options**.

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

### Admin

- **Custom Dashboard** — configurable wp-admin home (stats, system info, tips, notes; optional Bricks form submissions)
- **General Theme Options** — master switches for the toggleable modules; delete data on uninstall

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
