#!/bin/bash

# Theme package builder script
# Creates a production-ready zip file with the correct version number

set -e  # Exit on error

# Get the theme directory (assuming this script is in the theme root)
THEME_DIR=$(pwd)
THEME_NAME="sfx-bricks-child"

# Extract version from style.css
THEME_VERSION=$(grep -m 1 "Version:" "${THEME_DIR}/style.css" | awk -F' ' '{print $2}' | tr -d '\r')

if [ -z "$THEME_VERSION" ]; then
  echo "Error: Could not extract theme version from style.css"
  exit 1
fi

# A package without a WORKING Composer autoloader fatals on theme load
# (functions.php requires vendor/autoload.php). vendor/ is not in git, so a
# build from a clean export would ship exactly that — refuse.
#
# Existence is not the property that matters: a one-line `<?php` placeholder
# passed the old is-file check here on 2026-08-28 and would have shipped a theme
# that loads no class. Neither is shape: a vendor/ holding only autoload.php and
# ClassLoader.php looks plausible and fatals on require, because Composer's
# entry point pulls in composer/autoload_real.php. So load it for real.
if [ ! -f "${THEME_DIR}/vendor/autoload.php" ]; then
  echo "Error: vendor/autoload.php not found. Run 'composer install --no-dev --optimize-autoloader' before building."
  exit 1
fi

