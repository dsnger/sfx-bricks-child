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

# A package without the Composer autoloader fatals on theme load
# (functions.php requires vendor/autoload.php). vendor/ is not in git,
# so a build from a clean export would ship exactly that — refuse.
if [ ! -f "${THEME_DIR}/vendor/autoload.php" ]; then
  echo "Error: vendor/autoload.php not found. Run 'composer install --no-dev --optimize-autoloader' before building."
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
