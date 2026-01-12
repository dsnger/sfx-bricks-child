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
    local skip_publish=$2
    local force_recreate=$3
    local tag_name="v${version}"
    
    # Check if tag exists
    if git rev-parse "${tag_name}" >/dev/null 2>&1; then
        if [ "$force_recreate" = "true" ]; then
            print_warning "Tag ${tag_name} already exists, deleting and recreating..."
            git tag -d "${tag_name}" 2>/dev/null || true
        else
            print_warning "Tag ${tag_name} already exists, skipping tag creation"
            return 0
        fi
    fi
    
    print_status "Creating git tag: ${tag_name}"
    git tag -a "${tag_name}" -m "${tag_name} - Release"
    
    if [ "$skip_publish" = "true" ]; then
        print_warning "Skipping tag push (RC release - not publishing)"
        print_success "Git tag created locally: ${tag_name}"
    else
        print_status "Pushing tag to remote..."
        git push origin "${tag_name}"
        print_success "Git tag created and pushed: ${tag_name}"
    fi
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
        --title "${tag_name}" \
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
    echo "Usage: $0 <version> [release_notes] [--rc]"
    echo ""
    echo "Examples:"
    echo "  $0 0.4.61                                    # Interactive release notes"
    echo "  $0 0.4.61 \"Bug fixes and improvements\"      # Custom release notes"
    echo "  $0 1.0.0                                     # Interactive release notes"
    echo "  $0 0.4.61 \"\" --rc                           # Release candidate (adds _rc suffix, no publish)"
    echo "  $0 0.4.61_rc                                 # RC version (auto-detected, no publish)"
    echo "  $0 template 0.4.61                           # Create release notes template"
    echo ""
    echo "Release Notes Options:"
    echo "  - No second argument: Interactive mode (prompts for release notes)"
    echo "  - Custom text: Use provided release notes"
    echo "  - Type 'auto': Generate automatic release notes"
    echo "  - Type 'template': Create a release notes template file"
    echo ""
    echo "Release Candidate (RC) Mode:"
    echo "  - Use --rc flag or include _rc in version (e.g., 0.4.61_rc)"
    echo "  - Adds _rc suffix to version if not present"
    echo "  - Creates local tag but does NOT push to remote"
    echo "  - Does NOT create GitHub release"
    echo "  - Still builds theme package locally"
    echo ""
    echo "This script will:"
    echo "  1. Update version in style.css"
    echo "  2. Update changelog with new version entry"
    echo "  3. Commit changes"
    echo "  4. Create git tag (push only if not RC)"
    echo "  5. Build theme package"
    echo "  6. Create GitHub release with zip file (skip if RC)"
    echo "  7. Clean up local zip file after upload (if published)"
    echo ""
    echo "Prerequisites:"
    echo "  - Git repository with remote origin"
    echo "  - GitHub CLI (gh) installed and authenticated (only for non-RC releases)"
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
    
    local new_version=""
    local release_notes_arg=""
    local is_rc=false
    
    # Parse arguments - handle --rc flag and extract version/notes
    for arg in "$@"; do
        if [ "$arg" = "--rc" ]; then
            is_rc=true
        elif [ -z "$new_version" ]; then
            new_version="$arg"
        elif [ -z "$release_notes_arg" ] && [ "$arg" != "--rc" ]; then
            release_notes_arg="$arg"
        fi
    done
    
    local current_version=$(get_current_version)
    
    # Check if user wants to create a template
    if [ "$new_version" = "template" ]; then
        if [ -z "$release_notes_arg" ]; then
            print_error "Please provide a version for the template: ./release.sh template <version>"
            exit 1
        fi
        create_release_template "$release_notes_arg"
        exit 0
    fi
    
    # Check if version contains _rc or --rc flag was used
    if [[ "$new_version" == *"_rc"* ]] || [ "$is_rc" = true ]; then
        is_rc=true
        # Ensure _rc suffix is present
        if [[ ! "$new_version" == *"_rc"* ]]; then
            new_version="${new_version}_rc"
            print_status "Added _rc suffix: ${new_version}"
        fi
    fi
    
    # Validate version format (allow _rc suffix)
    local version_pattern="^[0-9]+\.[0-9]+\.[0-9]+(_rc)?$"
    if [[ ! $new_version =~ $version_pattern ]]; then
        print_error "Invalid version format: ${new_version}"
        print_error "Version must be in format: X.Y.Z or X.Y.Z_rc (e.g., 0.4.61 or 0.4.61_rc)"
        exit 1
    fi
    
    # Check if version is newer (compare base versions)
    # Allow re-releasing same RC version (for rebuilds without version bump)
    local base_new_version="${new_version%_rc}"
    local base_current_version="${current_version%_rc}"
    if [ "$base_new_version" = "$base_current_version" ] && [ "$new_version" = "$current_version" ] && [ "$is_rc" != true ]; then
        print_error "Version ${new_version} is already the current version"
        exit 1
    fi
    
    # For RC re-releases with same version, skip version update
    if [ "$new_version" = "$current_version" ] && [ "$is_rc" = true ]; then
        print_warning "Re-releasing RC version ${new_version} (no version update)"
        SKIP_VERSION_UPDATE=true
    else
        SKIP_VERSION_UPDATE=false
    fi
    
    NEW_VERSION="$new_version"  # Make it available for rollback function
    
    # Set trap for rollback on error
    trap rollback ERR
    
    if [ "$is_rc" = true ]; then
        print_status "Starting RELEASE CANDIDATE process for version ${new_version}"
        print_warning "RC Release: Will NOT publish to remote (no push, no GitHub release)"
    else
        print_status "Starting release process for version ${new_version}"
    fi
    print_status "Current version: ${current_version}"
    print_status "New version: ${new_version}"
    echo ""
    
    # Check git status
    check_git_status
    
    # Update version in style.css (skip if re-releasing same RC version)
    if [ "$SKIP_VERSION_UPDATE" != true ]; then
        print_status "Updating version in ${STYLE_FILE}..."
        update_version "${new_version}"
        
        # Update changelog
        print_status "Updating changelog..."
        update_changelog "${new_version}" "$release_notes_arg"
        
        # Commit changes
        print_status "Committing changes..."
        git add "${STYLE_FILE}" "${CHANGELOG_FILE}"
        local commit_msg="Release v${new_version}

