<?php
/*
Plugin Name: SiteOrigin CSS
Description: Powerful, simple CSS editing for WordPress. Visual controls & real-time previews for effortless site customization.
Version: 1.6.4
Author: SiteOrigin
Text Domain: so-css
Author URI: https://siteorigin.com
Plugin URI: https://siteorigin.com/css/
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html
*/

// Handle the legacy CSS editor that came with SiteOrigin themes
include plugin_dir_path( __FILE__ ) . 'inc/legacy.php';

define( 'SOCSS_VERSION', '1.6.4' );
define( 'SOCSS_JS_SUFFIX', '.min' );

/**
 * Class SiteOrigin_CSS The main class for the SiteOrigin CSS Editor
 */
class SiteOrigin_CSS {

	private $theme;
	private $snippet_paths;
	private $css_file;

	public function __construct() {
		$this->theme = basename( get_template_directory() );
		$this->snippet_paths = array();

		// Main header actions
		add_action( 'plugins_loaded', array( $this, 'set_plugin_textdomain' ) );

		global $wp_filesystem;

		if ( ! class_exists( 'wp_filesystem' ) ) {
			require_once ABSPATH . '/wp-admin/includes/file.php';
			WP_Filesystem();
		}

		// Priority 20 is necessary to ensure our CSS takes precedence.
		add_action( 'wp_head', array( $this, 'enqueue_css' ), 20 );

		add_filter( 'siteorigin_css_enqueue_css', array( $this, 'css_output_location' ), 9, 1 );

		// All the admin actions
		add_action( 'admin_init', array( $this, 'version_check' ) );
		add_action( 'admin_menu', array( $this, 'action_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'dequeue_admin_scripts' ), 19 );
		add_action( 'load-appearance_page_so_custom_css', array( $this, 'add_help_tab' ) );

		// Add the action links.
		add_action( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_action_links' ) );

		// The request to hide the getting started video
		add_action( 'wp_ajax_socss_hide_getting_started', array( $this, 'admin_action_hide_getting_started' ) );

		add_action( 'wp_ajax_socss_get_post_css', array( $this, 'admin_action_get_post_css' ) );
		add_action( 'wp_ajax_socss_get_revisions_list', array( $this, 'admin_action_get_revisions_list' ) );
		add_action( 'wp_ajax_socss_save_css', array( $this, 'admin_action_save_css' ) );

		if ( ! is_admin() ) {
			if ( isset( $_GET['so_css_preview'] )  ) {
				add_action( 'plugins_loaded', array( $this, 'disable_ngg_resource_manager' ) );
				add_filter( 'show_admin_bar', '__return_false' );
				add_filter( 'wp_enqueue_scripts', array( $this, 'enqueue_inspector_scripts' ) );
				add_filter( 'wp_footer', array( $this, 'inspector_templates' ) );

				// We'll be grabbing all the enqueued scripts and outputting them
				add_action( 'wp_enqueue_scripts', array( $this, 'inline_inspector_scripts' ), 100 );
			}
		} elseif ( ! class_exists( 'SiteOrigin_Installer' ) ) {
			include plugin_dir_path( __FILE__ ) . 'inc/installer/siteorigin-installer.php';
			if (
				! class_exists( 'SiteOrigin_Panels' ) &&
				! class_exists( 'SiteOrigin_Widgets_Bundle' ) &&
				! class_exists( 'SiteOrigin_Premium' )
			) {
				add_filter( 'siteorigin_add_installer', array( $this, 'manage_installer' ) );
			}
		}

		register_uninstall_hook( __FILE__, array( 'SiteOrigin_CSS', 'uninstall' ) );
	}

	/**
	 * Get a singleton of the SiteOrigin CSS.
	 *
	 * @return SiteOrigin_CSS
	 */
	public static function single() {
		static $single;

		if ( empty( $single ) ) {
			$single = new SiteOrigin_CSS();
		}

		return $single;
	}

	public function manage_installer( $status ) {
		// If the user hasn't enabled/disabled the installer, disable it by default.
		if ( empty( get_option( 'siteorigin_installer' ) ) ) {
			$status = false;
		}

		return $status;
	}

	/**
	 * Retrieve the current custom CSS for a given theme and post id combination.
	 *
	 * @param $theme string The name of the theme for which to retrieve custom CSS.
	 * @param $post_id int The ID of the specific post for which to retrieve custom CSS.
	 *
	 * @return string The custom CSS for the theme and post ID combination.
	 */
	public function get_custom_css( $theme, $post_id = null ) {
		$css_key = 'siteorigin_custom_css[' . $theme . ']';

		if ( empty( $post_id ) && WP_Filesystem() ) {
			$custom_css_file = apply_filters( 'siteorigin_custom_css_file', false );

			if (
				! empty( $custom_css_file ) &&
				! empty( $custom_css_file['file'] )
			) {
				// Did we previously load the CSS file? If not, load it.
				if ( empty( $this->css_file ) || isset( $_POST['siteorigin_custom_css'] ) ) {
					global $wp_filesystem;

					// If custom file doesn't exist, create it.
					if ( ! $wp_filesystem->exists( $custom_css_file['file'] ) ) {
						$wp_filesystem->touch( $custom_css_file['file'] );
					}

					if ( empty( get_option( 'siteorigin_custom_file' ) ) ) {
						update_option( 'siteorigin_custom_file', true, true );
					}

					if ( $wp_filesystem->is_writable( $custom_css_file['file'] ) ) {
						$this->css_file = $wp_filesystem->get_contents( $custom_css_file['file'] );
					}
				}

				return $this->css_file;
			} elseif ( ! empty( get_option( 'siteorigin_custom_file' ) ) ) {
				// If the custom file filter was previously active we need to
				// generate the global CSS file to avoid no CSS outputting
				// without modification.
				delete_option( 'siteorigin_custom_file', true );
				$css_file_path = $this->get_css_file_name( $theme );

				global $wp_filesystem;
				$wp_filesystem->put_contents(
					$css_file_path,
					get_option( $css_key, '' )
				);
			}
		}

		if ( ! empty( $post_id ) ) {
			return get_post_meta( $post_id, $css_key, true );
		}

		return get_option( $css_key, '' );
	}

	/**
	 * Save custom CSS for a given theme and post id combination.
	 *
	 * @param $custom_css string The custom CSS to save.
	 * @param $theme string The name of the theme for which to save custom CSS.
	 * @param $post_id int The ID of the specific post for which to save custom CSS.
	 *
	 * @return bool Whether or not saving the custom CSS was successful.
	 */
	public function save_custom_css( $custom_css, $theme, $post_id = null ) {
		$css_key = 'siteorigin_custom_css[' . $theme . ']';

		if ( empty( $post_id ) ) {
			$current = get_option( $css_key );

			if ( $current === false ) {
				return add_option( $css_key, $custom_css, '', 'no' );
			} else {
				return update_option( $css_key, $custom_css );
			}
		}

		if ( metadata_exists( 'post', $post_id, $css_key ) ) {
			return update_post_meta( $post_id, $css_key, $custom_css );
		}

		return add_post_meta( $post_id, $css_key, $custom_css );
	}

	/**
	 * Returns the file name of the CSS file we're editing.
	 *
	 * @param null $post_id
	 */
	public function get_css_file_name( $theme, $post_id = null ) {
		global $wp_filesystem;
		$upload_dir = wp_upload_dir();
		$upload_dir_path = $upload_dir['basedir'] . '/so-css/';

		if ( ! $wp_filesystem->is_dir( $upload_dir_path ) ) {
			$wp_filesystem->mkdir( $upload_dir_path );
		}

		$css_file_name = 'so-css-' . $theme . ( ! empty( $post_id ) ? '_' . $post_id : '' );

		return $upload_dir_path . $css_file_name . '.css';
	}

	/**
	 * Save custom CSS for a given theme and post id combination to a file in the uploads directory to allow for caching.
	 *
	 * @param null $post_id
	 */
	public function save_custom_css_file( $custom_css, $theme, $post_id = null ) {
		if ( WP_Filesystem() ) {
			global $wp_filesystem;
			$css_file_path = apply_filters( 'siteorigin_custom_css_file', false );

			if (
				empty( $css_file_path ) ||
				empty( $css_file_path['file'] ) ||
				! $wp_filesystem->is_writable( $css_file_path['file'] )
			) {
				$css_file_path = $this->get_css_file_name( $theme, $post_id );

				if ( file_exists( $css_file_path ) ) {
					$wp_filesystem->delete( $css_file_path );
				}
			} else {
				$css_file_path = $css_file_path['file'];
				$this->css_file = $custom_css;
			}

			$wp_filesystem->put_contents(
				$css_file_path,
				$custom_css
			);
		}
	}

	/**
	 * Retrieve the previous revisions of custom CSS for a given theme and post id combination.
	 *
	 * @param $theme string The name of the theme for which to retrieve custom CSS revisions.
	 * @param $post_id int The ID of the specific post for which to retrieve custom CSS revisions.
	 *
	 * @return array The custom CSS revisions for the theme and post ID combination.
	 */
	public function get_custom_css_revisions( $theme, $post_id = null ) {
		$css_key = 'siteorigin_custom_css_revisions[' . $theme . ']';

		if ( empty( $post_id ) ) {
			return get_option( $css_key, '' );
		}

		return get_post_meta( $post_id, $css_key, true );
	}

	/**
	 * Adds a custom CSS revision for a given theme and post id combination.
	 *
	 * @param $custom_css string The custom CSS to add as a revision.
	 * @param $theme string The name of the theme for which to save custom CSS.
	 * @param $post_id int The ID of the specific post for which to save custom CSS.
	 *
	 * @return bool Whether or not adding the custom CSS revision was successful.
	 */
	public function add_custom_css_revision( $custom_css, $theme, $post_id = null ) {
		$revisions = $this->get_custom_css_revisions( $this->theme, $post_id );

		$css_key = 'siteorigin_custom_css_revisions[' . $theme . ']';

		if ( empty( $revisions ) ) {
			$revisions = array();

			if ( empty( $post_id ) ) {
				add_option( $css_key, $revisions, '', 'no' );
			} else {
				add_post_meta( $post_id, $css_key, $revisions );
			}
		}
		$revisions[ time() ] = $custom_css;

		// Sort the revisions and cut off any old ones.
		krsort( $revisions );
		$revisions = array_slice( $revisions, 0, 15, true );

		if ( empty( $post_id ) ) {
			return update_option( $css_key, $revisions );
		}

		return update_post_meta( $post_id, $css_key, $revisions );
	}

	/**
	 * Enqueue or print inline CSS.
	 */
	public function enqueue_css() {
		$this->enqueue_custom_css( $this->theme );

		if ( is_singular() ) {
			$this->enqueue_custom_css( $this->theme, get_the_ID() );
		}
	}

	/**
	 * Changes the CSS output location based on user settings.
	 *
	 * This method retrieves the CSS output location setting from the
	 * `so_css_output_location` option and returns true if the setting is
	 * set to 'file'. Otherwise false is returned.
	 *
	 * @param string|null $location The unused default location value.
	 *
	 * @return bool True if the CSS should be output in a dedicated file.
	 */
	public function css_output_location( $location = null ) {
		$output_location =  get_option(
			'so_css_output_location',
			'file'
		);

		return $output_location === 'file';
	}

	/**
	 * Enqueue the custom CSS for the given theme and post id combination.
	 *
	 * @param $theme string The name of the theme for which to enqueue custom CSS.
	 * @param $post_id int The ID of the specific post for which to enqueue custom CSS.
	 */
	public function enqueue_custom_css( $theme, $post_id = null ) {
		$css_id = $theme . ( ! empty( $post_id ) ? '_' . $post_id : '' );

		if (
			empty( $_GET['so_css_preview'] ) &&
			! is_admin() &&
			apply_filters( 'siteorigin_css_enqueue_css', true )
		) {
			$custom_css_file = apply_filters( 'siteorigin_custom_css_file', array() );

			if ( ! empty( $post_id ) || empty( $custom_css_file ) ) {
				$upload_dir = wp_upload_dir();
				$upload_dir_path = $upload_dir['basedir'] . '/so-css/';
				$css_file_name = 'so-css-' . $css_id;
				$css_file_path = $upload_dir_path . $css_file_name . '.css';
				$css_file_url = $upload_dir['baseurl'] . '/so-css/' . $css_file_name . '.css';
			} elseif ( isset( $custom_css_file['url'] ) ) {
				$css_file_path = $custom_css_file['file'];
				$css_file_url = $custom_css_file['url'];
			}

			if ( ! empty( $css_file_path ) && file_exists( $css_file_path ) ) {
				wp_enqueue_style(
					'so-css-' . $css_id,
					set_url_scheme( $css_file_url ),
					array(),
					$this->get_latest_revision_timestamp()
				);
			}
		} else {
			$custom_css = $this->get_custom_css( $theme, $post_id );
			// We just need to enqueue a dummy style
			if ( ! empty( $custom_css ) ) {
				echo "<style id='" . sanitize_html_class( $css_id ) . "-custom-css' class='siteorigin-custom-css' type='text/css'>\n";
				echo self::sanitize_css( $custom_css ) . "\n";
				echo "</style>\n";
			}
		}
	}

	public function set_plugin_textdomain() {
		load_plugin_textdomain( 'so-css', false, plugin_dir_path( __FILE__ ) . 'lang/' );
	}

	/**
	 * Action to run on the admin action.
	 */
	public function action_admin_menu() {
		add_theme_page(
			esc_html__( 'Custom CSS', 'so-css' ),
			esc_html__( 'Custom CSS', 'so-css' ),
			'edit_theme_options',
			'so_custom_css',
			array( $this, 'display_admin_page' )
		);

		if ( current_user_can( 'edit_theme_options' ) && isset( $_POST['siteorigin_custom_css'] ) ) {
			check_admin_referer( 'custom_css', '_sononce' );

			// Sanitize CSS input. Should keep most tags, apart from script and style tags.
			$custom_css = self::sanitize_css( filter_input( INPUT_POST, 'siteorigin_custom_css' ) );
			$socss_post_id = filter_input( INPUT_GET, 'socss_post_id', FILTER_VALIDATE_INT );

			if ( empty( $this->css_file ) ) {
				$current = $this->get_custom_css( $this->theme, $socss_post_id );
				$this->save_custom_css( $custom_css, $this->theme, $socss_post_id );
			} else {
				$current = $this->css_file;
			}

			// If this has changed, then add a revision.
			if ( $current != $custom_css ) {
				$this->add_custom_css_revision( $custom_css, $this->theme, $socss_post_id );

				$this->save_custom_css_file( $custom_css, $this->theme, $socss_post_id );
			}

			// Update Editor Theme.
			if (
				$_POST['so_css_editor_theme'] == 'neat' ||
				$_POST['so_css_editor_theme'] == 'ambiance'
			) {
				update_option(
					'so_css_editor_theme',
					sanitize_text_field( $_POST['so_css_editor_theme'] )
				);
			}

			if (
				! empty( $_POST['so_css_output_location'] ) &&
				(
					$_POST['so_css_output_location'] === 'file' ||
					$_POST['so_css_output_location'] === 'inline'
				)
			) {
				update_option(
					'so_css_output_location',
					sanitize_text_field( $_POST['so_css_output_location'] )
				);
			}
		}
	}

	/**
	 * Display the help tab
	 */
	public function add_help_tab() {
		$screen = get_current_screen();
		$screen->add_help_tab( array(
			'id'      => 'custom-css',
			'title'   => esc_html__( 'Custom CSS', 'so-css' ),
			'content' => '<p>'
						 . sprintf( esc_html__( "SiteOrigin CSS adds any custom CSS you enter here into your site's header. ", 'so-css' ) )
						 . esc_html__( "These changes will persist across updates so it's best to make all your changes here. ", 'so-css' )
						 . '</p>',
		) );
	}

	public function enqueue_admin_scripts( $page ) {
		if ( $page != 'appearance_page_so_custom_css' ) {
			return;
		}

		// Core WordPress stuff that we use
		wp_enqueue_media();

		global $wp_version;

		if ( version_compare( $wp_version, '4.9', '>=' ) && wp_get_current_user()->syntax_highlighting ) {
			wp_enqueue_code_editor(
				array(
					'type' => 'css',
					'codemirror' => array(
						'lint' => true,
					),
				)
			);
		} else {
			$this->enqueue_fallback_codemirror();
		}
		wp_enqueue_style( 'socss-codemirror-theme-neat', plugin_dir_url( __FILE__ ) . 'lib/codemirror/theme/neat.css', array(), SOCSS_VERSION );
		wp_enqueue_style( 'socss-codemirror-theme-ambiance', plugin_dir_url( __FILE__ ) . 'lib/codemirror/theme/ambiance.css', array(), SOCSS_VERSION );

		// Enqueue the scripts for theme CSS processing
		wp_enqueue_script( 'siteorigin-css-parser-lib', plugin_dir_url( __FILE__ ) . 'js/css' . SOCSS_JS_SUFFIX . '.js', array( 'jquery' ), SOCSS_VERSION );

		// There are conflicts between CSS linting and the built in WordPress color picker, so use something else
		wp_enqueue_style( 'siteorigin-custom-css-minicolors', plugin_dir_url( __FILE__ ) . 'lib/minicolors/jquery.minicolors.css', array(), '2.1.7' );
		wp_enqueue_script( 'siteorigin-custom-css-minicolors', plugin_dir_url( __FILE__ ) . 'lib/minicolors/jquery.minicolors' . SOCSS_JS_SUFFIX . '.js', array( 'jquery' ), '2.1.7' );

		// URI parsing for preview navigation
		wp_enqueue_script( 'siteorigin-uri', plugin_dir_url( __FILE__ ) . 'js/URI' . SOCSS_JS_SUFFIX . '.js', array(), SOCSS_VERSION, true );

		// All the custom SiteOrigin CSS stuff
		wp_enqueue_script( 'siteorigin-custom-css', plugin_dir_url( __FILE__ ) . 'js/editor' . SOCSS_JS_SUFFIX . '.js', array( 'jquery', 'underscore', 'backbone' ), SOCSS_VERSION, true );
		wp_enqueue_style( 'siteorigin-custom-css', plugin_dir_url( __FILE__ ) . 'css/admin.css', array( ), SOCSS_VERSION );

		// Pretty confusing, but it seems we should be using `home_url` and NOT `site_url`
		// as described here => https://wordpress.stackexchange.com/a/50605
		$init_url = home_url();

		if ( ! empty( $socss_post_id ) && is_int( $socss_post_id ) ) {
			$init_url = set_url_scheme( get_permalink( $socss_post_id ) );
		}

		$open_visual_editor = ! empty( $_REQUEST['open_visual_editor'] );

		$home_url = add_query_arg( 'so_css_preview', '1', $init_url );

		wp_localize_script( 'siteorigin-custom-css', 'socssOptions', array(
			'homeURL' => $home_url,
			'postCssUrlRoot' => wp_nonce_url( admin_url( 'admin-ajax.php?action=socss_get_post_css' ), 'get_post_css' ),
			'getRevisionsListAjaxUrl' => wp_nonce_url( admin_url( 'admin-ajax.php?action=socss_get_revisions_list' ), 'get_revisions_list' ),
			'ajaxurl' => wp_nonce_url( admin_url( 'admin-ajax.php' ), 'so-css-ajax' ),
			'openVisualEditor' => $open_visual_editor,

			'propertyControllers' => apply_filters( 'siteorigin_css_property_controllers', $this->get_property_controllers() ),

			'loc' => array(
				'unchanged'    => esc_html__( 'Unchanged', 'so-css' ),
				'select'       => esc_html__( 'Select', 'so-css' ),
				'select_image' => esc_html__( 'Select Image', 'so-css' ),
				'leave'        => esc_html__( 'Are you sure you want to leave without saving?', 'so-css' ),
			),
		) );

		// This is for the templates required by the CSS editor. Ideally this would be out in the footer, but we need
		// it earlier for dependent scripts.
		include plugin_dir_path( __FILE__ ) . 'tpl/js-templates.php';
	}

	// Handles loading the fallback version of CodeMirror.
	public function enqueue_fallback_codemirror() {
		// Enqueue the codemirror scripts. Call Underscore and Backbone dependencies so they're enqueued first to prevent conflicts.
		wp_enqueue_script( 'socss-codemirror', plugin_dir_url( __FILE__ ) . 'lib/codemirror/lib/codemirror' . SOCSS_JS_SUFFIX . '.js', array( 'underscore', 'backbone' ), '5.2.0' );
		wp_enqueue_script( 'socss-codemirror-mode-css', plugin_dir_url( __FILE__ ) . 'lib/codemirror/mode/css/css' . SOCSS_JS_SUFFIX . '.js', array(), '5.2.0' );

		// Add in all the linting libs
		wp_enqueue_script( 'socss-codemirror-lint', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/lint/lint' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror' ), '5.2.0' );
		wp_enqueue_script( 'socss-codemirror-lint-css', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/lint/css-lint' . SOCSS_JS_SUFFIX . '.js', array(
			'socss-codemirror',
			'socss-codemirror-lint-css-lib',
		), '5.2.0' );
		wp_enqueue_script( 'socss-codemirror-lint-css-lib', plugin_dir_url( __FILE__ ) . 'js/csslint' . SOCSS_JS_SUFFIX . '.js', array(), '0.10.0' );

		// The CodeMirror autocomplete library
		wp_enqueue_script( 'socss-codemirror-show-hint', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/hint/show-hint' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror' ), '5.2.0' );

		// CodeMirror search and dialog addons
		wp_enqueue_script( 'socss-codemirror-dialog', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/dialog/dialog' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror' ), '5.2.0' );

