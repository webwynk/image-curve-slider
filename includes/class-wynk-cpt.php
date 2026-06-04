<?php
/**
 * Custom Post Type: wynk_slider
 *
 * Registers the 'wynk_slider' custom post type and exposes a read-only
 * REST endpoint used by the admin live preview.
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WYNK_CPT
 *
 * Responsible for:
 *  - Registering the 'wynk_slider' CPT on the 'init' action.
 *  - Registering meta keys used to store per-slider settings.
 *  - Exposing GET /wp-json/wynk/v1/slider/{id} for the admin preview panel.
 *
 * @since 1.0.0
 */
class WYNK_CPT {

	// =========================================================
	// Step 2.4 — Meta key constants
	// Using class constants keeps all key strings in one place;
	// change here and every reference updates automatically.
	// =========================================================

	/** @var string JSON-encoded array of attachment IDs. */
	const META_IMAGES = '_wynk_images';

	/** @var string Scroll speed — integer 5–150. */
	const META_SPEED = '_wynk_speed';

	/** @var string Curve intensity — integer 0–50. */
	const META_CURVE = '_wynk_curve';

	/** @var string Gap between images as a % — integer 0–50. */
	const META_GAP = '_wynk_gap';

	/** @var string Scroll direction — 'left' or 'right'. */
	const META_DIRECTION = '_wynk_direction';

	/** @var string Slider height in pixels — integer 200–800. */
	const META_HEIGHT = '_wynk_height';

	/** @var string Autoplay flag — integer 0 or 1. */
	const META_AUTOPLAY = '_wynk_autoplay';

	/** @var string Pause-on-hover flag — integer 0 or 1. */
	const META_PAUSE_HOVER = '_wynk_pause_hover';

	/** @var string Background colour — sanitized hex string e.g. '#ffffff'. */
	const META_BG_COLOR = '_wynk_bg_color';

	/** @var string Desktop visible images — integer 3–12. */
	const META_DESKTOP_VISIBLE = '_wynk_desktop_visible';

	/** @var string Tablet visible images — integer 2–8. */
	const META_TABLET_VISIBLE = '_wynk_tablet_visible';

	/** @var string Mobile visible images — integer 1–5. */
	const META_MOBILE_VISIBLE = '_wynk_mobile_visible';

	// =========================================================
	// Default values — used when meta is absent or empty.
	// =========================================================

	/** @var array<string, mixed> */
	private static $meta_defaults = array(
		self::META_IMAGES          => '[]',
		self::META_SPEED           => 30,
		self::META_CURVE           => 12,
		self::META_GAP             => 10,
		self::META_DIRECTION       => 'left',
		self::META_HEIGHT          => 400,
		self::META_AUTOPLAY        => 1,
		self::META_PAUSE_HOVER     => 1,
		self::META_BG_COLOR        => '#ffffff',
		self::META_DESKTOP_VISIBLE => 8,
		self::META_TABLET_VISIBLE  => 5,
		self::META_MOBILE_VISIBLE  => 3,
	);

	// =========================================================
	// Step 2.1 — Constructor / hook registration
	// =========================================================

