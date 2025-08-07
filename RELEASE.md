# Release Process

This document explains how to create releases for the SFX Bricks Child Theme using the automated release script.

## 🎉 Quick Start

To create a new release, simply run:

```bash
./release <version>
```

**Examples:**
```bash
./release 0.4.61                                    # Interactive release notes
./release 0.4.61 "Bug fixes and improvements"       # Custom release notes
./release 1.0.0                                     # Interactive release notes
./release template 0.4.61                           # Create release notes template
```

## 🚀 What the Release Script Does

The release script automates the entire release process:

1. **Version Validation**: Checks if the new version is valid and newer than current
2. **Git Status Check**: Ensures working directory is clean
3. **Version Update**: Updates version in `style.css`
4. **Changelog Update**: Adds custom release notes to `CHANGELOG.md`
5. **Git Operations**: Commits changes and creates/pushes git tag
6. **Theme Build**: Runs `build-theme.sh` to create production zip
7. **GitHub Release**: Creates GitHub release with zip file upload
8. **Cleanup**: Removes local zip file after successful upload

## 📁 Files Created

- **`release.sh`** - Main release automation script
- **`release`** - Simple wrapper script for easy usage
- **`RELEASE.md`** - This comprehensive documentation

## 🛡️ Safety Features

- **Error Handling**: Automatic rollback on failure
- **Validation**: Checks prerequisites before starting
- **Colored Output**: Clear status messages throughout the process
- **Safe Operations**: Uses temporary files and proper cleanup

## 📋 Prerequisites

Before using the release script, ensure you have:

- ✅ **Git repository** with remote origin configured
- ✅ **GitHub CLI (gh)** installed and authenticated
- ✅ **Clean git working directory** (no uncommitted changes)
- ✅ **Build script** (`build-theme.sh`) available

## 🎯 Benefits

### Before (Manual Process)
- ❌ 7+ manual steps
- ❌ Risk of human error
- ❌ Inconsistent process
- ❌ Time-consuming

### After (Automated Process)
- ✅ Single command execution
- ✅ Consistent process every time
- ✅ Error handling and rollback
- ✅ Fast and reliable

## Installation

### GitHub CLI

Install GitHub CLI if not already installed:

```bash
# macOS
brew install gh

# Ubuntu/Debian
sudo apt install gh

# Or download from: https://cli.github.com/
```

### Authentication

Authenticate with GitHub:

```bash
gh auth login
```

Follow the prompts to authenticate with your GitHub account.

## Usage

### Release Notes Options

The script supports multiple ways to provide release notes:

1. **Interactive Mode** (default): Script prompts for release notes
   ```bash
   ./release 0.4.61
   ```

2. **Custom Release Notes**: Provide release notes as second argument
   ```bash
   ./release 0.4.61 "Bug fixes and improvements"
   ```

3. **Template Mode**: Create a template file to edit
   ```bash
   ./release template 0.4.61
   # Edit the created file, then run:
   ./release 0.4.61 "$(cat release-notes-0.4.61.md)"
   ```

4. **Automatic Mode**: Generate basic release notes
   ```bash
   ./release 0.4.61
   # When prompted, type 'auto'
   ```

### Basic Usage

```bash
# Create a new release (interactive)
./release 0.4.61

# Create release with custom notes
./release 0.4.61 "Bug fixes and performance improvements"

# Create template first, then release
./release template 0.4.61
# Edit release-notes-0.4.61.md, then:
./release 0.4.61 "$(cat release-notes-0.4.61.md)"
```

## Release Process Details

### 1. Version Validation
- Checks version format (X.Y.Z)
- Ensures new version is different from current
- Validates semantic versioning

### 2. Git Status Check
- Ensures no uncommitted changes
- Prevents accidental releases with uncommitted work
- Shows git status if issues found

### 3. Version Update
- Updates `Version:` in `style.css`
- Maintains proper formatting
- Creates backup before changes

