<?php
/**
 * Admin page: menu registration + three views (Dashboard, Create/Edit, Preview Modal).
 *
 * Views are driven by the ?view= query parameter:
 *   (default / 'dashboard') → card grid of all sliders
 *   'create'                → empty create form + live preview panel
 *   'edit'                  → prefilled edit form + live preview panel
 *
 * The preview modal (#wynk-preview-modal) is always rendered at the bottom of
 * the page and toggled by JS.
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ============================================================
// Step 3.1 — Register top-level admin menu
// ============================================================

add_action( 'admin_menu', 'wynk_cs_register_menu' );

/**
 * Register the top-level "Wynk Slider" admin menu item.
 *
 * We use show_in_menu:false on the CPT so WordPress doesn't generate a
 * default menu entry; this function gives us full control over the label,
 * icon, and position.
 *
 * @since 1.0.0
 * @return void
 */
function wynk_cs_register_menu(): void {
	add_menu_page(
		__( 'Image Curve Slider by WebWynk', 'webwynk-curve-slider' ), // <title>
		__( 'Wynk Slider', 'webwynk-curve-slider' ),                   // menu label
		'manage_options',                                               // capability
		'wynk-curve-slider',                                            // slug (used in $hook)
		'wynk_cs_render_page',                                          // page callback
		'dashicons-images-alt2',                                        // icon
		25                                                              // position (after Comments)
	);
}

// ============================================================
// Step 3.2 — Page router
// ============================================================

/**
 * Main page callback — verifies capability then routes to the correct view.
 *
 * @since 1.0.0
 * @return void
 */
