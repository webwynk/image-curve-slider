<?php
/**
 * Asset Enqueuing: frontend + admin scripts and styles.
 *
 * Frontend:
 *   - Only enqueues on singular pages/posts that contain [wynk_slider].
 *   - Enqueues vendor/three.min.js first, then public/js/wynk-slider.js (depends on it).
 *   - Enqueues public/css/wynk-slider.css.
 *
 * Admin:
 *   - Only enqueues on pages whose $hook contains 'wynk-curve-slider'.
 *   - Enqueues WordPress media library, wp-color-picker, Three.js, wynk-admin.js, admin.css.
 *   - Localizes wynkAdminData into the admin JS.
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WYNK_Assets
 *
 * Hooks into wp_enqueue_scripts (frontend) and admin_enqueue_scripts (admin)
 * to register and enqueue the plugin's scripts and styles.
 *
 * @since 1.0.0
 */
class WYNK_Assets {

	// =========================================================
	// Step 5.2 — Constructor: wire hooks
	// =========================================================

	/**
	 * Register the enqueue hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'wp_enqueue_scripts',    array( $this, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin' ) );
	}

	// =========================================================
	// Frontend asset enqueue
	// =========================================================

	/**
	 * Register and enqueue frontend scripts + styles.
	 *
	 * Runs on 'wp_enqueue_scripts'. Bails early unless:
	 *   1. We are on a singular page/post (not an archive, front page, etc.).
	 *   2. The page content contains at least one [wynk_slider] shortcode.
	 *
	 * Script load order (enforced via dependency array):
	 *   1. wynk-three         → vendor/three.min.js (Three.js r160 UMD)
	 *   2. wynk-curve-slider  → public/js/wynk-slider.js (depends on wynk-three)
	 *
	 * Both scripts are enqueued in the footer (true) so they don't block rendering.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_frontend(): void {
		// ── Guard: singular pages only ────────────────────────
		if ( ! is_singular() ) {
			return;
		}

		// ── Guard: page must contain the shortcode ────────────
		// get_the_content() returns raw content without do_shortcode().
		// has_shortcode() performs a regex match — lightweight check.
		$content = get_the_content();
		if ( ! has_shortcode( $content, 'wynk_slider' ) ) {
			return;
		}

		// ── Three.js r160 UMD (bundled, no CDN) ──────────────
		wp_register_script(
			'wynk-three',                               // handle
			WYNK_CS_URL . 'vendor/three.min.js',        // src
			array(),                                    // no deps
			'0.160.0',                                  // version (three.js r160)
			array( 'in_footer' => true )                // load in footer
		);

		// ── WebGL engine ──────────────────────────────────────
		wp_register_script(
			'wynk-curve-slider',
			WYNK_CS_URL . 'public/js/wynk-slider.js',
			array( 'wynk-three' ),                      // Three.js must load first
			WYNK_CS_VERSION,
			array( 'in_footer' => true )
		);

		wp_enqueue_script( 'wynk-three' );
		wp_enqueue_script( 'wynk-curve-slider' );

		// ── Frontend stylesheet ───────────────────────────────
		wp_enqueue_style(
			'wynk-curve-slider-css',
			WYNK_CS_URL . 'public/css/wynk-slider.css',
			array(),
			WYNK_CS_VERSION
		);
	}

	// =========================================================
	// Admin asset enqueue
	// =========================================================

	/**
	 * Register and enqueue admin scripts + styles.
	 *
	 * Runs on 'admin_enqueue_scripts'. Bails unless the current admin
	 * page hook contains 'wynk-curve-slider' (i.e. our menu page).
	 *
	 * The $hook for our page will be:
	 *   'toplevel_page_wynk-curve-slider'
	 * which always contains 'wynk-curve-slider'.
	 *
	 * Dependencies loaded (in order):
	 *   - wp_enqueue_media()        → WP media library JS (for wp.media())
	 *   - wp-color-picker           → WP bundled color picker
	 *   - wynk-three                → Three.js r160 (same handle as frontend)
	 *   - wynk-curve-slider         → public/js/wynk-slider.js (defines window.wynkCurve — REQUIRED for live preview)
	 *   - wynk-admin-js             → admin/js/wynk-admin.js
	 *   - wynk-admin-css            → admin/css/admin.css
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin( string $hook ): void {
		// Only load on our own admin page.
		if ( false === strpos( $hook, 'wynk-curve-slider' ) ) {
			return;
		}

		// ── WordPress media library ───────────────────────────
		// Required for wp.media() to be available in our admin JS.
		wp_enqueue_media();

		// ── WP color picker (bundled with WordPress) ──────────
		wp_enqueue_style( 'wp-color-picker' );

		// ── Three.js r160 (same bundled file as frontend) ─────
		// Using the same 'wynk-three' handle — WP deduplicates automatically.
		wp_enqueue_script(
			'wynk-three',
			WYNK_CS_URL . 'vendor/three.min.js',
			array(),
			'0.160.0',
			array( 'in_footer' => true )
		);

		// ── Frontend WebGL engine (REQUIRED for admin live preview) ───
		// wynk-slider.js defines window.wynkCurve — initInstance() and
		// destroy() — which the admin JS calls for the live preview panel.
		// Without this, window.wynkCurve is undefined and the preview is blank.
		wp_enqueue_script(
			'wynk-curve-slider',
			WYNK_CS_URL . 'public/js/wynk-slider.js',
			array( 'wynk-three' ),
			WYNK_CS_VERSION,
			array( 'in_footer' => true )
		);

		// ── Admin JS ──────────────────────────────────────────
		// Dependency order: jquery → wynk-curve-slider (engine) → wp-color-picker.
		// wynk-curve-slider already depends on wynk-three, so WP handles that.
		wp_enqueue_script(
			'wynk-admin-js',
			WYNK_CS_URL . 'admin/js/wynk-admin.js',
			array( 'jquery', 'wynk-curve-slider', 'wp-color-picker' ),
			WYNK_CS_VERSION,
			array( 'in_footer' => true )
		);

		// ── Admin stylesheet ──────────────────────────────────
		wp_enqueue_style(
			'wynk-admin-css',
			WYNK_CS_URL . 'admin/css/admin.css',
			array( 'wp-color-picker' ),                 // load after WP color-picker styles
			WYNK_CS_VERSION
		);

		// ── Localize admin JS data ────────────────────────────
		// window.wynkAdminData is available globally in wynk-admin.js.
		$upload_dir = wp_upload_dir();

		wp_localize_script(
			'wynk-admin-js',
			'wynkAdminData',
			array(
				// AJAX endpoint.
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),

				// Nonce for all wynk_cs_* AJAX actions.
				// Uses wp_create_nonce (not wp_nonce_field) since this is JS output.
				'nonce'     => wp_create_nonce( 'wynk_cs_nonce' ),

				// Plugin + uploads URLs (used for building asset paths if needed).
				'uploadsUrl' => esc_url_raw( $upload_dir['baseurl'] ),
				'pluginUrl'  => esc_url_raw( WYNK_CS_URL ),

				// URL for "Create new slider" link (used after deleting last card).
				'createUrl'  => esc_url_raw( admin_url( 'admin.php?page=wynk-curve-slider&view=create' ) ),

				// Translatable strings passed to JS (avoids hard-coded English in JS).
				'i18n'       => array(
					'mediaTitle'  => __( 'Select Slider Images', 'webwynk-curve-slider' ),
					'mediaButton' => __( 'Add to Slider', 'webwynk-curve-slider' ),
					'copied'      => __( 'Copied!', 'webwynk-curve-slider' ),
					'saveError'   => __( 'Save failed. Please try again.', 'webwynk-curve-slider' ),
					'deleteError' => __( 'Delete failed. Please try again.', 'webwynk-curve-slider' ),
					'netError'    => __( 'Network error — please try again.', 'webwynk-curve-slider' ),
				),
			)
		);
	}
}