		wp_enqueue_script( 'socss-codemirror-search', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/search' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror' ), '5.37.0' );
		wp_enqueue_script( 'socss-codemirror-search-searchcursor', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/searchcursor' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror', 'socss-codemirror-search' ), '5.37.0' );
		wp_enqueue_script( 'socss-codemirror-search-match-cursor', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/match-highlighter' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror', 'socss-codemirror-search' ), '5.37.0' );
		wp_enqueue_script( 'socss-codemirror-search-matchesonscrollbar', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/matchesonscrollbar' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror', 'socss-codemirror-search' ), '5.37.0' );
		wp_enqueue_script( 'socss-codemirror-scroll-annotatescrollbar', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/scroll/annotatescrollbar' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror', 'socss-codemirror-search', 'socss-codemirror-search-matchesonscrollbar' ), '5.37.0' );
		wp_enqueue_script( 'socss-codemirror-jump-to-line', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/jump-to-line' . SOCSS_JS_SUFFIX . '.js', array( 'socss-codemirror', 'socss-codemirror-search' ), '5.37.0' );

		// All the CodeMirror styles
		wp_enqueue_style( 'socss-codemirror', plugin_dir_url( __FILE__ ) . 'lib/codemirror/lib/codemirror.css', array(), '5.2.0' );
		wp_enqueue_style( 'socss-codemirror-lint-css', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/lint/lint.css', array(), '5.2.0' );
		wp_enqueue_style( 'socss-codemirror-show-hint', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/hint/show-hint.css', array(), '5.2.0' );
		wp_enqueue_style( 'socss-codemirror-dialog', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/dialog/dialog.css', '5.2.0' );
		wp_enqueue_style( 'socss-codemirror-search-matchesonscrollbar', plugin_dir_url( __FILE__ ) . 'lib/codemirror/addon/search/matchesonscrollbar.css', array(), '5.37.0' );
	}

	public function dequeue_admin_scripts( $page ) {
		if ( $page != 'appearance_page_so_custom_css' ) {
			return;
		}

		// Dequeue the core WordPress color picker on the custom CSS page.
		// This script causes conflicts and other plugins seem to be enqueueing it on the SO CSS admin page.
		wp_dequeue_script( 'wp-color-picker' );
		wp_dequeue_style( 'wp-color-picker' );
	}

	/**
	 * Get all the available property controllers
	 */
	public function get_property_controllers() {
		return include plugin_dir_path( __FILE__ ) . 'inc/controller-config.php';
	}

	public function plugin_action_links( $links ) {
		if ( isset( $links['edit'] ) ) {
			unset( $links['edit'] );
		}
		$links['css_editor'] = '<a href="' . admin_url( 'themes.php?page=so_custom_css' ) . '">' . esc_html__( 'CSS Editor', 'so-css' ) . '</a>';
		$links['support'] = '<a href="https://siteorigin.com/thread/" target="_blank">' . esc_html__( 'Support', 'so-css' ) . '</a>';

		if ( apply_filters( 'siteorigin_premium_upgrade_teaser', true ) && ! defined( 'SITEORIGIN_PREMIUM_VERSION' ) ) {
			$links['addons'] = '<a href="https://siteorigin.com/downloads/premium/?featured_addon=plugin/web-font-selector" style="color: #3db634" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Addons', 'so-css' ) . '</a>';
		}

		return $links;
	}

	public function display_admin_page() {
		$socss_post_id = filter_input( INPUT_GET, 'socss_post_id', FILTER_VALIDATE_INT );
		$theme = filter_input( INPUT_GET, 'theme' );
		$time = filter_input( INPUT_GET, 'time', FILTER_VALIDATE_INT );

		$page_title = esc_html__( 'SiteOrigin CSS', 'so-css' );
		$theme_obj = wp_get_theme();
		$theme_name = $theme_obj->get( 'Name' );
		$editor_description = sprintf( esc_html__( 'Changes apply to %s and its child themes', 'so-css' ), $theme_name );
		$save_button_label = esc_html__( 'Save CSS', 'so-css' );
		$form_save_url = admin_url( 'themes.php?page=so_custom_css' );

		if ( ! empty( $socss_post_id ) ) {
			$selected_post = get_post( $socss_post_id );

			$page_title = sprintf(
				esc_html__( 'Editing CSS for: %s', 'so-css' ),
				$selected_post->post_title
			);

			$editor_description = sprintf(
				esc_html__( 'Changes apply to the %s %s when the current theme is %s or its child themes', 'so-css' ),
				$selected_post->post_type,
				$selected_post->post_title,
				$theme_name
			);
			$post_type_obj = get_post_type_object( $selected_post->post_type );
			$post_type_labels = $post_type_obj->labels;
			$save_button_label = sprintf( esc_html__( 'Save %s CSS', 'so-css' ), $post_type_labels->singular_name );
			$form_save_url = add_query_arg( 'socss_post_id', urlencode( $socss_post_id ), $form_save_url );
		}
		$custom_css = $this->get_custom_css( $this->theme, $socss_post_id );
		$custom_css_revisions = $this->get_custom_css_revisions( $this->theme, $socss_post_id );
		$current_revision = 0;

		if ( ! empty( $theme ) && $theme == $this->theme && ! empty( $time ) && ! empty( $custom_css_revisions[ $time ] ) ) {
			$current_revision = $time;
			$custom_css = $custom_css_revisions[ $time ];
		}

		if ( ! empty( $current_revision ) ) {
			$save_button_label = esc_html__( 'Revert to this revision', 'so-css' );
		}

		if ( ! empty( $custom_css_revisions ) ) {
			krsort( $custom_css_revisions );
		}

		$theme = basename( get_template_directory() );

		$editor_theme = get_option( 'so_css_editor_theme', 'neat' );
		$output_location = get_option( 'so_css_output_location', 'file' );

		include plugin_dir_path( __FILE__ ) . 'tpl/page.php';
	}

	public function display_teaser() {
		return apply_filters( 'siteorigin_premium_upgrade_teaser', true ) &&
			   ! defined( 'SITEORIGIN_PREMIUM_VERSION' );
	}

	/**
	 *  Generates the url to edit the custom CSS for a post.
	 */
	public function get_edit_css_link( $post ) {
		$url = admin_url( 'themes.php?page=so_custom_css' );

		if ( ! is_int( $post ) ) {
			$post = get_post( $post );
			$post = $post->ID;
		}

		return empty( $post ) ? $url : add_query_arg( array(
			'socss_post_id' => urlencode( $post ),
			'open_visual_editor' => 1,
		), $url );
	}

	public function admin_action_hide_getting_started() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'hide' ) ) {
			return;
		}

		$user = wp_get_current_user();

		if ( ! empty( $user ) ) {
			update_user_meta( $user->ID, 'socss_hide_gs', true );
		}
	}

	/**
	 * Retrieves the post specific CSS for the supplied postId.
	 */
	public function admin_action_get_post_css() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'get_post_css' ) ) {
			wp_die(
				esc_html__( 'The supplied nonce is invalid.', 'so-css' ),
				esc_html__( 'Invalid nonce.', 'so-css' ),
				403
			);
		}

		$post_id = filter_input( INPUT_GET, 'postId', FILTER_VALIDATE_INT );

		$current = $this->get_custom_css( $this->theme, $post_id );

		$url = empty( $post_id ) ? home_url() : set_url_scheme( get_permalink( $post_id ) );

		wp_send_json( array( 'css' => empty( $current ) ? '' : $current, 'postUrl' => $url ) );
	}

	/**
	 * Retrieves the past revisions of post specific CSS for the supplied postId.
	 */
	public function admin_action_get_revisions_list() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'get_revisions_list' ) ) {
			wp_die(
				esc_html__( 'The supplied nonce is invalid.', 'so-css' ),
				esc_html__( 'Invalid nonce.', 'so-css' ),
				403
			);
		}

		$post_id = filter_input( INPUT_GET, 'postId', FILTER_VALIDATE_INT );

		$this->custom_css_revisions_list( $this->theme, $post_id );

		wp_die();
	}

	/**
	 * Retrieves the past revisions of post specific CSS for the supplied postId.
	 */
	public function admin_action_save_css() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( $_GET['_wpnonce'], 'so-css-ajax' ) ) {
			wp_die(
				esc_html__( 'The supplied nonce is invalid.', 'so-css' ),
				esc_html__( 'Invalid nonce.', 'so-css' ),
				403
			);
		}

		if ( current_user_can( 'edit_theme_options' ) && isset( $_POST['css'] ) ) {
			// Sanitize CSS input. Should keep most tags, apart from script and style tags.
			$custom_css = self::sanitize_css( stripslashes( $_POST['css'] ) );

			if ( empty( $this->css_file ) ) {
				$current = $this->get_custom_css( $this->theme );
				$this->save_custom_css( $custom_css, $this->theme );
			} else {
				$current = $this->css_file;
			}

			// If this has changed, then add a revision.
			if ( $current != $custom_css ) {
				$this->add_custom_css_revision( $custom_css, $this->theme );
				$this->save_custom_css_file( $custom_css, $this->theme );

				// Output the full revisions list.
				$this->custom_css_revisions_list( $this->theme );
			}
		}
		wp_die();
	}

	public function custom_css_revisions_list( $theme, $post_id = null, $current_revision = null ) {
		$revisions = $this->get_custom_css_revisions( $theme, $post_id );

		if ( is_array( $revisions ) && ! empty( $revisions ) ) {
			$i = 0;

			foreach ( $revisions as $time => $css ) {
				$is_current = ( empty( $current_revision ) && $i == 0 ) || ( ! empty( $current_revision ) && $time == $current_revision );
				$query_args = array( 'theme' => $theme, 'time' => $time, 'open_visual_editor' => false );

				if ( ! empty( $post_id ) ) {
					$query_args['socss_post_id'] = $post_id;
				}
				?>
				<li>
					<?php if ( ! $is_current ) { ?>
					<a href="<?php echo esc_url( add_query_arg( $query_args, admin_url( 'themes.php?page=so_custom_css' ) ) ); ?>"
					   class="load-css-revision">
					<?php } ?>
					<?php echo date( 'j F Y @ H:i:s', $time + get_option( 'gmt_offset' ) * 60 * 60 ); ?>
					<?php if ( ! $is_current ) { ?>
					</a>
					<?php } ?>
					(<?php printf( esc_html__( '%d chars', 'so-css' ), strlen( $css ) ); ?>)<?php if ( $i == 0 ) { ?> (<?php esc_html_e( 'Latest', 'so-css' ); ?>)<?php } ?>
				</li>
				<?php
				$i++;
			}
		} else {
			printf( '<em>%s</em>', esc_html__( 'No revisions yet.', 'so-css' ) );
		}
	}

	/**
	 *  Add one or more paths to the registered snippet paths
	 *
	 * @param string|array $path
	 */
	public function register_snippet_path( $path ) {
		$this->snippet_paths = array_merge( $this->snippet_paths, (array) $path );
	}

	/**
	 * Get all the available snippets
	 *
	 * @return array|bool
	 */
	public function get_snippets() {
		// Get the snippet paths
		$snippet_paths = apply_filters( 'siteorigin_css_snippet_paths', $this->snippet_paths );

		if ( empty( $snippet_paths ) ) {
			return array();
		}

		static $snippets = array();

		if ( ! empty( $snippets ) ) {
			return $snippets;
		}

		if ( ! WP_Filesystem() ) {
			return false;
		}
		global $wp_filesystem;

		foreach ( $snippet_paths as $path ) {
			foreach ( glob( $path . '/*.css' ) as $css_file ) {
				$data = get_file_data( $css_file, array(
					'Name'        => 'Name',
					'Description' => 'Description',
				) );

				// Get the CSS and strip out the first header
				$css = $wp_filesystem->get_contents( $css_file );
				$css = preg_replace( '!/\*.*?\*/!s', '', $css, 1 );

				$snippets[] = wp_parse_args( $data, array(
					'css' => str_replace( "\t", '  ', trim( $css ) ),
				) );
			}
		}

		usort( $snippets, array( $this, 'sort_snippet_callback' ) );

		return $snippets;
	}

	/**
	 * Sort snippets by name.
	 *
	 * @return int
	 */
	public static function sort_snippet_callback( $a, $b ) {
		return $a['Name'] > $b['Name'] ? 1 : - 1;
	}

	/**
	 * A very simple CSS sanitization function.
	 *
	 * @return string
	 */
	public static function sanitize_css( $css ) {
		return trim( strip_tags( $css ) );
	}

	/**
	 * Get all the available theme CSS
	 */
	public function get_theme_css() {
		$css = '';

		if ( file_exists( get_template_directory() . '/style.css' ) ) {
			$css .= file_get_contents( get_template_directory() . '/style.css' );
		}

		if ( is_child_theme() ) {
			$css .= file_get_contents( get_stylesheet_directory() . '/style.css' );
		}

		// Remove all CSS comments
		$regex = array(
			"`^([\t\s]+)`ism"                             => '',
			"`^\/\*(.+?)\*\/`ism"                         => '',
			"`(\A|[\n;]+)/\*[^*]*\*+(?:[^/*][^*]*\*+)*/`" => '$1',
			"`(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+`ism"       => "\n",
		);
		$css = preg_replace( array_keys( $regex ), $regex, $css );
		$css = preg_replace( '/\s+/', ' ', $css );

		return $css;
	}

	public function enqueue_inspector_scripts() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		wp_enqueue_style( 'dashicons' );

		wp_enqueue_script( 'siteorigin-css-parser-lib', plugin_dir_url( __FILE__ ) . 'js/css' . SOCSS_JS_SUFFIX . '.js', array( 'jquery' ), SOCSS_VERSION );

		wp_enqueue_script( 'siteorigin-css-sizes', plugin_dir_url( __FILE__ ) . 'js/jquery.sizes' . SOCSS_JS_SUFFIX . '.js', array( 'jquery' ), '0.33' );
		wp_enqueue_script( 'siteorigin-css-specificity', plugin_dir_url( __FILE__ ) . 'js/specificity' . SOCSS_JS_SUFFIX . '.js', array() );
		wp_enqueue_script( 'siteorigin-css-inspector', plugin_dir_url( __FILE__ ) . 'js/inspector' . SOCSS_JS_SUFFIX . '.js', array(
			'jquery',
			'underscore',
			'backbone',
		), SOCSS_VERSION, true );
		wp_enqueue_style( 'siteorigin-css-inspector', plugin_dir_url( __FILE__ ) . 'css/inspector.css', array(), SOCSS_VERSION );

		wp_localize_script( 'siteorigin-css-inspector', 'socssOptions', array() );
	}

	public function inspector_templates() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		include plugin_dir_path( __FILE__ ) . 'tpl/inspector-templates.php';
	}

	/**
	 * Change the stylesheets to all be inline
	 */
	public function inline_inspector_scripts() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		$regex = array(
			"`^([\t\s]+)`ism"                             => '',
			"`^\/\*(.+?)\*\/`ism"                         => '',
			"`(\A|[\n;]+)/\*[^*]*\*+(?:[^/*][^*]*\*+)*/`" => '$1',
			"`(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+`ism"       => "\n",
		);

		global $wp_styles;

		if ( empty( $wp_styles->queue ) ) {
			return;
		}

		// Certain themes serve their main CSS from a file that's different to
		// what's initially set.
		$theme_src_compatibility = array(
			'divi-style' => get_template_directory_uri() . '/style.min.css',
		);

		// Make each of the scripts inline
		foreach ( $wp_styles->queue as $handle ) {
			if ( $handle === 'siteorigin-css-inspector' || $handle === 'dashicons' ) {
				continue;
			}
			$style = $wp_styles->registered[ $handle ];

			if ( empty( $style->src ) || substr( $style->src, 0, 4 ) !== 'http' ) {
				continue;
			}

			if ( isset( $theme_src_compatibility[ $handle ] ) ) {
				$style->src = $theme_src_compatibility[ $handle ];
			}

			$response = wp_remote_get( $style->src );

			if ( is_wp_error( $response ) || $response['response']['code'] !== 200 || empty( $response['body'] ) ) {
				continue;
			}

			$css = $response['body'];

			$css = preg_replace( array_keys( $regex ), $regex, $css );

			?>
			<script type="text/css" class="socss-theme-styles"
					id="socss-inlined-style-<?php echo sanitize_html_class( $handle ); ?>">
				<?php echo strip_tags( $css ); ?>
			</script>
			<?php
		}
	}

	public function disable_ngg_resource_manager() {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		//The NextGen Gallery plugin does some weird interfering with the output buffer.
		define( 'NGG_DISABLE_RESOURCE_MANAGER', true );
	}

	private function get_latest_revision_timestamp() {
		$revisions = $this->get_custom_css_revisions( $this->theme );

		if ( empty( $revisions ) ) {
			return false;
		}
		krsort( $revisions );
		$revision_times = array_keys( $revisions );

		return $revision_times[0];
	}

	/**
	 * Checks and updates the plugin version.
	 *
	 * This method checks the current version of the plugin stored in the database.
	 * If no version is set, it checks if the site already had SO CSS installed by
	 * looking for custom CSS. If custom CSS is found, it sets the CSS output location
	 * to 'inline'; otherwise, it sets it to 'file'.
	 *
	 * It also updates the stored version if it is not set or if it is outdated.
	 * Additionally, it triggers the 'so_css_version_update' action when the version is updated.
	 *
	 * @return void
	 */
	public function version_check() {
		$version = get_option( 'so_css_version' );

		// If there's no version set or it's set to 1.6.0, check if 
		// this site already had SO CSS installed by checking for custom CSS.
		if ( empty( $version ) || $version === '1.6.0' ) {
			update_option( 'so_css_output_location', 'file' );
		}

		if ( empty( $version ) || version_compare( $version, SOCSS_VERSION, '<' ) ) {
			update_option( 'so_css_version', SOCSS_VERSION );

			do_action(
				'so_css_version_update',
				SOCSS_VERSION,
				$version
			);
		}
	}

	/**
	 * Uninstall hook to clean up plugin data.
	 *
	 * This method is called when the plugin is uninstalled. It deletes
	 * options related to the plugin from the WordPress database.
	 *
	 * Things like custom CSS are not deleted. This is to prevent data loss.
	 *
	 * @return void
	 */
	public static function uninstall() {
		$theme = basename( get_template_directory() );
		delete_option( 'so_css_version' );
		delete_option( 'so_css_editor_theme' );
		delete_option( 'so_css_output_location' );
		delete_option( 'siteorigin_custom_css_revisions[' . $theme . ']' );
	}
}

// Initialize the single
SiteOrigin_CSS::single();
