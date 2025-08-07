#!/bin/bash

# SFX Bricks Child Theme Release Script
# Automates the entire release process from version bump to GitHub release

set -e  # Exit on error

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
THEME_NAME="sfx-bricks-child"
STYLE_FILE="style.css"
CHANGELOG_FILE="CHANGELOG.md"

# Function to print colored output
print_status() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

print_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

print_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Function to get current version
get_current_version() {
    grep -m 1 "Version:" "${STYLE_FILE}" | awk -F' ' '{print $2}' | tr -d '\r'
}

# Function to update version in style.css
update_version() {
    local new_version=$1
    local temp_file=$(mktemp)
    
    sed "s/Version:.*/Version:      ${new_version}/" "${STYLE_FILE}" > "${temp_file}"
    mv "${temp_file}" "${STYLE_FILE}"
    
    print_success "Updated version to ${new_version} in ${STYLE_FILE}"
}

# Function to update changelog
update_changelog() {
    local new_version=$1
    local release_date=$(date +%Y-%m-%d)
    local release_notes=""
    
    # Check if release notes were provided as second argument
    if [ -n "$2" ]; then
        release_notes="$2"
    else
        # Interactive mode - ask user for release notes
        echo ""
        print_status "Enter release notes for version ${new_version}"
        print_status "Press Enter twice to finish, or type 'auto' for automatic entry"
        echo ""
        
        read -p "Release notes (or 'auto'): " user_input
        
        if [ "$user_input" = "auto" ]; then
            # Generate automatic release notes
            release_notes="### Added

- **Release ${new_version}**: Automated release with version management
  - **Version Update**: Updated to version ${new_version}
  - **Build Process**: Production-ready theme package created
  - **GitHub Release**: Automatic release creation with zip file

### Changed

- **Release Process**: Automated release workflow
  - **Consistency**: Standardized release process
  - **Efficiency**: Streamlined version management"
        else
            # Collect multi-line input
            release_notes=""
            if [ -n "$user_input" ]; then
                release_notes="$user_input"
            fi
            
            print_status "Enter additional release notes (press Enter twice to finish):"
            while IFS= read -r line; do
                if [ -z "$line" ] && [ -z "$release_notes" ]; then
                    break
                fi
                if [ -z "$line" ]; then
                    release_notes="${release_notes}

"
                else
                    if [ -z "$release_notes" ]; then
                        release_notes="$line"
                    else
                        release_notes="${release_notes}
$line"
                    fi
                fi
            done
        fi
    fi
    
    # Create temporary file for new changelog entry
    local temp_file=$(mktemp)
    
    # Add new version entry at the top
    cat > "${temp_file}" << EOF
<!-- markdownlint-disable MD024 -->
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).



## [${new_version}] - ${release_date}

${release_notes}

EOF

    # Add the rest of the existing changelog
    tail -n +12 "${CHANGELOG_FILE}" >> "${temp_file}"
    
    # Replace the original changelog
    mv "${temp_file}" "${CHANGELOG_FILE}"
    
    print_success "Updated ${CHANGELOG_FILE} with new version entry"
}

# Function to check git status
check_git_status() {
    if [ -n "$(git status --porcelain)" ]; then
        print_warning "Uncommitted changes detected. Please commit or stash them before releasing."
        git status --short
        exit 1
    fi
    
    print_success "Git working directory is clean"
}

# Function to create git tag and push
create_git_tag() {
    local version=$1
    local tag_name="v${version}"
    
    print_status "Creating git tag: ${tag_name}"
    git tag -a "${tag_name}" -m "${tag_name} - Release"
    
    print_status "Pushing tag to remote..."
    git push origin "${tag_name}"
    
    print_success "Git tag created and pushed: ${tag_name}"
}

# Function to build theme
build_theme() {
    print_status "Building theme package..."
    
    if [ ! -f "build-theme.sh" ]; then
        print_error "build-theme.sh not found!"
        exit 1
    fi
    
    chmod +x build-theme.sh
    ./build-theme.sh
    
    local zip_file="${THEME_NAME}-v${1}.zip"
    if [ ! -f "${zip_file}" ]; then
        print_error "Build failed! Zip file not created: ${zip_file}"
        exit 1
    fi
    
    print_success "Theme built successfully: ${zip_file}"
}

# Function to create GitHub release
create_github_release() {
    local version=$1
    local tag_name="v${version}"
    local zip_file="${THEME_NAME}-v${version}.zip"
    
    print_status "Creating GitHub release: ${tag_name}"
    
    # Check if GitHub CLI is available
    if ! command -v gh &> /dev/null; then
        print_error "GitHub CLI (gh) is not installed or not in PATH"
        print_warning "Please install GitHub CLI: https://cli.github.com/"
        exit 1
    fi
    
    # Check if authenticated
    if ! gh auth status &> /dev/null; then
        print_error "GitHub CLI not authenticated. Please run: gh auth login"
        exit 1
    fi
    
    # Create release with changelog content
    local release_notes=$(awk '/^## \['"${version}"'\]/,/^## \[/ {if (!/^## \[/ || /^## \['"${version}"'\]/) print}' "${CHANGELOG_FILE}" | sed '1d' | sed '/^## \[/q' | sed '$d')
    
    gh release create "${tag_name}" \
        --title "${tag_name} - Release" \
        --notes "${release_notes}"
    
    print_success "GitHub release created: ${tag_name}"
    
    # Upload zip file
    print_status "Uploading zip file to release..."
    gh release upload "${tag_name}" "${zip_file}"
    
    print_success "Zip file uploaded to release"
}

