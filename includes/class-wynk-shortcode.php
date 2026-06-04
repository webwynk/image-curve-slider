<?php
/**
 * Shortcode: [wynk_slider]
 *
 * Renders the WebGL curved image slider HTML on the frontend.
 * Returns an empty string (no output) when:
 *   - No valid post ID is provided in the shortcode attributes.
 *   - The referenced post is not a 'wynk_slider' post type.
 *   - The slider is not published.
 *   - The slider has zero images in _wynk_images meta (Decision #5).
 *
 * Usage: [wynk_slider id="5"]
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WYNK_Shortcode
 *
 * Registers the [wynk_slider] shortcode and handles its output.
 * Depends on WYNK_CPT for meta key constants and data helpers.
 *
 * @since 1.0.0
 */
class WYNK_Shortcode {

	/**
	 * Register the shortcode on construction.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_shortcode( 'wynk_slider', array( $this, 'render' ) );
	}

	// =========================================================
	// Step 5.1 — Shortcode render callback
	// =========================================================

	/**
	 * Render the slider HTML and localize its data for the JS engine.
	 *
	 * @since  1.0.0
	 * @param  array|string $atts Shortcode attributes.
	 * @return string             HTML output, or '' on any validation failure.
	 */
	public function render( $atts ): string {
		$atts    = shortcode_atts( array( 'id' => 0 ), $atts, 'wynk_slider' );
		$post_id = absint( $atts['id'] );

		// ── Validate post ID ──────────────────────────────────
		if ( ! $post_id ) {
			return '';
		}

		$post = get_post( $post_id );

		if (
			! $post
			|| 'wynk_slider' !== $post->post_type
			|| 'publish'     !== $post->post_status
		) {
			return '';
		}

		// ── Read meta via WYNK_CPT helpers ────────────────────
		// Using the CPT class keeps meta key strings in one place.
		$cpt      = new WYNK_CPT();
		$images   = $cpt->get_image_data( $post_id );
		$settings = $cpt->get_settings( $post_id );

		// Decision #5: return '' if no images are stored.
		if ( empty( $images ) ) {
			return '';
		}

		// ── Mobile height cap (Section 11) ────────────────────
		// Applied via JS on init; we output the configured value here
		// and let the engine cap it client-side.
		$height   = absint( $settings['height'] );

		// ── Generate unique instance ID ───────────────────────
		// Unique per page load per shortcode placement.
		// Format: wynk-instance-{post_id}-{uniqid}
		$instance_id = 'wynk-instance-' . $post_id . '-' . uniqid( '', false );

		// ── Localize slider data for the JS engine ────────────
		// wp_localize_script merges successive calls for the same handle,
		// so calling it once per shortcode (one per page — Decision #2)
		// is safe and correct.
		//
		// The images array is already sanitised by WYNK_CPT::get_image_data().
		// The settings array is already cast/typed by WYNK_CPT::get_settings().
		wp_localize_script(
			'wynk-curve-slider',
			'wynkSliders',
			array(
				$instance_id => array(
					'images'   => $images,
					'settings' => array(
						'speed'          => (int) $settings['speed'],
						'gap'            => (int) $settings['gap'],
						'direction'      => (string) $settings['direction'],
						'height'         => $height,
						'autoplay'       => (bool) $settings['autoplay'],
						'pauseHover'     => (bool) $settings['pauseHover'],
						'desktopVisible' => (int) $settings['desktopVisible'],
						'tabletVisible'  => (int) $settings['tabletVisible'],
						'mobileVisible'  => (int) $settings['mobileVisible'],
					),
				),
			)
		);

		// ── Build HTML ────────────────────────────────────────
		// Use output buffering so the PHP/HTML template is clean and readable.
		ob_start();
		?>
		<div
			id="<?php echo esc_attr( $instance_id ); ?>"
			class="wynk-curve-slider-wrap"
			style="height:<?php echo absint( $height ); ?>px;"
			role="region"
			aria-label="<?php echo esc_attr( get_the_title( $post_id ) ); ?>"
		>
			<canvas class="wynk-curve-canvas" aria-hidden="true"></canvas>
		</div>
		<?php
		return ob_get_clean();
	}
}
