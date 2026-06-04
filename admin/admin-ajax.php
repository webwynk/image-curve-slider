<?php
/**
 * Admin AJAX handlers for the Image Curve Slider plugin.
 *
 * Handles three actions (all prefixed wynk_cs_):
 *  - wynk_cs_save_slider   — insert or update a slider and its meta.
 *  - wynk_cs_delete_slider — permanently delete a slider (force, no trash).
 *  - wynk_cs_get_slider    — return slider data JSON for edit-form repopulation.
 *
 * Every handler: verifies nonce → checks capability → sanitizes inputs →
 * operates on data → responds with wp_send_json_success / wp_send_json_error.
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// Step 3.6 — AJAX: Save (insert or update) a slider
// ============================================================

add_action( 'wp_ajax_wynk_cs_save_slider', 'wynk_cs_save_slider' );

/**
 * Insert or update a wynk_slider post and all its _wynk_* meta.
 *
 * POST params:
 *   nonce       (string)  — wp_nonce for 'wynk_cs_nonce'
 *   id          (int)     — 0 for new slider, post ID for update
 *   title       (string)  — slider name
 *   images      (string)  — JSON-encoded array of attachment IDs
 *   speed       (int)     — 5–150
 *   gap         (int)     — 0–50
 *   height      (int)     — 200–800
 *   direction   (string)  — 'left' or 'right'
 *   autoplay    (int)     — 0 or 1
 *   pause_hover (int)     — 0 or 1
 *
 * Success response: { id: int, shortcode: string }
 *
 * @since 1.0.0
 * @return never
 */
function wynk_cs_save_slider(): void {
	// ── Security ─────────────────────────────────────────────
	check_ajax_referer( 'wynk_cs_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array( 'message' => __( 'You do not have permission to perform this action.', 'webwynk-curve-slider' ) ),
			403
		);
	}

	// ── Sanitize inputs ──────────────────────────────────────
	$id    = absint( $_POST['id'] ?? 0 );
	$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

	// Attachment IDs: decode JSON → cast each value to absint → re-encode.
	$raw_images = json_decode( wp_unslash( $_POST['images'] ?? '[]' ), true );
	if ( ! is_array( $raw_images ) ) {
		$raw_images = array();
	}
	$images = array_values( array_filter( array_map( 'absint', $raw_images ) ) );

	// Numeric settings — clamped to valid ranges.
	$speed           = max( 5,   min( 150, absint( $_POST['speed']           ?? 30  ) ) );
	$gap             = max( 0,   min( 50,  absint( $_POST['gap']             ?? 10  ) ) );
	$height          = max( 200, min( 800, absint( $_POST['height']          ?? 400 ) ) );
	$desktop_visible = max( 3,   min( 12,  absint( $_POST['desktop_visible'] ?? 8   ) ) );
	$tablet_visible  = max( 2,   min( 8,   absint( $_POST['tablet_visible']  ?? 5   ) ) );
	$mobile_visible  = max( 1,   min( 5,   absint( $_POST['mobile_visible']  ?? 3   ) ) );

	// Direction: allow-list check.
	$direction_raw = sanitize_key( wp_unslash( $_POST['direction'] ?? 'left' ) );
	$direction     = in_array( $direction_raw, array( 'left', 'right' ), true ) ? $direction_raw : 'left';



	// Booleans stored as 0/1 integers.
	$autoplay = (int) (bool) (int) ( $_POST['autoplay']    ?? 1 );
	$pause    = (int) (bool) (int) ( $_POST['pause_hover'] ?? 1 );

	// ── Title validation ─────────────────────────────────────
	if ( '' === $title ) {
		wp_send_json_error(
			array( 'message' => __( 'Slider name cannot be empty.', 'webwynk-curve-slider' ) ),
			400
		);
	}

	// ── Insert or update the post ────────────────────────────
	$post_data = array(
		'post_title'  => $title,
		'post_type'   => 'wynk_slider',
		'post_status' => 'publish',
	);

	if ( $id > 0 ) {
		// Verify the ID belongs to our CPT before updating.
		if ( 'wynk_slider' !== get_post_type( $id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Invalid slider ID.', 'webwynk-curve-slider' ) ),
				400
			);
		}
		$post_data['ID'] = $id;
		$post_id         = wp_update_post( $post_data, true );
	} else {
		$post_id = wp_insert_post( $post_data, true );
	}

	if ( is_wp_error( $post_id ) ) {
		wp_send_json_error(
			array( 'message' => $post_id->get_error_message() ),
			500
		);
	}

	// ── Persist meta ─────────────────────────────────────────
	// Use the WYNK_CPT constants to keep key strings DRY.
	update_post_meta( $post_id, WYNK_CPT::META_IMAGES,          wp_json_encode( $images ) );
	update_post_meta( $post_id, WYNK_CPT::META_SPEED,           $speed );
	update_post_meta( $post_id, WYNK_CPT::META_GAP,             $gap );
	update_post_meta( $post_id, WYNK_CPT::META_DIRECTION,       $direction );
	update_post_meta( $post_id, WYNK_CPT::META_HEIGHT,          $height );
	update_post_meta( $post_id, WYNK_CPT::META_AUTOPLAY,        $autoplay );
	update_post_meta( $post_id, WYNK_CPT::META_PAUSE_HOVER,     $pause );

	update_post_meta( $post_id, WYNK_CPT::META_DESKTOP_VISIBLE, $desktop_visible );
	update_post_meta( $post_id, WYNK_CPT::META_TABLET_VISIBLE,  $tablet_visible );
	update_post_meta( $post_id, WYNK_CPT::META_MOBILE_VISIBLE,  $mobile_visible );

	// ── Respond ──────────────────────────────────────────────
	wp_send_json_success(
		array(
			'id'        => $post_id,
			'shortcode' => sprintf( '[wynk_slider id="%d"]', $post_id ),
		)
	);
}