# Resolve PHP the way quality.sh does, including its version probe. An exit code
# alone is satisfied by `PHP=/usr/bin/true`, and a bare "it ran" by PHP 7.4 —
# both would wave broken autoloaders through.
php_bin=""
for candidate in "${PHP:-}" php /Applications/MAMP/bin/php/php8.5.2/bin/php; do
  [ -n "$candidate" ] || continue
  case "$candidate" in
    */*) [ -x "$candidate" ] || continue ;;
    *) command -v "$candidate" >/dev/null 2>&1 || continue ;;
  esac
  # Version as a number PHP computed, not a string it could have echoed.
  # `|| ver=""`: under `set -e` a failing command substitution aborts the whole
  # script, so an unusable $PHP would stop the build instead of falling through
  # to the next candidate.
  ver=$("$candidate" -r 'echo PHP_VERSION_ID + 1;' 2>/dev/null) || ver=""
  case "$ver" in '' | *[!0-9]*) continue ;; esac
  [ "$ver" -gt 80000 ] || continue
  php_bin=$candidate
  break
done
if [ -z "$php_bin" ]; then
  echo "Error: no usable PHP >= 8.0 found (tried \$PHP, php on PATH, the MAMP build)."
  echo "       PHP is needed to verify the Composer autoloader before packaging."
  exit 1
fi

# Load it, and RESOLVE a class through it. Weaker checks all failed in review: a
# file that exists (a `<?php` placeholder passed), a file whose shape looks right
# (a half-copied vendor/ fatals on require), an object with loadClass() (a
# hand-written stub passed), a loader advertising the SFX\ prefix (a prefix
# pointing at a directory that is not there passed). Only resolving the class
# proves the package will boot. Output from the autoloader is discarded so it
# cannot forge the marker.
# ponytail: this stops accidents — a stub, a half-copied tree, someone else's
# vendor/. A deliberately crafted fake still wins and is not the threat model.
probe=$("$php_bin" -r '
    ob_start();
    require $argv[1];
    ob_end_clean();
    // Two classes, and deliberately not only the one functions.php also requires
    // by hand: a stale --optimize-autoloader classmap can carry one and miss the
    // other, which still fatals on activation.
    echo (class_exists("SFX\\SFXBricksChildTheme")
          && class_exists("SFX\\SFXBricksChildAdmin")) ? "LOADER-OK" : "";
' "${THEME_DIR}/vendor/autoload.php" 2>/dev/null) || probe=""
if [ "$probe" != "LOADER-OK" ]; then
  echo "Error: vendor/autoload.php does not resolve this theme's classes."
  echo "       Run 'composer install --no-dev --optimize-autoloader' before building."
  exit 1
fi

echo "Building ${THEME_NAME} version ${THEME_VERSION}..."
ZIP_NAME="${THEME_NAME}-v${THEME_VERSION}.zip"

# Create temporary build directory. One EXIT trap covers every failure path from
# here on — set -e would otherwise leave a full copy of the theme in the system
# temp dir after a failed rsync, zip or mv.
BUILD_DIR=$(mktemp -d)
ZIP_TMP=""
trap 'rm -rf "$BUILD_DIR"; rm -f "$ZIP_TMP"' EXIT
DEST_DIR="${BUILD_DIR}/${THEME_NAME}"
mkdir -p "$DEST_DIR"

# Production zip filter only. Do not copy these into .gitignore — tests, docs,
# Cursor rules, and release scripts must stay in git so a clone can keep developing.
# Root-anchored patterns start with / and apply only at the theme root.
# Unanchored names match that filename anywhere (nested .git, .DS_Store, etc).
EXCLUDE=(
  ".git"
  ".github"
  ".gitignore"
  ".gitattributes"
  ".vscode"
  ".idea"
  ".DS_Store"
  "._*"
  "node_modules"
  ".env"
  ".env.*"
  "/.cursor/"
  "/.claude/"
  "/.conductor/"
  "/.remember/"
  "/.superpowers/"
  "/.cloud/"
  "/.codex/"
  "/.context/"
  "/.mcp.json"
  "/.mcp/"
  "/AGENTS.md"
  "/CLAUDE.md"
  "/todos.md"
  "/.agents/"
  "/docs/"
  "/tests/"
  "/test-github-updater.php"
  "/env-example.txt"
  "/inc/CustomDashboard/docs/"
  "/inc/ImageOptimizer/FIX-NOTES.md"
  "/release"
  "/release.sh"
  "/build-theme.sh"
  "/package-lock.json"
  "/composer.lock"
  "*.zip"
  ".*.zip.??????"
  "*.log"
  "*.sql"
  "*.bak"
  "*.tmp"
  "*.sh"
)

# Paths that must never appear in the package, even if an exclude is missed.
FORBIDDEN_PATHS=(
  .git
  .github
  .gitignore
  .vscode
  .idea
  .cursor
  .claude
  .conductor
  .remember
  .superpowers
  .cloud
  .codex
  .context
  .mcp.json
  .mcp
  .gitattributes
  AGENTS.md
  CLAUDE.md
  todos.md
  .agents
  docs
  tests
  test-github-updater.php
  env-example.txt
  release
  build-theme.sh
  release.sh
  inc/CustomDashboard/docs
  inc/ImageOptimizer/FIX-NOTES.md
)

# Generate rsync exclude patterns
RSYNC_EXCLUDES=()
for item in "${EXCLUDE[@]}"; do
  RSYNC_EXCLUDES+=(--exclude="$item")
done

# Copy theme files to build directory, excluding development files
rsync -a "${RSYNC_EXCLUDES[@]}" "$THEME_DIR/" "$DEST_DIR/"

# Remove any .env.local file that might have been copied despite exclusions
rm -f "$DEST_DIR/.env.local"

leaked=""
for name in "${FORBIDDEN_PATHS[@]}"; do
  if [ -e "${DEST_DIR}/${name}" ]; then
    leaked="${leaked}${name}"$'\n'
  fi
done

if [ -n "$leaked" ]; then
  echo "Error: development paths leaked into the package:"
  printf '%s' "$leaked"
  exit 1
fi

# Create the zip file. Write to a fresh temp path and move it into place: `zip -r`
# UPDATES an existing archive of the same name rather than replacing it, so a file
# that has since become forbidden — or been deleted from the theme — survives a
# same-version rebuild and ships, with every exclude and guard above still passing.
# The temp name is unique per run (two concurrent builds must not hand each other a
# half-written archive) and lives in THEME_DIR so the mv is a same-filesystem rename.
# The trap removes it on any exit path — set -e would otherwise leave residue behind.
cd "$BUILD_DIR"
ZIP_TMP=$(mktemp "$THEME_DIR/.${ZIP_NAME}.XXXXXX")
rm -f "$ZIP_TMP"
zip -r "$ZIP_TMP" "$THEME_NAME"
mv -f "$ZIP_TMP" "$THEME_DIR/$ZIP_NAME"

# Clean up (the EXIT trap also covers this; doing it here keeps the success path
# explicit and makes the trap a backstop rather than the only cleanup).
cd "$THEME_DIR"
rm -rf "$BUILD_DIR"

echo "Build complete: $ZIP_NAME"
echo "Path: $THEME_DIR/$ZIP_NAME"