- Updated version to ${new_version}
- Updated changelog with release notes
- Automated release process"
        
        if [ "$is_rc" = true ]; then
            commit_msg="${commit_msg}
- Release Candidate (not published)"
        fi
        
        git commit -m "$commit_msg"
    else
        print_status "Skipping version and changelog updates (re-releasing same RC version)"
    fi

    # Create git tag (skip push if RC)
    NEW_TAG="v${new_version}"
    if [ "$is_rc" = true ]; then
        # For RC re-releases, force recreate the tag if it exists
        create_git_tag "${new_version}" "true" "$SKIP_VERSION_UPDATE"
    else
        create_git_tag "${new_version}" "false" "false"
    fi
    
    # Build theme
    build_theme "${new_version}"
    
    # Create GitHub release (skip if RC)
    if [ "$is_rc" = true ]; then
        print_warning "Skipping GitHub release creation (RC release - not publishing)"
        local zip_file="${THEME_NAME}-v${new_version}.zip"
        print_status "RC release package available locally: ${zip_file}"
    else
        create_github_release "${new_version}"
        
        # Clean up zip file after successful release
        local zip_file="${THEME_NAME}-v${new_version}.zip"
        if [ -f "${zip_file}" ]; then
            print_status "Cleaning up zip file..."
            rm "${zip_file}"
            print_success "Removed zip file: ${zip_file}"
        fi
    fi
    
    # Success!
    echo ""
    if [ "$is_rc" = true ]; then
        print_success "🎉 Release Candidate ${new_version} created successfully!"
        print_warning "RC Release: NOT published to remote"
        print_status "Local tag created: v${new_version}"
        local zip_file="${THEME_NAME}-v${new_version}.zip"
        if [ -f "${zip_file}" ]; then
            print_status "RC package available: ${zip_file}"
        fi
        echo ""
        print_status "Next steps:"
        echo "  - Test the RC release on a staging site"
        echo "  - When ready, create final release without _rc suffix"
        echo "  - Or push tag manually: git push origin v${new_version}"
    else
        print_success "🎉 Release ${new_version} completed successfully!"
        print_success "Release URL: https://github.com/dsnger/sfx-bricks-child/releases/tag/v${new_version}"
        print_success "Zip file uploaded and cleaned up"
        echo ""
        print_status "Next steps:"
        echo "  - Test the release on a staging site"
        echo "  - Update documentation if needed"
        echo "  - Notify users of the new release"
    fi
    
    # Remove error trap
    trap - ERR
}

# Run main function with all arguments
main "$@" 