// ============================================================
// Step 3.7 — AJAX: Permanently delete a slider
// ============================================================

add_action( 'wp_ajax_wynk_cs_delete_slider', 'wynk_cs_delete_slider' );

/**
 * Permanently delete a wynk_slider post (force, no trash).
 *
 * POST params:
 *   nonce (string) — wp_nonce for 'wynk_cs_nonce'
 *   id    (int)    — slider post ID
 *
 * Success response: { } (empty data object)
 *
 * @since 1.0.0
 * @return never
 */
function wynk_cs_delete_slider(): void {
	// ── Security ─────────────────────────────────────────────
	check_ajax_referer( 'wynk_cs_nonce', 'nonce' );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error(
			array( 'message' => __( 'You do not have permission to perform this action.', 'webwynk-curve-slider' ) ),
			403
		);
	}

	// ── Validate ─────────────────────────────────────────────
	$id = absint( $_POST['id'] ?? 0 );

	if ( ! $id ) {
		wp_send_json_error(
			array( 'message' => __( 'No slider ID provided.', 'webwynk-curve-slider' ) ),
			400
		);
	}

	// Verify the post belongs to our CPT — prevents deleting arbitrary posts.
	if ( 'wynk_slider' !== get_post_type( $id ) ) {
		wp_send_json_error(
			array( 'message' => __( 'Invalid slider ID.', 'webwynk-curve-slider' ) ),
			400
		);
	}

	// ── Force delete (bypass trash) ──────────────────────────
	$deleted = wp_delete_post( $id, true );

	if ( ! $deleted ) {
		wp_send_json_error(
			array( 'message' => __( 'Could not delete the slider. Please try again.', 'webwynk-curve-slider' ) ),
			500
		);
	}

	wp_send_json_success();
}

// ============================================================
// Step 3.8 — AJAX: Get slider data (for edit-form repopulation)
// ============================================================

add_action( 'wp_ajax_wynk_cs_get_slider', 'wynk_cs_get_slider' );

/**
 * Return slider data as JSON for repopulating the edit form.
 *
 * Uses the public helpers on WYNK_CPT so the response shape is identical to
 * the REST endpoint — no duplication of data-building logic.
 *
 * GET params:
 *   nonce (string) — wp_nonce for 'wynk_cs_nonce'
 *   id    (int)    — slider post ID
 *
 * Success response: { id, title, images[], settings{} }
 *
 * @since 1.0.0
 * @return never
 */
function wynk_cs_get_slider(): void {
	// ── Security ─────────────────────────────────────────────
	// Editors and above can read slider data (same cap as REST endpoint).
	check_ajax_referer( 'wynk_cs_nonce', 'nonce' );

	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_send_json_error(
			array( 'message' => __( 'You do not have permission to perform this action.', 'webwynk-curve-slider' ) ),
			403
		);
	}

	// ── Validate ─────────────────────────────────────────────
	$id   = absint( $_GET['id'] ?? 0 );
	$post = get_post( $id );

	if ( ! $post || 'wynk_slider' !== $post->post_type ) {
		wp_send_json_error(
			array( 'message' => __( 'Slider not found.', 'webwynk-curve-slider' ) ),
			404
		);
	}

	// ── Build response via WYNK_CPT helpers ──────────────────
	// Delegates to the same helpers used by the REST endpoint so the
	// response shape is guaranteed to be identical.
	$cpt = new WYNK_CPT();

	wp_send_json_success(
		array(
			'id'       => $id,
			'title'    => get_the_title( $post ),
			'images'   => $cpt->get_image_data( $id ),
			'settings' => $cpt->get_settings( $id ),
		)
	);
}
