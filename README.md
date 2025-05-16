# SFX Bricks Child Theme

A WordPress child theme for Bricks Builder with enhanced features.

## Development Mode

This theme supports a development mode that disables GitHub updates during local development, preventing your development version from being overwritten by GitHub updates.

### Setup

1. Create a `.env.local` file in the theme root directory:
   ```
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

## Other Documentation 