# Function to create release notes template
create_release_template() {
    local version=$1
    local template_file="release-notes-${version}.md"
    
    cat > "${template_file}" << EOF
# Release Notes for v${version}

## Summary
Brief description of what this release includes.

## Added
- New features or functionality

## Changed
- Changes to existing functionality

## Fixed
- Bug fixes

## Removed
- Removed features (if any)

## Breaking Changes
- Any breaking changes (if any)

## Technical Details
- Implementation details
- Performance improvements
- Security updates

## Migration Guide
- Steps to migrate from previous version (if needed)
EOF

    print_success "Created release notes template: ${template_file}"
    print_status "Edit this file with your release notes, then run:"
    print_status "./release ${version} \"\$(cat ${template_file})\""
}

# Function to show usage
show_usage() {
    echo "Usage: $0 <version> [release_notes]"
    echo ""
    echo "Examples:"
    echo "  $0 0.4.61                                    # Interactive release notes"
    echo "  $0 0.4.61 \"Bug fixes and improvements\"      # Custom release notes"
    echo "  $0 1.0.0                                     # Interactive release notes"
    echo "  $0 template 0.4.61                           # Create release notes template"
    echo ""
    echo "Release Notes Options:"
    echo "  - No second argument: Interactive mode (prompts for release notes)"
    echo "  - Custom text: Use provided release notes"
    echo "  - Type 'auto': Generate automatic release notes"
    echo "  - Type 'template': Create a release notes template file"
    echo ""
    echo "This script will:"
    echo "  1. Update version in style.css"
    echo "  2. Update changelog with new version entry"
    echo "  3. Commit changes"
    echo "  4. Create and push git tag"
    echo "  5. Build theme package"
    echo "  6. Create GitHub release with zip file"
    echo "  7. Clean up local zip file after upload"
    echo ""
    echo "Prerequisites:"
    echo "  - Git repository with remote origin"
    echo "  - GitHub CLI (gh) installed and authenticated"
    echo "  - Clean git working directory"
}

# Function to rollback on error
rollback() {
    print_error "Release failed! Rolling back changes..."
    
    # Reset git changes
    git reset --hard HEAD
    git clean -fd
    
    # Remove any created tags
    if [ -n "$NEW_TAG" ]; then
        git tag -d "$NEW_TAG" 2>/dev/null || true
        git push origin ":refs/tags/$NEW_TAG" 2>/dev/null || true
    fi
    
    # Clean up any zip files that might have been created
    local zip_file="${THEME_NAME}-v${NEW_VERSION}.zip"
    if [ -f "${zip_file}" ]; then
        print_status "Cleaning up zip file from failed release..."
        rm "${zip_file}"
        print_success "Removed zip file: ${zip_file}"
    fi
    
    print_warning "Rollback completed. Please check the error and try again."
}

# Main execution
main() {
    # Check if version is provided
    if [ $# -eq 0 ]; then
        show_usage
        exit 1
    fi
    
    local new_version=$1
    local current_version=$(get_current_version)
    NEW_VERSION="$new_version"  # Make it available for rollback function
    
    # Check if user wants to create a template
    if [ "$new_version" = "template" ]; then
        if [ $# -lt 2 ]; then
            print_error "Please provide a version for the template: ./release.sh template <version>"
            exit 1
        fi
        create_release_template "$2"
        exit 0
    fi
    
    # Validate version format
    if [[ ! $new_version =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
        print_error "Invalid version format: ${new_version}"
        print_error "Version must be in format: X.Y.Z (e.g., 0.4.61)"
        exit 1
    fi
    
    # Check if version is newer
    if [ "$new_version" = "$current_version" ]; then
        print_error "Version ${new_version} is already the current version"
        exit 1
    fi
    
    # Set trap for rollback on error
    trap rollback ERR
    
    print_status "Starting release process for version ${new_version}"
    print_status "Current version: ${current_version}"
    print_status "New version: ${new_version}"
    echo ""
    
    # Check git status
    check_git_status
    
    # Update version in style.css
    print_status "Updating version in ${STYLE_FILE}..."
    update_version "${new_version}"
    
    # Update changelog
    print_status "Updating changelog..."
    update_changelog "${new_version}" "$2"
    
    # Commit changes
    print_status "Committing changes..."
    git add "${STYLE_FILE}" "${CHANGELOG_FILE}"
    git commit -m "Release v${new_version}

- Updated version to ${new_version}
- Updated changelog with release notes
- Automated release process"

    # Create git tag
    NEW_TAG="v${new_version}"
    create_git_tag "${new_version}"
    
    # Build theme
    build_theme "${new_version}"
    
    # Create GitHub release
    create_github_release "${new_version}"
    
    # Clean up zip file after successful release
    local zip_file="${THEME_NAME}-v${new_version}.zip"
    if [ -f "${zip_file}" ]; then
        print_status "Cleaning up zip file..."
        rm "${zip_file}"
        print_success "Removed zip file: ${zip_file}"
    fi
    
    # Success!
    echo ""
    print_success "🎉 Release ${new_version} completed successfully!"
    print_success "Release URL: https://github.com/dsnger/sfx-bricks-child/releases/tag/v${new_version}"
    print_success "Zip file uploaded and cleaned up"
    echo ""
    print_status "Next steps:"
    echo "  - Test the release on a staging site"
    echo "  - Update documentation if needed"
    echo "  - Notify users of the new release"
    
    # Remove error trap
    trap - ERR
}

# Run main function with all arguments
main "$@" 