<?php
/**
 * SiteOrigin Updater
 * 
 * A utility for updating SiteOrigin plugins from GitHub.
 * 
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

class SiteOrigin_Updater {
	private $file;
	private $plugin_slug;
	private $owner;
	private $actual_repo_name;
	private $updates_branch = 'master';

	public function __construct( $file, $plugin_slug, $repo_name_with_owner, $updates_branch = 'master' ) {
		$this->file = $file;
		$this->plugin_slug = $plugin_slug;
		$this->updates_branch = $updates_branch;

		if (
			empty( $repo_name_with_owner ) ||
			strpos( $repo_name_with_owner, '/' ) === false
		) {
			throw new InvalidArgumentException(
				'SiteOrigin_Updater: Repository identifier (argument 3) must be a non-empty string in "owner/repository-name" format. E.g., "my-github-username/my-plugin-repo". Provided: ' . var_export($repo_name_with_owner, true)
			);
		}

		list($this->owner, $this->actual_repo_name) = explode('/', $repo_name_with_owner, 2);

		if (
			empty( $this->owner ) ||
			empty( $this->actual_repo_name )
		) {
			throw new InvalidArgumentException(
				'SiteOrigin_Updater: Owner and repository name segments cannot be empty in "owner/repository-name" format. Provided: "' . $repo_name_with_owner . '"'
			);
		}

		// Check if WordPress 5.8+ is available for Update URI system.
		global $wp_version;
		if (
			version_compare( $wp_version, '5.8', '>=' ) &&
			$this->has_update_uri()
		) {
			// Use modern WordPress 5.8+ Update URI system (avoids jQuery selector bug).
			add_filter( 'update_plugins_github.com', array( $this, 'check_for_github_update' ), 10, 4 );
		} else {
			// Fall back to legacy system for older WordPress versions.
			add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ), 15 );
		}
		
		add_filter( 'plugins_api', array( $this, 'plugin_api_call' ), 10, 3 );
	}

	public function check_for_update( $transient ) {
		$all_headers = $this->get_plugin_headers();
		$current_version = $this->get_current_version();

		if (
			! empty( $all_headers ) &&
			version_compare( $current_version, $all_headers['Version'], '<' )
		) {
			// There is a newer version available on GitHub (comparing local version to remote plugin header).
			$update = $this->get_plugin_data();
			$update->new_version = $all_headers['Version'];
			$update->stable_version = $all_headers['Version'];

			// Prevent potential warnings if we're the first thing to add data here.
			if ( ! is_object( $transient ) ) {
				$transient = new stdClass();
			}
			if ( ! isset( $transient->response ) ) {
				$transient->response = array();
			}

			$transient->response[ $update->slug ] = $update;
		}

		return $transient;
	}

	public function get_plugin_headers() {
		static $all_headers = array();

		if ( ! empty( $all_headers ) ) {
			return $all_headers;
		}

		// Fetch the remote plugin file from GitHub to read its header.
		$response = wp_remote_get( 'https://raw.githubusercontent.com/' . $this->owner . '/' . $this->actual_repo_name . '/' . $this->updates_branch . '/' . $this->plugin_slug . '.php' );

		if (
			is_wp_error( $response ) ||
			empty( $response['body'] )
		) {
			return false;
		}

		$file_data = $response['body'];
		$all_headers = array(
			'Name'        => 'Plugin Name',
			'PluginURI'   => 'Plugin URI',
			'Version'     => 'Version',
			'Description' => 'Description',
			'Author'      => 'Author',
			'AuthorURI'   => 'Author URI',
		);

		foreach ( $all_headers as $field => $regex ) {
			if (
				preg_match( '/^[ \t\/*#@]*' . preg_quote( $regex, '/' ) . ':(.*)$/mi', $file_data, $match ) &&
				! empty( $match[1] )
			) {
				$all_headers[ $field ] = _cleanup_header_comment( $match[1] );
			} else {
				$all_headers[ $field ] = '';
			}
		}

		return $all_headers;
	}

	/**
	 * Get and parse a markdown file from GitHub.
	 *
	 * @param string $file
	 *
	 * @return bool|string
	 */
	public function get_github_markdown( $file = 'readme.md' ) {
		$response = wp_remote_get( 'https://raw.githubusercontent.com/' . $this->owner . '/' . $this->actual_repo_name . '/' . $this->updates_branch . '/' . urlencode( $file ) );

		if (
			is_wp_error( $response ) ||
			empty( $response['body'] )
		) {
			return false;
		}

		if ( ! class_exists( 'Parsedown' ) ) {
			$parsedown_path = dirname( __FILE__ ) . '/Parsedown/Parsedown.php';
			if ( file_exists( $parsedown_path ) ) {
				require_once $parsedown_path;
			} else {
				// Fallback: return raw markdown if Parsedown not found.
				return $response['body'];
			}
		}

		if ( class_exists( 'Parsedown' ) ) {
			$parsedown = new Parsedown();
			return $parsedown->parse( $response['body'] );
		}

		// Fallback: return raw markdown.
		return $response['body'];
	}

	private function get_plugin_data() {
		$headers = $this->get_plugin_headers();
		if ( empty( $headers ) ) {
			return array();
		}
		$data = new stdClass();
		$data->slug = plugin_basename( $this->file );
		$data->id = $this->plugin_slug;
		$data->plugin_name = $headers['Name'];
		$data->name = $headers['Name'];
		$data->version = $headers['Version'];
		$data->author = $headers['Author'];
		$data->url = $headers['PluginURI'];
		$data->homepage = $headers['PluginURI'];
		$data->download_link = 'https://github.com/' . $this->owner . '/' . $this->actual_repo_name . '/archive/' . $this->updates_branch . '.zip';
		$data->package = 'https://github.com/' . $this->owner . '/' . $this->actual_repo_name . '/archive/' . $this->updates_branch . '.zip';
		
		// Add required fields for modal display.
		$data->short_description = ! empty( $headers['Description'] ) ? $headers['Description'] : 'A WordPress plugin by SiteOrigin.';
		$data->requires = '4.7';
		$data->tested = '6.8.1';
		$data->requires_php = '7.0.0';
		$data->last_updated = date( 'Y-m-d' );
		$data->added = date( 'Y-m-d' );
		
		// Set icon - use conventional naming with PNG preference.
		$icon_png_path = plugin_dir_path( $this->file ) . 'img/icon.png';
		
		if ( file_exists( $icon_png_path ) ) {
			$data->icons = array(
				'1x' => plugin_dir_url( $this->file ) . 'img/icon.png',
				'2x' => plugin_dir_url( $this->file ) . 'img/icon.png',
				'default' => plugin_dir_url( $this->file ) . 'img/icon.png',
			);
		} else {
			// Fallback to WordPress.org geopattern icon.
			$data->icons = array(
				'default' => "https://s.w.org/plugins/geopattern-icon/{$data->slug}.svg",
			);
		}
		
		// Set banner - only if a proper banner exists.
		$banner_path = plugin_dir_path( $this->file ) . 'img/banner.png';
		if ( file_exists( $banner_path ) ) {
			$data->banners = array(
				'low' => plugin_dir_url( $this->file ) . 'img/banner.png',
				'high' => plugin_dir_url( $this->file ) . 'img/banner.png',
			);
		}
		
		$data->sections = array(
			'description' => $this->get_github_markdown( 'readme.md' ),
			'changelog' => $this->get_github_markdown( 'changelog.md' ),
		);
		return $data;
	}

	/**
	 * Add all the plugin details to the Plugin API call.
	 *
	 * @return stdClass
	 */
	public function plugin_api_call( $def, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $def;
		}

		// Handle both legacy slug format and modern dirname format.
		$plugin_basename = plugin_basename( $this->file );
		$plugin_dirname = dirname( $plugin_basename );
		
		if ( ! isset( $args->slug ) ) {
			return $def;
		}

		// Check if this is our plugin (support both slug formats).
		if (
			$args->slug !== $plugin_basename &&
			$args->slug !== $plugin_dirname
		) {
			return $def;
		}

		// Get plugin data using the appropriate method.
		global $wp_version;
		if (
			version_compare( $wp_version, '5.8', '>=' ) &&
			$this->has_update_uri()
		) {
			// Use GitHub API for modern system.
			$data = $this->get_github_plugin_data();
		} else {
			// Use legacy method.
			$data = $this->get_plugin_data();
		}

		return empty( $data ) ? $def : $data;
	}

	/**
	 * Get plugin data from GitHub API (for WordPress 5.8+ system).
	 */
	private function get_github_plugin_data() {
		// Get plugin headers from local file.
		$plugin_data = get_file_data( $this->file, array(
			'Name' => 'Plugin Name',
			'PluginURI' => 'Plugin URI',
			'Version' => 'Version',
			'Description' => 'Description',
			'Author' => 'Author',
			'AuthorURI' => 'Author URI',
		) );

		// Get latest release info from GitHub Releases API.
		$response = wp_remote_get( 'https://api.github.com/repos/' . $this->owner . '/' . $this->actual_repo_name . '/releases/latest' );
		
		if ( is_wp_error( $response ) ) {
			return false;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $response ), true );
		
		$data = new stdClass();
		$data->slug = dirname( plugin_basename( $this->file ) );
		$data->plugin_name = $plugin_data['Name'];
		$data->name = $plugin_data['Name'];
		$data->version = ! empty( $release_data['tag_name'] ) ? ltrim( $release_data['tag_name'], 'v' ) : $plugin_data['Version'];
		$data->author = $plugin_data['Author'];
		$data->homepage = $plugin_data['PluginURI'];
		$data->download_link = ! empty( $release_data ) ? $this->get_github_download_url( $release_data ) : '';
		$data->requires = '4.7';
		$data->tested = '6.8.1';
		$data->requires_php = '7.0.0';
		
		// Add required fields for modal display.
		$data->short_description = ! empty( $plugin_data['Description'] ) ? $plugin_data['Description'] : 'A WordPress plugin by SiteOrigin.';
		$data->last_updated = ! empty( $release_data['published_at'] ) ? date( 'Y-m-d', strtotime( $release_data['published_at'] ) ) : date( 'Y-m-d' );
		$data->added = date( 'Y-m-d' );
		
		// Set icon - use conventional naming with PNG preference.
		$icon_png_path = plugin_dir_path( $this->file ) . 'img/icon.png';
		
		if ( file_exists( $icon_png_path ) ) {
			$data->icons = array(
				'1x' => plugin_dir_url( $this->file ) . 'img/icon.png',
				'2x' => plugin_dir_url( $this->file ) . 'img/icon.png',
				'default' => plugin_dir_url( $this->file ) . 'img/icon.png',
			);
		} else {
			// Fallback to WordPress.org geopattern icon.
			$data->icons = array(
				'default' => "https://s.w.org/plugins/geopattern-icon/{$data->slug}.svg",
			);
		}
		
		// Set banner - only if a proper banner exists.
		$banner_path = plugin_dir_path( $this->file ) . 'img/banner.png';
		if ( file_exists( $banner_path ) ) {
			$data->banners = array(
				'low' => plugin_dir_url( $this->file ) . 'img/banner.png',
				'high' => plugin_dir_url( $this->file ) . 'img/banner.png',
			);
		}
		
		$data->sections = array(
			'description' => $this->get_github_markdown( 'readme.md' ),
			'changelog' => $this->get_github_markdown( 'changelog.md' ),
		);

		return $data;
	}

	private function get_current_version() {
		$plugin_data = get_file_data( $this->file, array( 'Version' => 'Version' ) );
		return isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : '0.0.0';
	}

	/**
	 * Check if the plugin has an Update URI header pointing to GitHub.
	 */
	private function has_update_uri() {
		$plugin_data = get_file_data( $this->file, array( 'UpdateURI' => 'Update URI' ) );
		return (
			! empty( $plugin_data['UpdateURI'] ) &&
			strpos( $plugin_data['UpdateURI'], 'github.com' ) !== false
		);
	}

	/**
	 * WordPress 5.8+ Update URI system - check for GitHub updates.
	 */
	public function check_for_github_update( $update, $plugin_data, $plugin_file, $locales ) {
		// Only handle our plugin.
		if ( plugin_basename( $this->file ) !== $plugin_file ) {
			return $update;
		}

		// Skip if update already found.
		if ( ! empty( $update ) ) {
			return $update;
		}

		// Get latest release from GitHub Releases API.
		$response = wp_remote_get( 'https://api.github.com/repos/' . $this->owner . '/' . $this->actual_repo_name . '/releases/latest' );
		
		if (
			is_wp_error( $response ) ||
			wp_remote_retrieve_response_code( $response ) !== 200
		) {
			return false;
		}

		$release_data = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( empty( $release_data['tag_name'] ) ) {
			return false;
		}

		$new_version = ltrim( $release_data['tag_name'], 'v' );
		$current_version = $plugin_data['Version'];

		// Check if update is available (comparing local version to GitHub release tag).
		if ( version_compare( $current_version, $new_version, '<' ) ) {
			// Get icon data for the update notification.
			$icon_png_path = plugin_dir_path( $this->file ) . 'img/icon.png';
			$icons = array();
			
			if ( file_exists( $icon_png_path ) ) {
				$icons = array(
					'1x' => plugin_dir_url( $this->file ) . 'img/icon.png',
					'2x' => plugin_dir_url( $this->file ) . 'img/icon.png',
					'default' => plugin_dir_url( $this->file ) . 'img/icon.png',
				);
			}
			
			return array(
				'slug' => dirname( plugin_basename( $this->file ) ),
				'version' => $new_version,
				'url' => $release_data['html_url'],
				'package' => $this->get_github_download_url( $release_data ),
				'icons' => $icons,
				'tested' => '6.8.1',
			);
		}

		return false;
	}

	/**
	 * Get download URL from GitHub release data.
	 */
	private function get_github_download_url( $release_data ) {
		// Look for ZIP asset first.
		if ( ! empty( $release_data['assets'] ) ) {
			foreach ( $release_data['assets'] as $asset ) {
				if ( strpos( $asset['name'], '.zip' ) !== false ) {
					return $asset['browser_download_url'];
				}
			}
		}
		
		// Fallback to source ZIP.
		return $release_data['zipball_url'];
	}
}