	/**
	 * Wire up WordPress actions.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_action( 'init',         array( $this, 'register_post_type' ) );
		add_action( 'init',         array( $this, 'register_meta' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
	}

	// =========================================================
	// Step 2.2 — Register the 'wynk_slider' CPT
	// =========================================================

	/**
	 * Register the 'wynk_slider' custom post type.
	 *
	 * Deliberately kept non-public and hidden from the default WP admin menu
	 * because we provide our own top-level menu page. The CPT is exposed in
	 * the admin UI (show_ui: true) so WP's capabilities system works correctly.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_post_type(): void {
		$labels = array(
			'name'                  => _x( 'Wynk Sliders', 'post type general name', 'webwynk-curve-slider' ),
			'singular_name'         => _x( 'Wynk Slider', 'post type singular name', 'webwynk-curve-slider' ),
			'add_new'               => __( 'Add New', 'webwynk-curve-slider' ),
			'add_new_item'          => __( 'Add New Slider', 'webwynk-curve-slider' ),
			'edit_item'             => __( 'Edit Slider', 'webwynk-curve-slider' ),
			'new_item'              => __( 'New Slider', 'webwynk-curve-slider' ),
			'view_item'             => __( 'View Slider', 'webwynk-curve-slider' ),
			'all_items'             => __( 'All Sliders', 'webwynk-curve-slider' ),
			'search_items'          => __( 'Search Sliders', 'webwynk-curve-slider' ),
			'not_found'             => __( 'No sliders found.', 'webwynk-curve-slider' ),
			'not_found_in_trash'    => __( 'No sliders found in Trash.', 'webwynk-curve-slider' ),
			'menu_name'             => __( 'Wynk Sliders', 'webwynk-curve-slider' ),
			'name_admin_bar'        => __( 'Wynk Slider', 'webwynk-curve-slider' ),
		);

		$args = array(
			'labels'              => $labels,
			// Not publicly queryable — sliders are embedded via shortcode, not visited by URL.
			'public'              => false,
			'publicly_queryable'  => false,
			'show_ui'             => true,    // Needed for WP capability system to work correctly.
			'show_in_menu'        => false,   // We register our own top-level menu in admin-page.php.
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,   // We expose our own REST endpoint below.
			'query_var'           => false,
			'rewrite'             => false,
			'has_archive'         => false,
			'hierarchical'        => false,
			// Only the title is managed through our custom UI; content/thumbnail unused.
			'supports'            => array( 'title' ),
			'capability_type'     => 'post',
			'map_meta_cap'        => true,
		);

		register_post_type( 'wynk_slider', $args );
	}

	// =========================================================
	// Meta registration (improves REST / capability handling)
	// =========================================================

	/**
	 * Register all _wynk_* meta keys with the WordPress meta registry.
	 *
	 * Registering meta explicitly:
	 *  - Enables sanitization / auth callbacks at the framework level.
	 *  - Prevents accidental exposure via the default REST post endpoint.
	 *  - Makes meta available in the Site Health debug panel.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_meta(): void {
		$meta_definitions = array(
			self::META_IMAGES      => array(
				'type'        => 'string',
				'description' => 'JSON-encoded array of attachment IDs.',
				'default'     => '[]',
			),
			self::META_SPEED       => array(
				'type'        => 'integer',
				'description' => 'Scroll speed (5–150).',
				'default'     => 30,
			),
			self::META_CURVE       => array(
				'type'        => 'integer',
				'description' => 'Curve intensity (0–50).',
				'default'     => 12,
			),
			self::META_GAP         => array(
				'type'        => 'integer',
				'description' => 'Gap between images as a percentage (0–50).',
				'default'     => 10,
			),
			self::META_DIRECTION   => array(
				'type'        => 'string',
				'description' => 'Scroll direction: "left" or "right".',
				'default'     => 'left',
			),
			self::META_HEIGHT      => array(
				'type'        => 'integer',
				'description' => 'Slider height in pixels (200–800).',
				'default'     => 400,
			),
			self::META_AUTOPLAY    => array(
				'type'        => 'integer',
				'description' => 'Autoplay flag: 1 = on, 0 = off.',
				'default'     => 1,
			),
			self::META_PAUSE_HOVER => array(
				'type'        => 'integer',
				'description' => 'Pause on hover flag: 1 = on, 0 = off.',
				'default'     => 1,
			),
			self::META_BG_COLOR    => array(
				'type'        => 'string',
				'description' => 'Background colour as a hex string.',
				'default'     => '#ffffff',
			),
			self::META_DESKTOP_VISIBLE => array(
				'type'        => 'integer',
				'description' => 'Desktop visible images count (3-12).',
				'default'     => 8,
			),
			self::META_TABLET_VISIBLE => array(
				'type'        => 'integer',
				'description' => 'Tablet visible images count (2-8).',
				'default'     => 5,
			),
			self::META_MOBILE_VISIBLE => array(
				'type'        => 'integer',
				'description' => 'Mobile visible images count (1-5).',
				'default'     => 3,
			),
		);

		foreach ( $meta_definitions as $meta_key => $definition ) {
			register_post_meta(
				'wynk_slider',
				$meta_key,
				array(
					'type'              => $definition['type'],
					'description'       => $definition['description'],
					'single'            => true,
					'default'           => $definition['default'],
					'show_in_rest'      => false, // Exposed only through our own endpoint.
					'sanitize_callback' => array( $this, 'sanitize_meta_value_' . ltrim( $meta_key, '_' ) ),
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	// =========================================================
	// Step 2.3 — REST API endpoint
	// =========================================================

	/**
	 * Register the read-only REST route used by the admin preview panel.
	 *
	 * Route: GET /wp-json/wynk/v1/slider/{id}
	 *
	 * Authentication: standard WordPress cookie auth (admin session).
	 * Capability: edit_posts — editors and above can read slider data.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_rest_routes(): void {
		register_rest_route(
			'wynk/v1',
			'/slider/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE, // 'GET'
				'callback'            => array( $this, 'rest_get_slider' ),
				'permission_callback' => static function () {
					// Minimum: logged-in user with edit capability.
					// No user data or capabilities are returned in the response.
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'id' => array(
						'description'       => __( 'Slider post ID.', 'webwynk-curve-slider' ),
						'type'              => 'integer',
						'required'          => true,
						'minimum'           => 1,
						'validate_callback' => static function ( $value ) {
							return is_numeric( $value ) && (int) $value > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * REST callback: returns slider data for the given post ID.
	 *
	 * Response shape:
	 * {
	 *   id:       int,
	 *   title:    string,
	 *   images:   [ { id, url, thumb, alt } ],
	 *   settings: { speed, curve, gap, direction, height, autoplay, pauseHover, bgColor }
	 * }
	 *
	 * No user data, user IDs, or capability information is included.
	 *
	 * @since  1.0.0
	 * @param  WP_REST_Request $request Incoming REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function rest_get_slider( WP_REST_Request $request ) {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		// Validate the post exists and belongs to our CPT.
		if ( ! $post || 'wynk_slider' !== $post->post_type ) {
			return new WP_Error(
				'wynk_slider_not_found',
				__( 'Slider not found.', 'webwynk-curve-slider' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response(
			array(
				'id'       => $post_id,
				'title'    => get_the_title( $post ),
				'images'   => $this->get_image_data( $post_id ),
				'settings' => $this->get_settings( $post_id ),
			),
			200
		);
	}

	// =========================================================
	// Public helpers (used by shortcode and AJAX classes)
	// =========================================================

	/**
	 * Build the normalised image data array for a slider.
	 *
	 * Retrieves full-size and thumbnail URLs for every attachment ID stored
	 * in the _wynk_images meta. IDs pointing to deleted or non-image
	 * attachments are silently skipped.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Slider post ID.
	 * @return array<int, array{id: int, url: string, thumb: string, width: int, height: int, alt: string}>
	 */
	public function get_image_data( int $post_id ): array {
		$raw_ids = $this->get_meta_value( $post_id, self::META_IMAGES );
		$ids     = json_decode( $raw_ids, true );

		if ( ! is_array( $ids ) || empty( $ids ) ) {
			return array();
		}

		$images = array();

		foreach ( $ids as $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id ) {
				continue;
			}

			$large = wp_get_attachment_image_src( $attachment_id, 'large' );
			if ( ! $large ) {
				// Attachment deleted or not an image — skip gracefully.
				continue;
			}

			$thumb = wp_get_attachment_image_src( $attachment_id, 'thumbnail' );

			$images[] = array(
				'id'     => $attachment_id,
				'url'    => esc_url_raw( $large[0] ),
				'thumb'  => esc_url_raw( $thumb ? $thumb[0] : $large[0] ),
				'width'  => (int) $large[1],
				'height' => (int) $large[2],
				'alt'    => esc_attr( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ),
			);
		}