### 4. Changelog Update
- Adds new version entry at top of `CHANGELOG.md`
- Includes current date
- Maintains changelog format
- Supports custom release notes

### 5. Git Operations
- Commits version and changelog changes
- Creates annotated git tag
- Pushes tag to remote repository

### 6. Theme Build
- Runs existing `build-theme.sh` script
- Creates production-ready zip file
- Excludes development files

### 7. GitHub Release
- Creates GitHub release with tag
- Uploads zip file as release asset
- Uses changelog content for release notes

## Error Handling

The script includes comprehensive error handling:

- **Rollback on Error**: Automatically reverts changes if any step fails
- **Validation Checks**: Validates prerequisites before starting
- **Clear Error Messages**: Colored output with specific error details
- **Safe Operations**: Uses temporary files and proper cleanup

## Output

The script provides colored output with clear status messages:

- 🔵 **[INFO]** - General information
- 🟢 **[SUCCESS]** - Successful operations
- 🟡 **[WARNING]** - Warnings and notices
- 🔴 **[ERROR]** - Errors and failures

## Manual Release (if needed)

If you need to create a release manually:

1. **Update version** in `style.css`
2. **Update changelog** in `CHANGELOG.md`
3. **Commit changes**:
   ```bash
   git add style.css CHANGELOG.md
   git commit -m "Release v0.4.61"
   ```
4. **Create tag**:
   ```bash
   git tag -a v0.4.61 -m "v0.4.61 - Release"
   git push origin v0.4.61
   ```
5. **Build theme**:
   ```bash
   ./build-theme.sh
   ```
6. **Create GitHub release**:
   ```bash
   gh release create v0.4.61 --title "v0.4.61 - Release" --notes "Release notes"
   gh release upload v0.4.61 sfx-bricks-child-v0.4.61.zip
   ```

## Troubleshooting

### Common Issues

**"GitHub CLI not authenticated"**
```bash
gh auth login
```

**"Uncommitted changes detected"**
```bash
git status
git add .
git commit -m "Your commit message"
```

**"Build failed! Zip file not created"**
```bash
chmod +x build-theme.sh
./build-theme.sh
```

**"Invalid version format"**
- Use format: X.Y.Z (e.g., 0.4.61, 1.0.0)
- Don't include 'v' prefix (script adds it automatically)

### Rollback

If the release fails, the script automatically rolls back:

- Resets git changes
- Removes created tags
- Cleans up temporary files
- Removes any zip files that were created

## Best Practices

1. **Test First**: Always test changes before releasing
2. **Clean Working Directory**: Ensure no uncommitted changes
3. **Semantic Versioning**: Follow semantic versioning (MAJOR.MINOR.PATCH)
4. **Changelog**: Update changelog with meaningful release notes
5. **Test Release**: Verify the release works after creation

## Version Numbering

Follow semantic versioning:

- **MAJOR**: Breaking changes (1.0.0)
- **MINOR**: New features (0.5.0)
- **PATCH**: Bug fixes (0.4.61)

## Files Modified by Release

- `style.css` - Version number
- `CHANGELOG.md` - Release notes
- Git repository - New commit and tag
- GitHub - New release with assets

## 🔧 Example Usage

```bash
# Check current version
grep "Version:" style.css

# Create new release (interactive)
./release 0.4.61

# Create release with custom notes
./release 0.4.61 "Bug fixes and performance improvements"

# Create template first, then release
./release template 0.4.61
# Edit release-notes-0.4.61.md, then:
./release 0.4.61 "$(cat release-notes-0.4.61.md)"

# View release
open https://github.com/dsnger/sfx-bricks-child/releases
```

## Support

If you encounter issues with the release process:

1. Check the prerequisites
2. Review the error messages
3. Try the manual release process
4. Check the GitHub CLI documentation
5. Verify your git and GitHub setup

## 🎊 Success!

The release process is now fully automated and ready for use. Every future release can be created with a single command!

---

**Next Release**: Just run `./release <version>` and you're done! 🚀 