function wynk_cs_render_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'webwynk-curve-slider' ) );
	}

	$view = sanitize_key( $_GET['view'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wynk-admin-wrap wrap">
		<?php
		switch ( $view ) {
			case 'create':
				wynk_cs_view_edit( 0 );
				break;

			case 'edit':
				$slider_id = absint( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
				wynk_cs_view_edit( $slider_id );
				break;

			case '':
			case 'dashboard':
			default:
				wynk_cs_view_dashboard();
				break;
		}
		?>

		<?php
		// Step 3.5 — Preview modal (always in DOM; toggled by JS).
		wynk_cs_render_preview_modal();
		?>
	</div>
	<?php
}

// ============================================================
// Step 3.3 — View 1: Dashboard (card grid)
// ============================================================

/**
 * Render the slider card grid (or the empty state when no sliders exist).
 *
 * @since 1.0.0
 * @return void
 */
function wynk_cs_view_dashboard(): void {
	$query = new WP_Query(
		array(
			'post_type'      => 'wynk_slider',
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true, // we don't need pagination
		)
	);

	$create_url = admin_url( 'admin.php?page=wynk-curve-slider&view=create' );
	?>
	<div class="wynk-dashboard-header">
		<h1 class="wynk-page-title">
			<?php esc_html_e( 'Image Curve Slider by WebWynk', 'webwynk-curve-slider' ); ?>
		</h1>
		<a href="<?php echo esc_url( $create_url ); ?>" class="wynk-btn wynk-btn--primary wynk-btn--lg">
			<span class="dashicons dashicons-plus-alt2"></span>
			<?php esc_html_e( 'Create New Slider', 'webwynk-curve-slider' ); ?>
		</a>
	</div>

	<?php if ( ! $query->have_posts() ) : ?>

		<div class="wynk-empty-state">
			<div class="wynk-empty-state__icon">
				<span class="dashicons dashicons-images-alt2"></span>
			</div>
			<h2 class="wynk-empty-state__heading">
				<?php esc_html_e( 'No sliders yet', 'webwynk-curve-slider' ); ?>
			</h2>
			<p class="wynk-empty-state__desc">
				<?php esc_html_e( 'Create your first curved image slider — it only takes a minute.', 'webwynk-curve-slider' ); ?>
			</p>
			<a href="<?php echo esc_url( $create_url ); ?>" class="wynk-btn wynk-btn--primary">
				<?php esc_html_e( 'Create Your First Slider', 'webwynk-curve-slider' ); ?>
			</a>
		</div>

	<?php else : ?>

		<div class="wynk-card-grid">
			<?php
			while ( $query->have_posts() ) :
				$query->the_post();
				$post_id = get_the_ID();

				// ── Image data ────────────────────────────────────────
				$raw_ids    = get_post_meta( $post_id, WYNK_CPT::META_IMAGES, true );
				$image_ids  = json_decode( (string) $raw_ids, true );
				$image_ids  = is_array( $image_ids ) ? array_filter( array_map( 'absint', $image_ids ) ) : array();
				$img_count  = count( $image_ids );

				// Thumbnail: use the first image in the set (medium size).
				$thumb_url  = '';
				if ( $img_count > 0 ) {
					$first_img = wp_get_attachment_image_src( reset( $image_ids ), 'medium' );
					if ( $first_img ) {
						$thumb_url = $first_img[0];
					}
				}

				// ── Settings pills ────────────────────────────────────
				$speed = (int) get_post_meta( $post_id, WYNK_CPT::META_SPEED, true ) ?: 30;
				$curve = (int) get_post_meta( $post_id, WYNK_CPT::META_CURVE, true );

				// ── URLs ──────────────────────────────────────────────
				$edit_url    = admin_url( 'admin.php?page=wynk-curve-slider&view=edit&id=' . $post_id );
				$shortcode   = sprintf( '[wynk_slider id="%d"]', $post_id );
				?>

				<div class="wynk-card" id="wynk-card-<?php echo absint( $post_id ); ?>">

					<?php /* ── Thumbnail ──────────────────────────────── */ ?>
					<div class="wynk-card__thumb-wrap">
						<?php if ( $thumb_url ) : ?>
							<img
								class="wynk-card__thumb"
								src="<?php echo esc_url( $thumb_url ); ?>"
								alt="<?php echo esc_attr( get_the_title() ); ?>"
								loading="lazy"
							>
						<?php else : ?>
							<div class="wynk-card__thumb wynk-card__thumb--placeholder">
								<span class="dashicons dashicons-format-image"></span>
							</div>
						<?php endif; ?>
					</div>

					<div class="wynk-card__body">

						<?php /* ── Title ──────────────────────────────── */ ?>
						<h3 class="wynk-card__title"><?php the_title(); ?></h3>

						<?php /* ── Shortcode badge or no-images warning ─ */ ?>
						<?php if ( $img_count > 0 ) : ?>
							<div class="wynk-card__shortcode">
								<code class="wynk-shortcode-text"><?php echo esc_html( $shortcode ); ?></code>
								<button
									type="button"
									class="wynk-copy-btn"
									data-shortcode="<?php echo esc_attr( $shortcode ); ?>"
									aria-label="<?php esc_attr_e( 'Copy shortcode', 'webwynk-curve-slider' ); ?>"
								>
									<span class="dashicons dashicons-clipboard"></span>
									<?php esc_html_e( 'Copy', 'webwynk-curve-slider' ); ?>
								</button>
							</div>
						<?php else : ?>
							<div class="wynk-card__no-images">
								<span class="dashicons dashicons-warning"></span>
								<?php esc_html_e( 'No images yet — add images to use this slider.', 'webwynk-curve-slider' ); ?>
							</div>
						<?php endif; ?>

						<?php /* ── Stat pills ─────────────────────────── */ ?>
						<div class="wynk-card__stats">
							<span class="wynk-pill">
								<span class="dashicons dashicons-format-gallery"></span>
								<?php
								printf(
									/* translators: %d: number of images */
									esc_html( _n( '%d image', '%d images', $img_count, 'webwynk-curve-slider' ) ),
									absint( $img_count )
								);
								?>
							</span>
							<span class="wynk-pill">
								<?php
								printf(
									/* translators: %d: speed value */
									esc_html__( 'Speed %d', 'webwynk-curve-slider' ),
									absint( $speed )
								);
								?>
							</span>
						</div>

						<?php /* ── Action buttons ──────────────────────── */ ?>
						<div class="wynk-card__actions">
							<a
								href="<?php echo esc_url( $edit_url ); ?>"
								class="wynk-btn wynk-btn--primary wynk-btn--sm"
							>
								<span class="dashicons dashicons-edit"></span>
								<?php esc_html_e( 'Edit', 'webwynk-curve-slider' ); ?>
							</a>

							<?php if ( $img_count > 0 ) : ?>
								<button
									type="button"
									class="wynk-btn wynk-btn--outline wynk-btn--sm wynk-preview-btn"
									data-slider-id="<?php echo absint( $post_id ); ?>"
									data-slider-title="<?php echo esc_attr( get_the_title() ); ?>"
								>
									<span class="dashicons dashicons-visibility"></span>
									<?php esc_html_e( 'Preview', 'webwynk-curve-slider' ); ?>
								</button>
							<?php endif; ?>

							<button
								type="button"
								class="wynk-btn wynk-btn--danger wynk-btn--sm wynk-delete-btn"
								data-slider-id="<?php echo absint( $post_id ); ?>"
								data-confirm="<?php esc_attr_e( 'Delete this slider? This cannot be undone.', 'webwynk-curve-slider' ); ?>"
							>
								<span class="dashicons dashicons-trash"></span>
								<?php esc_html_e( 'Delete', 'webwynk-curve-slider' ); ?>
							</button>
						</div>

					</div><!-- .wynk-card__body -->
				</div><!-- .wynk-card -->

			<?php endwhile; ?>
			<?php wp_reset_postdata(); ?>
		</div><!-- .wynk-card-grid -->

	<?php endif; ?>
	<?php
}

// ============================================================
// Step 3.4 — View 2: Create / Edit form
// ============================================================

/**
 * Render the two-column Create/Edit slider form.
 *
 * @since 1.0.0
 * @param int $post_id 0 for create, positive integer for edit.
 * @return void
 */
function wynk_cs_view_edit( int $post_id ): void {
	// Defaults for a new slider.
	$slider_title    = '';
	$images_json     = '[]';
	$speed           = 30;
	$curve           = 12;
	$gap             = 10;
	$height          = 400;
	$direction       = 'left';

	$autoplay        = 1;
	$pause_hover     = 1;
	$desktop_visible = 8;
	$tablet_visible  = 5;
	$mobile_visible  = 3;
	$is_edit         = false;

	// If editing an existing slider, load its values.
	if ( $post_id > 0 ) {
		$post = get_post( $post_id );
		if ( ! $post || 'wynk_slider' !== $post->post_type ) {
			wp_die( esc_html__( 'Slider not found.', 'webwynk-curve-slider' ) );
		}

		$is_edit         = true;
		$slider_title    = $post->post_title;
		$images_json     = get_post_meta( $post_id, WYNK_CPT::META_IMAGES,      true ) ?: '[]';
		$speed           = (int) ( get_post_meta( $post_id, WYNK_CPT::META_SPEED,       true ) ?: 30  );
		$curve           = (int) ( get_post_meta( $post_id, WYNK_CPT::META_CURVE,       true ) ?: 12  );
		$gap             = (int) ( get_post_meta( $post_id, WYNK_CPT::META_GAP,         true ) ?: 10  );
		$height          = (int) ( get_post_meta( $post_id, WYNK_CPT::META_HEIGHT,      true ) ?: 400 );
		$direction       =        get_post_meta( $post_id, WYNK_CPT::META_DIRECTION,   true ) ?: 'left';

		$autoplay        = (int) ( get_post_meta( $post_id, WYNK_CPT::META_AUTOPLAY,    true ) ?? 1 );
		$pause_hover     = (int) ( get_post_meta( $post_id, WYNK_CPT::META_PAUSE_HOVER, true ) ?? 1 );
		$desktop_visible = (int) ( get_post_meta( $post_id, WYNK_CPT::META_DESKTOP_VISIBLE, true ) ?: 8 );
		$tablet_visible  = (int) ( get_post_meta( $post_id, WYNK_CPT::META_TABLET_VISIBLE,  true ) ?: 5 );
		$mobile_visible  = (int) ( get_post_meta( $post_id, WYNK_CPT::META_MOBILE_VISIBLE,  true ) ?: 3 );
	}

	$cancel_url  = admin_url( 'admin.php?page=wynk-curve-slider' );
	$page_title  = $is_edit
		? __( 'Edit Slider', 'webwynk-curve-slider' )
		: __( 'Create New Slider', 'webwynk-curve-slider' );
	?>

	<div class="wynk-edit-header">
		<a href="<?php echo esc_url( $cancel_url ); ?>" class="wynk-back-link">
			<span class="dashicons dashicons-arrow-left-alt"></span>
			<?php esc_html_e( 'All Sliders', 'webwynk-curve-slider' ); ?>
		</a>
		<h1 class="wynk-page-title"><?php echo esc_html( $page_title ); ?></h1>
	</div>

	<?php /* Inline notices area — populated by JS after save ───────── */ ?>
	<div id="wynk-notice-area" class="wynk-notice-area" aria-live="polite"></div>

	<div class="wynk-edit-layout">

		<?php /* ══════════════════════════════════════════════════════════
		       LEFT COLUMN — Settings form
		       ══════════════════════════════════════════════════════════ */ ?>
		<div class="wynk-settings-panel">
			<form id="wynk-slider-form" method="post" novalidate>

				<?php /* Security: nonce + slider ID ──────────────────── */ ?>
				<?php wp_nonce_field( 'wynk_cs_nonce', 'wynk_nonce' ); ?>
				<input type="hidden" id="wynk-slider-id" name="slider_id" value="<?php echo absint( $post_id ); ?>">

				<?php /* ── Section A: General ────────────────────────── */ ?>
				<div class="wynk-section">
					<h2 class="wynk-section__heading"><?php esc_html_e( 'General', 'webwynk-curve-slider' ); ?></h2>

					<div class="wynk-field">
						<label for="wynk-slider-name" class="wynk-label">
							<?php esc_html_e( 'Slider Name', 'webwynk-curve-slider' ); ?>
							<span class="wynk-required" aria-hidden="true">*</span>
						</label>
						<input
							type="text"
							id="wynk-slider-name"
							name="title"
							class="widefat"
							value="<?php echo esc_attr( $slider_title ); ?>"
							required
							placeholder="<?php esc_attr_e( 'e.g. Homepage Hero Slider', 'webwynk-curve-slider' ); ?>"
						>
					</div>



					<div class="wynk-field">
						<label for="wynk-height" class="wynk-label">
							<?php esc_html_e( 'Slider Height (px)', 'webwynk-curve-slider' ); ?>
						</label>
						<input
							type="number"
							id="wynk-height"
							name="height"
							class="widefat"
							min="200"
							max="800"
							step="10"
							value="<?php echo absint( $height ); ?>"
						>
						<p class="wynk-field__desc"><?php esc_html_e( 'Between 200 and 800 pixels.', 'webwynk-curve-slider' ); ?></p>
					</div>
				</div><!-- Section A -->

				<?php /* ── Section B: Images ───────────────────────── */ ?>
				<div class="wynk-section">
					<h2 class="wynk-section__heading"><?php esc_html_e( 'Images', 'webwynk-curve-slider' ); ?></h2>

					<button type="button" id="wynk-add-images" class="wynk-btn wynk-btn--outline">
						<span class="dashicons dashicons-admin-media"></span>
						<?php esc_html_e( 'Add / Change Images', 'webwynk-curve-slider' ); ?>
					</button>

					<?php /* Thumbnail grid — rendered and managed by JS */ ?>
					<div id="wynk-image-grid" class="wynk-image-grid" aria-label="<?php esc_attr_e( 'Selected images (drag to reorder)', 'webwynk-curve-slider' ); ?>"></div>

					<?php /* Hidden input holds the ordered JSON array of IDs */ ?>
					<input
						type="hidden"
						id="wynk-images-json"
						name="images"
						value="<?php echo esc_attr( $images_json ); ?>"
					>

					<p class="wynk-field__desc">
						<?php esc_html_e( 'Drag thumbnails to reorder. Click × to remove an image.', 'webwynk-curve-slider' ); ?>
					</p>
				</div><!-- Section B -->

				<?php /* ── Section C: Motion ───────────────────────── */ ?>
				<div class="wynk-section">
					<h2 class="wynk-section__heading"><?php esc_html_e( 'Motion', 'webwynk-curve-slider' ); ?></h2>

					<?php /* Speed ──────────────────────────────────── */ ?>
					<div class="wynk-field wynk-range-row">
						<div class="wynk-range-header">
							<label for="wynk-speed" class="wynk-label"><?php esc_html_e( 'Scroll Speed', 'webwynk-curve-slider' ); ?></label>
							<span class="wynk-range-value" id="wynk-speed-val"><?php echo absint( $speed ); ?></span>
						</div>
						<input
							type="range"
							id="wynk-speed"
							name="speed"
							min="5"
							max="150"
							step="1"
							value="<?php echo absint( $speed ); ?>"
							aria-valuenow="<?php echo absint( $speed ); ?>"
							aria-valuemin="5"
							aria-valuemax="150"
						>
						<div class="wynk-range-labels">
							<span><?php esc_html_e( 'Slow', 'webwynk-curve-slider' ); ?></span>
							<span><?php esc_html_e( 'Fast', 'webwynk-curve-slider' ); ?></span>
						</div>
					</div>


					<?php /* Gap ────────────────────────────────────── */ ?>
					<div class="wynk-field wynk-range-row">
						<div class="wynk-range-header">
							<label for="wynk-gap" class="wynk-label"><?php esc_html_e( 'Image Gap (%)', 'webwynk-curve-slider' ); ?></label>
							<span class="wynk-range-value" id="wynk-gap-val"><?php echo absint( $gap ); ?></span>
						</div>
						<input
							type="range"
							id="wynk-gap"
							name="gap"
							min="0"
							max="50"
							step="1"
							value="<?php echo absint( $gap ); ?>"
							aria-valuenow="<?php echo absint( $gap ); ?>"
							aria-valuemin="0"
							aria-valuemax="50"
						>
						<div class="wynk-range-labels">
							<span><?php esc_html_e( 'No gap', 'webwynk-curve-slider' ); ?></span>
							<span><?php esc_html_e( 'Wide gap', 'webwynk-curve-slider' ); ?></span>
						</div>
					</div>

					<?php /* Direction ──────────────────────────────── */ ?>
					<div class="wynk-field">
						<fieldset>
							<legend class="wynk-label"><?php esc_html_e( 'Scroll Direction', 'webwynk-curve-slider' ); ?></legend>
							<div class="wynk-radio-group">
								<label class="wynk-radio">
									<input
										type="radio"
										id="wynk-dir-left"
										name="direction"
										value="left"
										<?php checked( $direction, 'left' ); ?>
									>
									<span><?php esc_html_e( 'Left (default)', 'webwynk-curve-slider' ); ?></span>
								</label>
								<label class="wynk-radio">
									<input
										type="radio"
										id="wynk-dir-right"
										name="direction"
										value="right"
										<?php checked( $direction, 'right' ); ?>
									>
									<span><?php esc_html_e( 'Right', 'webwynk-curve-slider' ); ?></span>
								</label>
							</div>
						</fieldset>
					</div>
				</div><!-- Section C -->

				<?php /* ── Section D: Responsive Layout ─────────────── */ ?>
				<div class="wynk-section">
					<h2 class="wynk-section__heading"><?php esc_html_e( 'Responsive Layout', 'webwynk-curve-slider' ); ?></h2>

					<?php /* Desktop Visible Images ──────────────────── */ ?>
					<div class="wynk-field wynk-range-row">
						<div class="wynk-range-header">
							<label for="wynk-desktop-visible" class="wynk-label"><?php esc_html_e( 'Desktop Visible Images', 'webwynk-curve-slider' ); ?></label>
							<span class="wynk-range-value" id="wynk-desktop-visible-val"><?php echo absint( $desktop_visible ); ?></span>
						</div>
						<input
							type="range"
							id="wynk-desktop-visible"
							name="desktop_visible"
							min="3"
							max="12"
							step="1"
							value="<?php echo absint( $desktop_visible ); ?>"
							aria-valuenow="<?php echo absint( $desktop_visible ); ?>"
							aria-valuemin="3"
							aria-valuemax="12"
						>
						<div class="wynk-range-labels">
							<span><?php esc_html_e( 'Fewer (3)', 'webwynk-curve-slider' ); ?></span>
							<span><?php esc_html_e( 'More (12)', 'webwynk-curve-slider' ); ?></span>
						</div>
					</div>

					<?php /* Tablet Visible Images ───────────────────── */ ?>
					<div class="wynk-field wynk-range-row">
						<div class="wynk-range-header">
							<label for="wynk-tablet-visible" class="wynk-label"><?php esc_html_e( 'Tablet Visible Images', 'webwynk-curve-slider' ); ?></label>
							<span class="wynk-range-value" id="wynk-tablet-visible-val"><?php echo absint( $tablet_visible ); ?></span>
						</div>
						<input
							type="range"
							id="wynk-tablet-visible"
							name="tablet_visible"
							min="2"
							max="8"
							step="1"
							value="<?php echo absint( $tablet_visible ); ?>"
							aria-valuenow="<?php echo absint( $tablet_visible ); ?>"
							aria-valuemin="2"
							aria-valuemax="8"
						>
						<div class="wynk-range-labels">
							<span><?php esc_html_e( 'Fewer (2)', 'webwynk-curve-slider' ); ?></span>
							<span><?php esc_html_e( 'More (8)', 'webwynk-curve-slider' ); ?></span>
						</div>
					</div>

					<?php /* Mobile Visible Images ───────────────────── */ ?>
					<div class="wynk-field wynk-range-row">
						<div class="wynk-range-header">
							<label for="wynk-mobile-visible" class="wynk-label"><?php esc_html_e( 'Mobile Visible Images', 'webwynk-curve-slider' ); ?></label>
							<span class="wynk-range-value" id="wynk-mobile-visible-val"><?php echo absint( $mobile_visible ); ?></span>
						</div>
						<input
							type="range"
							id="wynk-mobile-visible"
							name="mobile_visible"
							min="1"
							max="5"
							step="1"
							value="<?php echo absint( $mobile_visible ); ?>"
							aria-valuenow="<?php echo absint( $mobile_visible ); ?>"
							aria-valuemin="1"
							aria-valuemax="5"
						>
						<div class="wynk-range-labels">
							<span><?php esc_html_e( 'Fewer (1)', 'webwynk-curve-slider' ); ?></span>
							<span><?php esc_html_e( 'More (5)', 'webwynk-curve-slider' ); ?></span>
						</div>
					</div>
				</div><!-- Section Responsive -->

				<?php /* ── Section E: Behavior ─────────────────────── */ ?>
				<div class="wynk-section">
					<h2 class="wynk-section__heading"><?php esc_html_e( 'Behavior', 'webwynk-curve-slider' ); ?></h2>

					<?php /* Autoplay toggle ─────────────────────────── */ ?>
					<div class="wynk-field wynk-field--row wynk-field--toggle">
						<div class="wynk-toggle-label">
							<span class="wynk-label"><?php esc_html_e( 'Autoplay', 'webwynk-curve-slider' ); ?></span>
							<span class="wynk-field__desc"><?php esc_html_e( 'Automatically scroll images.', 'webwynk-curve-slider' ); ?></span>
						</div>
						<label class="wynk-toggle" for="wynk-autoplay">
							<input
								type="checkbox"
								class="wynk-toggle__input"
								id="wynk-autoplay"
								name="autoplay"
								value="1"
								<?php checked( 1, $autoplay ); ?>
							>
							<span class="wynk-toggle__track" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Autoplay', 'webwynk-curve-slider' ); ?></span>
						</label>
					</div>

					<?php /* Pause on hover toggle ───────────────────── */ ?>
					<div class="wynk-field wynk-field--row wynk-field--toggle">
						<div class="wynk-toggle-label">
							<span class="wynk-label"><?php esc_html_e( 'Pause on Hover', 'webwynk-curve-slider' ); ?></span>
							<span class="wynk-field__desc"><?php esc_html_e( 'Stop scrolling when the user hovers over the slider.', 'webwynk-curve-slider' ); ?></span>
						</div>
						<label class="wynk-toggle" for="wynk-pause-hover">
							<input
								type="checkbox"
								class="wynk-toggle__input"
								id="wynk-pause-hover"
								name="pause_hover"
								value="1"
								<?php checked( 1, $pause_hover ); ?>
							>
							<span class="wynk-toggle__track" aria-hidden="true"></span>
							<span class="screen-reader-text"><?php esc_html_e( 'Pause on Hover', 'webwynk-curve-slider' ); ?></span>
						</label>
					</div>
				</div><!-- Section D -->

				<?php /* ── Form actions ─────────────────────────────── */ ?>
				<div class="wynk-form-actions">
					<button type="button" id="wynk-save-btn" class="wynk-btn wynk-btn--primary wynk-btn--lg">
						<span class="dashicons dashicons-saved"></span>
						<?php $is_edit ? esc_html_e( 'Update Slider', 'webwynk-curve-slider' ) : esc_html_e( 'Save Slider', 'webwynk-curve-slider' ); ?>
					</button>
					<a href="<?php echo esc_url( $cancel_url ); ?>" class="wynk-btn wynk-btn--ghost">
						<?php esc_html_e( 'Cancel', 'webwynk-curve-slider' ); ?>
					</a>
				</div>

			</form>
		</div><!-- .wynk-settings-panel -->

		<?php /* ══════════════════════════════════════════════════════════
		       RIGHT COLUMN — Live Preview panel
		       ══════════════════════════════════════════════════════════ */ ?>
		<div class="wynk-preview-panel" id="wynk-preview-panel">
			<div class="wynk-preview-panel__header">
				<span class="wynk-preview-panel__title">
					<span class="dashicons dashicons-visibility"></span>
					<?php esc_html_e( 'Live Preview', 'webwynk-curve-slider' ); ?>
				</span>
				<span class="wynk-preview-panel__note">
					<?php esc_html_e( 'Updates as you type', 'webwynk-curve-slider' ); ?>
				</span>
			</div>
			<div class="wynk-preview-panel__canvas-wrap" style="height: <?php echo absint( $height ); ?>px;">
				<div id="wynk-admin-preview" class="wynk-curve-slider-wrap" style="height: 100%;">
					<canvas id="wynk-preview-canvas" class="wynk-curve-canvas"></canvas>
				</div>
			</div>
		</div><!-- .wynk-preview-panel -->

	</div><!-- .wynk-edit-layout -->
	<?php
}

// ============================================================
// Step 3.5 — Preview modal (always rendered, toggled by JS)
// ============================================================

/**
 * Render the full-screen preview modal markup.
 *
 * The modal is hidden via aria-hidden="true" and display:none (set in CSS).
 * The admin JS opens it when a "Preview" button is clicked on a dashboard card.
 *
 * @since 1.0.0
 * @return void
 */
function wynk_cs_render_preview_modal(): void {
	?>
	<div
		id="wynk-preview-modal"
		class="wynk-modal"
		aria-hidden="true"
		role="dialog"
		aria-modal="true"
		aria-label="<?php esc_attr_e( 'Slider preview', 'webwynk-curve-slider' ); ?>"
	>
		<button
			type="button"
			id="wynk-modal-close"
			class="wynk-modal__close"
			aria-label="<?php esc_attr_e( 'Close preview', 'webwynk-curve-slider' ); ?>"
		>
			<span class="dashicons dashicons-no-alt"></span>
		</button>

		<div id="wynk-modal-inner" class="wynk-modal__inner"></div>
	</div>
	<?php
}
