# SiteOrigin GitHub Updater

SiteOrigin GitHub Updater enables WordPress to obtain plugin updates directly from GitHub Releases, rather than the WordPress.org repository. The implementation is optimized for SiteOrigin-maintained plugins, but the source code is public and may be adapted for other projects.

## What It Does

* Queries the GitHub Releases API for the most recent published release (non-draft, non-prerelease).  
* Compares the release tag to the `Version` header of the installed plugin.  
* When a higher version is detected, WordPress presents a standard update notice and installs the ZIP asset linked to that release.  
* For WordPress versions earlier than 5.8, or when an `Update URI` header is missing, the updater can fall back to a branch-based check.

## Integrating the Updater

1. **Add the submodule**

```
cd path/to/plugin
git submodule add https://github.com/siteorigin/github-updater.git github-updater
git submodule update --init --recursive
```

2. **Load and initialize**

```php
// Include the updater library.
require_once plugin_dir_path( __FILE__ ) . 'github-updater/updater.php';

// Instantiate the updater.
new SiteOrigin_Updater(
    __FILE__,                                  // Path to this main plugin file.
    'your-plugin-slug',                        // Plugin directory name (e.g., 'my-plugin').
    'your-github-owner/your-repository-name',  // GitHub owner and repository name (e.g., 'my-username/my-plugin').
    // Optional fourth argument: branch for legacy updates (default 'master').
);
```
    *   Replace `'your-plugin-slug'` with your plugin's directory name.
    *   Replace `'your-github-owner/your-repository-name'` with the GitHub owner (username or organization) followed by a slash and then the repository name. For example, if your repository URL is `https://github.com/my-username/my-cool-plugin`, this argument would be `'my-username/my-cool-plugin'`.
    *   The fourth parameter for the branch is optional and is used for the legacy update system (defaults to 'master').

3. **Define the `Update URI` header**

```php
/*
 * Plugin Name: Example Plugin
 * Version: 1.2.3
 * Update URI: https://github.com/your-github-owner/your-repository-name
 */
```

## Releasing a New Version

1. Increment the `Version` header.
2. Commit and push all changes.  
3. On GitHub, create a release whose tag matches the new version (for example, `1.2.4` or `v1.2.4`) and attach the installable ZIP file.  
4. WordPress installations will detect and apply the update during their next routine check.

## Legacy Update Mechanism

For environments running WordPress < 5.8 or lacking a valid `Update URI`, the updater retrieves the main plugin file from a designated branch (default `master`), compares version numbers, and supplies the update directly from that branch.

## Licence

GPL-3.0. Refer to the included `LICENSE` file for details.
