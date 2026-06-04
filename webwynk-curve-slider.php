<?php
/**
 * Plugin Name: Image Curve Slider by WebWynk
 * Plugin URI:  https://webwynk.com
 * Description: A 3D WebGL curved image slider with a full admin dashboard and [wynk_slider] shortcode.
 * Version:     1.0.3
 * Author:      WebWynk
 * Author URI:  https://webwynk.com
 * Text Domain: webwynk-curve-slider
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// ============================================================
// Security: Prevent direct file access.
// ============================================================
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// Step 1.2 — Constants
// ============================================================

/**
 * Plugin version string. Bump on every release.
 *
 * @since 1.0.0
 * @var string
 */
define( 'WYNK_CS_VERSION', '1.0.3' );

/**
 * Absolute filesystem path to the plugin root directory, with trailing slash.
 * Example: /var/www/html/wp-content/plugins/webwynk-curve-slider/
 *
 * @since 1.0.0
 * @var string
 */
define( 'WYNK_CS_PATH', plugin_dir_path( __FILE__ ) );

/**
 * Full URL to the plugin root directory, with trailing slash.
 * Example: https://example.com/wp-content/plugins/webwynk-curve-slider/
 *
 * @since 1.0.0
 * @var string
 */
define( 'WYNK_CS_URL', plugin_dir_url( __FILE__ ) );

// ============================================================
// Step 1.3 — Require class files (in dependency order)
// ============================================================

// Guard: bail gracefully if a required file is missing (e.g. incomplete upload).
$wynk_cs_required_files = array(
	WYNK_CS_PATH . 'includes/class-wynk-cpt.php',
	WYNK_CS_PATH . 'includes/class-wynk-shortcode.php',
	WYNK_CS_PATH . 'includes/class-wynk-assets.php',
	WYNK_CS_PATH . 'admin/admin-ajax.php',
	WYNK_CS_PATH . 'admin/admin-page.php',
);

foreach ( $wynk_cs_required_files as $wynk_cs_file ) {
	if ( ! file_exists( $wynk_cs_file ) ) {
		// Surface a clean admin notice instead of a fatal error.
		add_action(
			'admin_notices',
			static function () use ( $wynk_cs_file ) {
				printf(
					'<div class="notice notice-error"><p><strong>Image Curve Slider by WebWynk:</strong> %s</p></div>',
					esc_html(
						sprintf(
							/* translators: %s: absolute file path */
							__( 'Required file not found: %s — please re-install the plugin.', 'webwynk-curve-slider' ),
							$wynk_cs_file
						)
					)
				);
			}
		);
		return; // Stop loading — do not register any hooks with missing dependencies.
	}
	require_once $wynk_cs_file;
}

// Clean up the temporary variable from the global scope.
unset( $wynk_cs_required_files, $wynk_cs_file );

// ============================================================
// Step 1.4 — Lifecycle hooks
// ============================================================

/**
 * Activation hook.
 *
 * Stores the plugin version in wp_options (used for future upgrade routines)
 * and flushes rewrite rules so the CPT slug is registered correctly on
 * first activation without needing a manual Permalinks save.
 *
 * @since 1.0.0
 */
register_activation_hook(
	__FILE__,
	static function () {
		// add_option is a no-op if the option already exists, which is the
		// correct behaviour: we never want activation to overwrite an existing
		// version string set by a previous install.
		add_option( 'wynk_cs_version', WYNK_CS_VERSION );

		// Register CPT before flushing so the slug is available immediately.
		( new WYNK_CPT() )->register_post_type();

		flush_rewrite_rules();
	}
);

/**
 * Deactivation hook.
 *
 * Flushes rewrite rules so the CPT slug is cleanly removed from WordPress's
 * rewrite cache. No data is deleted — sliders and their meta are preserved.
 *
 * @since 1.0.0
 */
register_deactivation_hook(
	__FILE__,
	static function () {
		flush_rewrite_rules();
	}
);

// ============================================================
// Step 1.5 — Bootstrap plugin classes on plugins_loaded
// ============================================================

/**
 * Instantiate core plugin classes once all plugins and WP core are loaded.
 *
 * Using 'plugins_loaded' (priority 10) rather than 'init' ensures:
 * - Other plugins have run their own init routines (no race conditions).
 * - The full WP API surface is available (e.g. get_locale() for i18n).
 * - The REST API infrastructure is initialised before our REST routes fire.
 *
 * @since 1.0.0
 */
add_action(
	'plugins_loaded',
	static function () {
		// Load plugin text domain for translations.
		load_plugin_textdomain(
			'webwynk-curve-slider',
			false,
			dirname( plugin_basename( __FILE__ ) ) . '/languages'
		);

		// Core: registers the 'wynk_slider' custom post type and REST endpoint.
		new WYNK_CPT();

		// Frontend: registers the [wynk_slider] shortcode.
		new WYNK_Shortcode();

		// Assets: hooks wp_enqueue_scripts and admin_enqueue_scripts.
		new WYNK_Assets();
	},
	10 // explicit priority for clarity
);
