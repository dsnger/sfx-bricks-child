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

echo "Building ${THEME_NAME} version ${THEME_VERSION}..."
ZIP_NAME="${THEME_NAME}-v${THEME_VERSION}.zip"

# Create temporary build directory
BUILD_DIR=$(mktemp -d)
DEST_DIR="${BUILD_DIR}/${THEME_NAME}"
mkdir -p "$DEST_DIR"

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
  rm -rf "$BUILD_DIR"
  exit 1
fi

# Create the zip file
cd "$BUILD_DIR"
zip -r "$THEME_DIR/$ZIP_NAME" "$THEME_NAME"

# Clean up
cd "$THEME_DIR"
rm -rf "$BUILD_DIR"

echo "Build complete: $ZIP_NAME"
echo "Path: $THEME_DIR/$ZIP_NAME"
