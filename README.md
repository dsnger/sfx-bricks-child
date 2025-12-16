# SFX Bricks Child Theme

A WordPress child theme for Bricks Builder with enhanced features.

This child theme extends Bricks Builder with powerful content management tools, performance optimizations, security enhancements, and developer utilities. It provides a comprehensive suite of features for managing contact information, social media accounts, text snippets, custom scripts, image optimization, and WordPress performance tuning.

## Features & Functions

### Core Features
- **GitHub Theme Updater** - Automatic theme updates from GitHub repository
- **Development Mode** - Disable updates during local development
- **Access Control** - Two-tier permission system for theme and dashboard settings
- **Cache Management** - Automatic cache clearing on theme/plugin updates

### Content Management
- **Contact Infos** - Custom post type for managing contact information with shortcode `[contact_info]` and Bricks dynamic tags `{contact_info:field}`
- **Social Media Accounts** - Custom post type for social media links with shortcode `[social_accounts]`
- **Text Snippets** - Reusable text content with shortcode `[snippet]` and Bricks dynamic tag `{snippet_content:ID}`
- **Custom Scripts Manager** - Manage and enqueue custom JavaScript/CSS files with conditional loading

### Optimization
- **Image Optimizer** - Automatic WebP/AVIF conversion on upload with quality control and size management
- **WP Optimizer** - Comprehensive WordPress performance optimizations:
  - Disable jQuery/jQuery Migrate, Emoji, Embeds, Feeds, XML-RPC, REST API
  - Remove version numbers, RSD links, shortlinks, wlwmanifest
  - Defer JavaScript/CSS loading
  - Limit post revisions and autosave intervals
  - Disable comments, search, author archives, attachment pages
  - Content ordering and media replacement utilities
  - Remove thumbnail dimensions, navigation containers, caption widths
  - Shortcode formatting improvements

### Security
- **Security Headers** - Configurable HTTP security headers (HSTS, CSP, X-Frame-Options, Permissions Policy, etc.)

### Builder Integration
- **HTML Copy/Paste** - Advanced HTML editing tool for Bricks Builder
- **Shortcodes** - Iconify icon shortcode support
- **Dynamic Tags** - Custom Bricks dynamic data tags for contact info and text snippets

### Admin Features
- **Custom Dashboard** - Customizable WordPress dashboard with stats, system info, and form submissions
- **General Theme Options** - Global theme configuration settings
- **Import/Export** - Export/import theme settings and custom post type data with preview, selective import, and merge/replace modes

### Utilities
- **Environment Handler** - Development/production environment detection
- **Text Domain Support** - Internationalization ready

## Development Mode

This theme supports a development mode that disables GitHub updates during local development, preventing your development version from being overwritten by GitHub updates.

### Setup

1. Create a `.env.local` file in the theme root directory:

   ```bash
   # Set to 'true' to disable GitHub theme updates during development
   SFX_THEME_DEV_MODE=true
   ```

2. The `.env.local` file is automatically ignored by Git to ensure your local development settings are not committed.

3. When you're ready to deploy, either:
   - Delete the `.env.local` file
   - Or set `SFX_THEME_DEV_MODE=false`

### How It Works

- The environment file is loaded at theme initialization
- When `SFX_THEME_DEV_MODE=true`, the GitHub updater will not be initialized
- This prevents the theme from checking for updates during development

## GitHub Updater Authentication

On shared hosting, GitHub's API rate limit (60 requests/hour per IP) can cause "no connection" errors. Add a token for 5,000 requests/hour:

```php
// wp-config.php
define('SFX_GITHUB_TOKEN', 'ghp_your_token_here');
```

**Create token:** [GitHub Settings → Developer settings → Personal access tokens (classic)](https://github.com/settings/tokens) → Generate with `public_repo` scope.

**Debug page:** `/wp-admin/themes.php?page=theme-updater-debug`

## Building a Release Package

To create a production-ready zip file of the theme:

1. Make sure you're in the theme root directory
2. Run the build script:

   ```bash
   ./build-theme.sh
   ```

This will:

- Extract the current version from style.css
- Create a zip file named `sfx-bricks-child-v{VERSION}.zip`
- Exclude development files (.git, node_modules, .env, etc.)
- Place the zip file in the theme root directory

## Restricting Theme Settings Access

Two-tier access control via `wp-config.php`. **If not defined, access is locked for everyone.**

```php
// Theme Settings - role OR capability (auto-detected)
define('SFX_THEME_ADMINS', 'administrator');  // or 'manage_options'

// Custom Dashboard Settings - usernames (comma-separated)
define('SFX_THEME_DASHBOARD', 'agency_user,agency_dev');
```

| SFX_THEME_ADMINS | SFX_THEME_DASHBOARD | Theme Settings | Dashboard Settings |
|------------------|---------------------|----------------|-------------------|
| Not defined | Not defined | Locked | Locked |
| Defined | Not defined | By role/cap | Locked |
| Not defined | Defined | Locked | By username |
| Defined | Defined | By role/cap | By username |