		return $images;
	}

	/**
	 * Return all slider settings as a normalised associative array.
	 *
	 * This is the canonical source of slider settings used by both the
	 * shortcode renderer and the REST endpoint.
	 *
	 * @since  1.0.0
	 * @param  int $post_id Slider post ID.
	 * @return array{speed: int, curve: int, gap: int, direction: string, height: int, autoplay: bool, pauseHover: bool, bgColor: string}
	 */
	public function get_settings( int $post_id ): array {
		return array(
			'speed'      => (int) $this->get_meta_value( $post_id, self::META_SPEED ),
			'curve'      => (int) $this->get_meta_value( $post_id, self::META_CURVE ),
			'gap'        => (int) $this->get_meta_value( $post_id, self::META_GAP ),
			'direction'  => (string) $this->get_meta_value( $post_id, self::META_DIRECTION ),
			'height'     => (int) $this->get_meta_value( $post_id, self::META_HEIGHT ),
			'autoplay'   => (bool) (int) $this->get_meta_value( $post_id, self::META_AUTOPLAY ),
			'pauseHover'     => (bool) (int) $this->get_meta_value( $post_id, self::META_PAUSE_HOVER ),
			'bgColor'        => (string) $this->get_meta_value( $post_id, self::META_BG_COLOR ),
			'desktopVisible' => (int) $this->get_meta_value( $post_id, self::META_DESKTOP_VISIBLE ),
			'tabletVisible'  => (int) $this->get_meta_value( $post_id, self::META_TABLET_VISIBLE ),
			'mobileVisible'  => (int) $this->get_meta_value( $post_id, self::META_MOBILE_VISIBLE ),
		);
	}

	// =========================================================
	// Private helpers
	// =========================================================

	/**
	 * Retrieve a single post meta value, falling back to the registered default.
	 *
	 * @since  1.0.0
	 * @param  int    $post_id  Slider post ID.
	 * @param  string $meta_key One of the META_* constants.
	 * @return mixed
	 */
	private function get_meta_value( int $post_id, string $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );

		// get_post_meta returns '' for missing meta; fall back to our defaults.
		if ( '' === $value || false === $value ) {
			return self::$meta_defaults[ $meta_key ] ?? '';
		}

		return $value;
	}

	// =========================================================
	// Sanitize callbacks for register_post_meta
	// (named sanitize_meta_value_{key_without_underscores})
	// =========================================================

	/** @internal */
	public function sanitize_meta_value_wynk_images( $value ): string {
		$decoded = json_decode( wp_unslash( (string) $value ), true );
		if ( ! is_array( $decoded ) ) {
			return '[]';
		}
		return wp_json_encode( array_values( array_map( 'absint', $decoded ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_speed( $value ): int {
		return max( 5, min( 150, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_curve( $value ): int {
		return max( 0, min( 50, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_gap( $value ): int {
		return max( 0, min( 50, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_direction( $value ): string {
		return in_array( $value, array( 'left', 'right' ), true ) ? $value : 'left';
	}

	/** @internal */
	public function sanitize_meta_value_wynk_height( $value ): int {
		return max( 200, min( 800, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_autoplay( $value ): int {
		return (int) (bool) $value;
	}

	/** @internal */
	public function sanitize_meta_value_wynk_pause_hover( $value ): int {
		return (int) (bool) $value;
	}

	/** @internal */
	public function sanitize_meta_value_wynk_bg_color( $value ): string {
		$sanitized = sanitize_hex_color( (string) $value );
		return $sanitized ?: '#ffffff';
	}

	/** @internal */
	public function sanitize_meta_value_wynk_desktop_visible( $value ): int {
		return max( 3, min( 12, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_tablet_visible( $value ): int {
		return max( 2, min( 8, absint( $value ) ) );
	}

	/** @internal */
	public function sanitize_meta_value_wynk_mobile_visible( $value ): int {
		return max( 1, min( 5, absint( $value ) ) );
	}
}
