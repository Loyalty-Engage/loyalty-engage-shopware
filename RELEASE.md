# Release Guide

This guide explains how to create and publish a new release of the LoyaltyEngage Shopware plugin.

## Prerequisites

- Git repository set up on GitHub at `https://github.com/Loyalty-Engage/loyalty-engage-shopware`
- Write access to the repository
- Git installed locally

## Automated Release Process

The plugin uses GitHub Actions to automatically create releases when you push a version tag.

### Step 1: Prepare the Release

1. Ensure all changes are committed and pushed to the `main` branch
2. Update the version in `composer.json` if needed
3. Update `CHANGELOG.md` with release notes (create if it doesn't exist)

### Step 2: Create and Push a Version Tag

```bash
# Navigate to the plugin directory
cd src/custom/plugins/LoyaltyEngage

# Create a version tag (e.g., v1.0.0)
git tag -a v1.0.0 -m "Release version 1.0.0"

# Push the tag to GitHub
git push origin v1.0.0
```

### Step 3: Automatic Release Creation

Once you push the tag, GitHub Actions will automatically:
1. Create a ZIP file with the correct structure
2. Create a GitHub Release
3. Attach both `LoyaltyEngage.zip` and `LoyaltyEngage-1.0.0.zip` to the release

### Step 4: Verify the Release

1. Go to `https://github.com/Loyalty-Engage/loyalty-engage-shopware/releases`
2. Verify the release was created
3. Download and test the ZIP file to ensure it works

## Manual Release Process

If you need to create a release manually:

### Step 1: Create the ZIP File

```bash
# Navigate to the plugins directory
cd src/custom/plugins/

# Create ZIP with correct structure
zip -r LoyaltyEngage.zip LoyaltyEngage \
  -x "*.git*" \
  -x "*node_modules*" \
  -x "*.DS_Store" \
  -x "*vendor*" \
  -x "*.idea*" \
  -x "*.vscode*" \
  -x "*test*"
```

### Step 2: Verify ZIP Structure

```bash
# Check the ZIP contents
unzip -l LoyaltyEngage.zip | head -20
```

You should see paths like:
- `LoyaltyEngage/composer.json`
- `LoyaltyEngage/src/LoyaltyEngage.php`
- `LoyaltyEngage/README.md`

### Step 3: Create GitHub Release

1. Go to `https://github.com/Loyalty-Engage/loyalty-engage-shopware/releases/new`
2. Choose or create a tag (e.g., `v1.0.0`)
3. Set the release title (e.g., `Version 1.0.0`)
4. Add release notes
5. Upload the `LoyaltyEngage.zip` file
6. Click "Publish release"

## Version Numbering

Follow [Semantic Versioning](https://semver.org/):

- **MAJOR** version (1.0.0 → 2.0.0): Breaking changes
- **MINOR** version (1.0.0 → 1.1.0): New features, backwards compatible
- **PATCH** version (1.0.0 → 1.0.1): Bug fixes, backwards compatible

## Release Checklist

Before creating a release:

- [ ] All tests pass
- [ ] Documentation is up to date
- [ ] CHANGELOG.md is updated
- [ ] Version number is updated in composer.json
- [ ] All changes are committed and pushed
- [ ] Tag follows semantic versioning
- [ ] Release notes are prepared

## Troubleshooting

### GitHub Actions Fails

If the automated release fails:
1. Check the Actions tab on GitHub for error details
2. Verify the workflow file syntax
3. Ensure you have the necessary permissions
4. Try the manual release process

### ZIP Structure is Wrong

If the ZIP doesn't have the correct structure:
1. Verify you're in the correct directory when creating the ZIP
2. Check the ZIP contents with `unzip -l`
3. The plugin folder should be at the root of the ZIP

### Release Not Appearing

If the release doesn't appear:
1. Verify the tag was pushed: `git ls-remote --tags origin`
2. Check GitHub Actions for errors
3. Ensure the tag name starts with 'v' (e.g., v1.0.0)

## Post-Release

After creating a release:

1. Announce the release to users
2. Update any documentation that references version numbers
3. Monitor for issues and feedback
4. Plan the next release

## Example Release Notes Template

```markdown
## What's New

- Feature: Added support for X
- Feature: Improved Y functionality
- Enhancement: Better error handling for Z

## Bug Fixes

- Fixed issue with A
- Resolved problem with B

## Breaking Changes

- Changed C (migration guide: ...)

## Installation

Download `LoyaltyEngage.zip` and upload via Shopware Admin Panel.
See [INSTALLATION.md](INSTALLATION.md) for details.

## Full Changelog

See the [commit history](https://github.com/Loyalty-Engage/loyalty-engage-shopware/compare/v1.0.0...v1.1.0)
```

## Support

For questions about the release process:
- Open an issue on GitHub
- Contact: support@loyaltyengage.tech
