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

# Files/directories to exclude from the package
EXCLUDE=(
  ".git"
  ".github"
  ".gitignore"
  ".vscode"
  ".idea"
  ".DS_Store"
  "node_modules"
  ".env"
  ".env.*"
  ".cursor"
  "build-theme.sh"
  "package-lock.json"
  "composer.lock"
  "*.zip"
  "*.log"
  "*.sql"
  "*.bak"
  "*.tmp"
  "*.sh"
)

# Generate rsync exclude patterns
RSYNC_EXCLUDE=""
for item in "${EXCLUDE[@]}"; do
  RSYNC_EXCLUDE="$RSYNC_EXCLUDE --exclude=$item"
done

# Copy theme files to build directory, excluding development files
rsync -av $RSYNC_EXCLUDE "$THEME_DIR/" "$DEST_DIR/"

# Remove any .env.local file that might have been copied despite exclusions
rm -f "$DEST_DIR/.env.local"

# Create the zip file
cd "$BUILD_DIR"
zip -r "$THEME_DIR/$ZIP_NAME" "$THEME_NAME"

# Clean up
cd "$THEME_DIR"
rm -rf "$BUILD_DIR"

echo "✅ Build complete: $ZIP_NAME"
echo "Path: $THEME_DIR/$ZIP_NAME" 