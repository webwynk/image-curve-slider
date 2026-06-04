# Implementation Plan — Image Curve Slider by WebWynk
## Phase-wise Development Roadmap

---

## Overview

| Property | Value |
|---|---|
| Plugin Slug | `webwynk-curve-slider` |
| Total Phases | 6 |
| Estimated Files | 12 (PHP: 5, JS: 2, CSS: 2, Vendor: 1, Docs: 2) |
| Estimated LOC | ~2,500–3,500 lines |
| PHP Minimum | 7.4 |
| WP Minimum | 5.8 |
| Three.js Version | r160 (UMD) |

---

## Development Rules

> [!IMPORTANT]
> **Plugin Versioning:** Whenever any changes are made to the plugin, the version number MUST be updated/bumped in both:
> 1. The WordPress plugin header in `webwynk-curve-slider.php` (`Version: X.Y.Z`)
> 2. The `WYNK_CS_VERSION` constant in `webwynk-curve-slider.php`

---

## Phase Map

```
Phase 1 → Plugin Bootstrap & Constants
Phase 2 → Custom Post Type + REST Endpoint
Phase 3 → Admin Dashboard (Views 1-3) + AJAX
Phase 4 → Admin JS (Media Picker, Drag-Sort, Preview, AJAX)
Phase 5 → Shortcode + Asset Enqueuing
Phase 6 → Three.js Engine + Frontend CSS
```

---

## Phase 1 — Plugin Bootstrap & Constants

**Goal:** Create the entry file that defines all constants, loads all class files, registers hooks, and bootstraps the plugin.

**Files in this phase:**
- `webwynk-curve-slider.php`

---

### Step 1.1 — Plugin Header

Write the standard WordPress plugin header block:

```php
/**
 * Plugin Name: Image Curve Slider by WebWynk
 * Plugin URI:  https://webwynk.com
 * Description: A 3D WebGL curved image slider with a full admin dashboard and [wynk_slider] shortcode.
 * Version:     1.0.0
 * Author:      WebWynk
 * Text Domain: webwynk-curve-slider
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */
```

Abort early if accessed directly:
```php
if ( ! defined( 'ABSPATH' ) ) exit;
```

---

### Step 1.2 — Define Constants

```php
define( 'WYNK_CS_VERSION', '1.0.0' );
define( 'WYNK_CS_PATH',    plugin_dir_path( __FILE__ ) );
define( 'WYNK_CS_URL',     plugin_dir_url( __FILE__ ) );
```

---

### Step 1.3 — Require Class Files

Load in dependency order (no autoloader needed for this plugin size):
1. `includes/class-wynk-cpt.php`
2. `includes/class-wynk-shortcode.php`
3. `includes/class-wynk-assets.php`
4. `admin/admin-ajax.php`
5. `admin/admin-page.php`

---

### Step 1.4 — Lifecycle Hooks

**Activation hook:**
```php
register_activation_hook( __FILE__, function() {
    add_option( 'wynk_cs_version', WYNK_CS_VERSION );
    flush_rewrite_rules();
});
```

**Deactivation hook:**
```php
register_deactivation_hook( __FILE__, function() {
    flush_rewrite_rules();
});
```

---

### Step 1.5 — Bootstrap on `init`

```php
add_action( 'plugins_loaded', function() {
    new WYNK_CPT();
    new WYNK_Shortcode();
    new WYNK_Assets();
});
```

> ⚠️ Use `plugins_loaded` (not `init`) so all WP core is available when instantiating classes.

---

### Phase 1 Checklist

- [ ] Plugin header complete with all required fields
- [ ] `ABSPATH` guard in place
- [ ] All three constants defined
- [ ] All class files required in correct order
- [ ] Activation hook creates `wynk_cs_version` option
- [ ] Deactivation hook flushes rewrite rules
- [ ] Classes instantiated via `plugins_loaded`

---

## Phase 2 — Custom Post Type + REST Endpoint

**Goal:** Register the `wynk_slider` CPT with correct labels and capabilities. Define all `_wynk_*` meta keys. Expose a REST endpoint for admin preview.

**Files in this phase:**
- `includes/class-wynk-cpt.php`

---

### Step 2.1 — Class Scaffold

```php
class WYNK_CPT {
    public function __construct() {
        add_action( 'init', [ $this, 'register_post_type' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
    }
}
```

---

### Step 2.2 — Register Post Type

```php
public function register_post_type() {
    $labels = [
        'name'          => 'Wynk Sliders',
        'singular_name' => 'Wynk Slider',
        'add_new'       => 'Add New',
        'add_new_item'  => 'Add New Slider',
        'edit_item'     => 'Edit Slider',
        'all_items'     => 'All Sliders',
    ];

    register_post_type( 'wynk_slider', [
        'labels'          => $labels,
        'public'          => false,
        'show_ui'         => true,
        'show_in_menu'    => false,
        'supports'        => [ 'title' ],
        'capability_type' => 'post',
        'map_meta_cap'    => true,
    ]);
}
```

---

### Step 2.3 — REST Endpoint

**Route:** `GET /wp-json/wynk/v1/slider/{id}`

```php
public function register_rest_routes() {
    register_rest_route( 'wynk/v1', '/slider/(?P<id>\d+)', [
        'methods'             => 'GET',
        'callback'            => [ $this, 'rest_get_slider' ],
        'permission_callback' => function() {
            return current_user_can( 'edit_posts' );
        },
        'args' => [
            'id' => [
                'validate_callback' => function( $v ) {
                    return is_numeric( $v );
                }
            ]
        ]
    ]);
}
```

**Callback:** `rest_get_slider( WP_REST_Request $request )`
- Get `$id = absint( $request['id'] )`
- Validate post exists and `post_type === 'wynk_slider'`
- Fetch all `_wynk_*` meta
- For each image ID: call `wp_get_attachment_image_src($id, 'large')` and `wp_get_attachment_image_src($id, 'thumbnail')`
- Return `WP_REST_Response` with `{ id, title, images, settings }` — no user data

---

### Step 2.4 — Meta Key Constants (Optional, Recommended)

Define as class constants for safety:
```php
const META_IMAGES      = '_wynk_images';
const META_SPEED       = '_wynk_speed';
const META_CURVE       = '_wynk_curve';
const META_GAP         = '_wynk_gap';
const META_DIRECTION   = '_wynk_direction';
const META_HEIGHT      = '_wynk_height';
const META_AUTOPLAY    = '_wynk_autoplay';
const META_PAUSE_HOVER = '_wynk_pause_hover';
const META_BG_COLOR    = '_wynk_bg_color';
```

---

### Phase 2 Checklist

- [ ] `register_post_type` fires on `init`
- [ ] CPT: `public: false`, `show_ui: true`, `show_in_menu: false`
- [ ] CPT: supports `['title']` only
- [ ] REST route registered on `rest_api_init`
- [ ] REST permission callback checks `edit_posts`
- [ ] REST response contains `images[]` and `settings{}`
- [ ] REST response contains no user/capability data
- [ ] All 9 meta key strings defined (as constants or inline)

---

## Phase 3 — Admin Dashboard + AJAX

**Goal:** Build the three admin views (Dashboard, Create/Edit, Preview Modal) and all AJAX save/delete/get handlers.

**Files in this phase:**
- `admin/admin-page.php`
- `admin/admin-ajax.php`

---

### Step 3.1 — Register Admin Menu

In `admin/admin-page.php`, hook into `admin_menu`:

```php
add_action( 'admin_menu', 'wynk_cs_register_menu' );

function wynk_cs_register_menu() {
    add_menu_page(
        'Image Curve Slider by WebWynk',  // page title
        'Wynk Slider',                    // menu title
        'manage_options',                 // capability
        'wynk-curve-slider',              // slug
        'wynk_cs_render_page',            // callback
        'dashicons-images-alt2',          // icon
        25                                // position
    );
}
```

---

### Step 3.2 — Page Router

In `wynk_cs_render_page()`:
1. Check `current_user_can('manage_options')` — die if not
2. Open `.wynk-admin-wrap` div
3. Read `$view = sanitize_key( $_GET['view'] ?? '' )`
4. Route:
   - `''` or `'dashboard'` → `wynk_cs_view_dashboard()`
   - `'create'` → `wynk_cs_view_edit( 0 )`
   - `'edit'` → `wynk_cs_view_edit( absint( $_GET['id'] ?? 0 ) )`
5. Close `.wynk-admin-wrap` div

---

### Step 3.3 — View 1: Dashboard

`wynk_cs_view_dashboard()` logic:

```
1. WP_Query: post_type=wynk_slider, posts_per_page=-1, post_status=any
2. Output header row: title + "Create New" button
3. If no posts → output empty-state message + Create button
4. Else → open .wynk-card-grid div
   For each post:
   a. Get first image ID from _wynk_images meta
   b. Get thumbnail URL (wp_get_attachment_image_src with 'medium' size)
   c. Count images: $img_count = count($image_ids)
   d. Render card:
      - <img> with thumbnail (or placeholder SVG if none)
      - <h3> with esc_html(post_title)
      - Shortcode badge + copy button — ONLY if $img_count > 0 (hidden when 0 images)
      - Stat pills: image count, speed, curve
      - If $img_count === 0: show a yellow "No images yet" warning badge instead of shortcode
      - Edit button → ?view=edit&id={id}
      - Preview button → data-slider-id={id} (opens modal via JS)
      - Delete button → data-slider-id={id} (JS confirm + AJAX)
5. Close .wynk-card-grid div
```

---

### Step 3.4 — View 2: Create/Edit

`wynk_cs_view_edit( int $post_id )` logic:

If `$post_id > 0`:
- Validate post exists and `post_type === 'wynk_slider'`
- Load all `_wynk_*` meta (with defaults for any missing values)

Output two-column layout:

**Left column — form:**
- `wp_nonce_field('wynk_cs_nonce')` hidden field
- `<input type="hidden" name="slider_id" value="...">` (0 for new)
- **Section A: General**
  - Name: `<input type="text" id="wynk-slider-name">`
  - BG Color: `<input type="text" id="wynk-bg-color" class="wynk-color-picker">`
  - Height: `<input type="number" id="wynk-height" min="200" max="800" step="10">`
- **Section B: Images**
  - Button: `<button id="wynk-add-images">Add / Change Images</button>`
  - `<div id="wynk-image-grid">` (rendered by JS)
  - `<input type="hidden" id="wynk-images-json" name="images">`
- **Section C: Motion**
  - Speed: `<input type="range" id="wynk-speed" min="5" max="150" step="1">` + `<span id="wynk-speed-val">`
  - Curve: `<input type="range" id="wynk-curve" min="0" max="50" step="1">` + `<span id="wynk-curve-val">`
  - Gap: `<input type="range" id="wynk-gap" min="0" max="50" step="1">` + `<span id="wynk-gap-val">`
  - Direction: radio buttons `#wynk-dir-left` / `#wynk-dir-right`
- **Section D: Behavior**
  - Autoplay: toggle switch `#wynk-autoplay`
  - Pause Hover: toggle switch `#wynk-pause-hover`
- Save button `#wynk-save-btn` + Cancel button

**Right column — preview:**
- `<div id="wynk-preview-panel">` with label "Live Preview"
- `<canvas id="wynk-preview-canvas">`

---

### Step 3.5 — View 3: Preview Modal

Output a hidden modal `#wynk-preview-modal` at bottom of page:
```html
<div id="wynk-preview-modal" class="wynk-modal" aria-hidden="true">
  <button id="wynk-modal-close" class="wynk-modal__close">✕</button>
  <div id="wynk-modal-inner"></div>
</div>
```

Modal is opened by JS when "Preview" button on a card is clicked. The full-page slider is mounted into `#wynk-modal-inner`.

---

### Step 3.6 — AJAX Handler: `wynk_cs_save_slider`

In `admin/admin-ajax.php`:

```php
add_action( 'wp_ajax_wynk_cs_save_slider', 'wynk_cs_save_slider' );

function wynk_cs_save_slider() {
    check_ajax_referer( 'wynk_cs_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    // Sanitize
    $id        = absint( $_POST['id'] ?? 0 );
    $title     = sanitize_text_field( $_POST['title'] ?? '' );
    $images    = array_map( 'absint', json_decode( wp_unslash( $_POST['images'] ?? '[]' ), true ) );
    $speed     = max( 5,   min( 150, absint( $_POST['speed']  ?? 30 ) ) );
    $curve     = max( 0,   min( 50,  absint( $_POST['curve']  ?? 12 ) ) );
    $gap       = max( 0,   min( 50,  absint( $_POST['gap']    ?? 10 ) ) );
    $height    = max( 200, min( 800, absint( $_POST['height'] ?? 400 ) ) );
    $direction = in_array( $_POST['direction'] ?? '', ['left','right'] ) ? $_POST['direction'] : 'left';
    $bg_color  = sanitize_hex_color( $_POST['bg_color'] ?? '#ffffff' ) ?: '#ffffff';
    $autoplay  = (bool)(int)( $_POST['autoplay'] ?? 1 );
    $pause     = (bool)(int)( $_POST['pause_hover'] ?? 1 );

    // Insert or update
    $post_data = [
        'post_title'  => $title,
        'post_type'   => 'wynk_slider',
        'post_status' => 'publish',
    ];
    if ( $id > 0 ) {
        $post_data['ID'] = $id;
        $post_id = wp_update_post( $post_data, true );
    } else {
        $post_id = wp_insert_post( $post_data, true );
    }

    if ( is_wp_error( $post_id ) ) {
        wp_send_json_error( $post_id->get_error_message() );
    }

    // Save meta
    update_post_meta( $post_id, '_wynk_images',      wp_json_encode( $images ) );
    update_post_meta( $post_id, '_wynk_speed',       $speed );
    update_post_meta( $post_id, '_wynk_curve',       $curve );
    update_post_meta( $post_id, '_wynk_gap',         $gap );
    update_post_meta( $post_id, '_wynk_direction',   $direction );
    update_post_meta( $post_id, '_wynk_height',      $height );
    update_post_meta( $post_id, '_wynk_autoplay',    (int) $autoplay );
    update_post_meta( $post_id, '_wynk_pause_hover', (int) $pause );
    update_post_meta( $post_id, '_wynk_bg_color',    $bg_color );

    wp_send_json_success([
        'id'        => $post_id,
        'shortcode' => sprintf( '[wynk_slider id="%d"]', $post_id ),
    ]);
}
```

---

### Step 3.7 — AJAX Handler: `wynk_cs_delete_slider`

```php
add_action( 'wp_ajax_wynk_cs_delete_slider', 'wynk_cs_delete_slider' );

function wynk_cs_delete_slider() {
    check_ajax_referer( 'wynk_cs_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $id = absint( $_POST['id'] ?? 0 );
    if ( ! $id || get_post_type( $id ) !== 'wynk_slider' ) {
        wp_send_json_error( 'Invalid slider ID' );
    }

    $result = wp_delete_post( $id, true ); // force delete, no trash
    if ( ! $result ) {
        wp_send_json_error( 'Delete failed' );
    }

    wp_send_json_success();
}
```

---

### Step 3.8 — AJAX Handler: `wynk_cs_get_slider`

```php
add_action( 'wp_ajax_wynk_cs_get_slider', 'wynk_cs_get_slider' );

function wynk_cs_get_slider() {
    check_ajax_referer( 'wynk_cs_nonce', 'nonce' );
    if ( ! current_user_can( 'edit_posts' ) ) {
        wp_send_json_error( 'Unauthorized', 403 );
    }

    $id   = absint( $_GET['id'] ?? 0 );
    $post = get_post( $id );

    if ( ! $post || $post->post_type !== 'wynk_slider' ) {
        wp_send_json_error( 'Not found', 404 );
    }

    // Build response (same structure as REST endpoint)
    $image_ids = json_decode( get_post_meta( $id, '_wynk_images', true ), true ) ?: [];
    $images = [];
    foreach ( $image_ids as $img_id ) {
        $large = wp_get_attachment_image_src( $img_id, 'large' );
        $thumb = wp_get_attachment_image_src( $img_id, 'thumbnail' );
        if ( $large ) {
            $images[] = [
                'id'    => $img_id,
                'url'   => $large[0],
                'thumb' => $thumb ? $thumb[0] : $large[0],
                'alt'   => get_post_meta( $img_id, '_wp_attachment_image_alt', true ),
            ];
        }
    }

    wp_send_json_success([
        'id'       => $id,
        'title'    => get_the_title( $id ),
        'images'   => $images,
        'settings' => [
            'speed'      => (int) get_post_meta( $id, '_wynk_speed',       true ) ?: 30,
            'curve'      => (int) get_post_meta( $id, '_wynk_curve',       true ) ?: 12,
            'gap'        => (int) get_post_meta( $id, '_wynk_gap',         true ) ?: 10,
            'direction'  =>       get_post_meta( $id, '_wynk_direction',   true ) ?: 'left',
            'height'     => (int) get_post_meta( $id, '_wynk_height',      true ) ?: 400,
            'autoplay'   => (bool)(int) get_post_meta( $id, '_wynk_autoplay',    true ),
            'pauseHover' => (bool)(int) get_post_meta( $id, '_wynk_pause_hover', true ),
            'bgColor'    =>       get_post_meta( $id, '_wynk_bg_color',    true ) ?: '#ffffff',
        ],
    ]);
}
```

---

### Phase 3 Checklist

- [ ] Menu registered with correct slug, icon, and capability
- [ ] Page router handles all three views + unknown view fallback
- [ ] Dashboard: card grid with all card anatomy elements
- [ ] Dashboard: empty-state message when no sliders
- [ ] Create/Edit: all four sections (A–D) with correct input IDs
- [ ] Create/Edit: nonce field + hidden slider ID
- [ ] Preview modal: hidden, full-screen, closable
- [ ] AJAX save: nonce check, cap check, full sanitization, range clamping
- [ ] AJAX save: inserts new or updates existing based on ID
- [ ] AJAX delete: force deletes (no trash), validates post type
- [ ] AJAX get: returns same structure as REST endpoint
- [ ] All `$_POST`/`$_GET` values sanitized before use
- [ ] All output escaped with correct WP escape functions

---

## Phase 4 — Admin JavaScript (`admin/js/wynk-admin.js`)

**Goal:** Implement all admin interactivity — media picker, drag-sort, live Three.js preview, AJAX save/delete, shortcode copy.

**Files in this phase:**
- `admin/js/wynk-admin.js`

---

### Step 4.1 — Namespace Setup

```js
window.WynkAdmin = (function($, THREE) {
  'use strict';

  var state = {
    imageIds: [],
    previewInstanceId: 'wynk-admin-preview',
    dragSrcIdx: null,
    mediaFrame: null,
  };

  // ... all functions ...

  return {
    init: init,
    refreshPreview: refreshPreview,
    openMediaPicker: openMediaPicker,
    renderThumbs: renderThumbs,
    saveSlider: saveSlider,
    deleteSlider: deleteSlider,
    copyShortcode: copyShortcode,
  };

})(jQuery, typeof THREE !== 'undefined' ? THREE : null);
```

---

### Step 4.2 — Media Library Picker

```js
function openMediaPicker() {
  if ( state.mediaFrame ) {
    state.mediaFrame.open();
    return;
  }

  state.mediaFrame = wp.media({
    title: 'Select Slider Images',
    button: { text: 'Add to Slider' },
    multiple: true,
    library: { type: 'image' },
  });

  state.mediaFrame.on('select', function() {
    var attachments = state.mediaFrame.state().get('selection').toJSON();
    attachments.forEach(function(att) {
      if ( state.imageIds.indexOf(att.id) === -1 ) {
        state.imageIds.push(att.id);
      }
    });
    renderThumbs();
    refreshPreview();
  });

  state.mediaFrame.open();
}
```

---

### Step 4.3 — Thumbnail Grid Renderer

```js
function renderThumbs() {
  var grid = document.getElementById('wynk-image-grid');
  var hidden = document.getElementById('wynk-images-json');
  grid.innerHTML = '';

  state.imageIds.forEach(function(id, idx) {
    // Use wp.media.attachment(id) or fallback to URL from data attribute
    var div = document.createElement('div');
    div.className = 'wynk-image-thumb';
    div.draggable = true;
    div.dataset.idx = idx;
    div.dataset.id = id;

    // Thumb img (fetch URL from pre-loaded attachment data stored in state.imageData)
    var img = document.createElement('img');
    img.src = state.imageData[id] ? state.imageData[id].thumb : '';
    div.appendChild(img);

    // Remove button
    var rmBtn = document.createElement('button');
    rmBtn.className = 'wynk-image-thumb__remove';
    rmBtn.textContent = '×';
    rmBtn.dataset.idx = idx;
    rmBtn.addEventListener('click', function(e) {
      e.stopPropagation();
      state.imageIds.splice(parseInt(this.dataset.idx), 1);
      renderThumbs();
      refreshPreview();
    });
    div.appendChild(rmBtn);

    // Drag events
    div.addEventListener('dragstart', onDragStart);
    div.addEventListener('dragover',  onDragOver);
    div.addEventListener('drop',      onDrop);
    div.addEventListener('dragend',   onDragEnd);

    grid.appendChild(div);
  });

  hidden.value = JSON.stringify(state.imageIds);
}
```

---

### Step 4.4 — HTML5 Drag-and-Drop Reorder

```js
function onDragStart(e) {
  state.dragSrcIdx = parseInt(this.dataset.idx);
  e.dataTransfer.effectAllowed = 'move';
  this.classList.add('is-dragging');
}

function onDragOver(e) {
  e.preventDefault();
  e.dataTransfer.dropEffect = 'move';
  return false;
}

function onDrop(e) {
  e.stopPropagation();
  var toIdx = parseInt(this.dataset.idx);
  if ( state.dragSrcIdx !== toIdx ) {
    var moved = state.imageIds.splice(state.dragSrcIdx, 1)[0];
    state.imageIds.splice(toIdx, 0, moved);
    renderThumbs();
    refreshPreview();
  }
  return false;
}

function onDragEnd() {
  document.querySelectorAll('.wynk-image-thumb').forEach(function(el) {
    el.classList.remove('is-dragging');
  });
}
```

---

### Step 4.5 — Live Preview (Three.js) — Hot-Update Strategy

**Decision:** Use **uniform hot-update** — update existing ShaderMaterial uniforms and scene properties in-place rather than destroying/rebuilding the scene on every debounced change. Full rebuild only happens when the image set changes (add/remove/reorder).

```js
var previewDebounceTimer = null;

// Tracks whether the current preview scene needs a full rebuild vs a hot-update
var previewNeedsRebuild = false;

function schedulePreviewRefresh(forceRebuild) {
  if (forceRebuild) previewNeedsRebuild = true;
  clearTimeout(previewDebounceTimer);
  previewDebounceTimer = setTimeout(refreshPreview, 300);
}

function refreshPreview() {
  var settings = collectFormValues();
  var ctx = state.previewCtx; // holds the live Three.js context

  if (!ctx || previewNeedsRebuild) {
    // Full rebuild path: image set changed, or first init
    previewNeedsRebuild = false;

    if (ctx && window.wynkCurve) {
      wynkCurve.destroy(state.previewInstanceId);
      state.previewCtx = null;
    }

    var images = (state.imageIds.length > 0)
      ? state.imageIds.map(function(id) { return state.imageData[id]; }).filter(Boolean)
      : generatePlaceholders(6);

    window.wynkSliders = window.wynkSliders || {};
    window.wynkSliders[state.previewInstanceId] = { images: images, settings: settings };

    var wrapEl = document.getElementById('wynk-preview-panel');
    if (wrapEl && window.wynkCurve) {
      state.previewCtx = wynkCurve.initInstance(state.previewInstanceId, wrapEl);
    }
    return;
  }

  // Hot-update path: only settings changed, image set is the same
  // Update uniforms on all existing plane materials directly
  ctx.planes.forEach(function(plane) {
    if (plane.material && plane.material.uniforms) {
      plane.material.uniforms.curve.value = settings.curve;
    }
  });

  // Update scene-level settings
  ctx.data.settings.speed      = settings.speed;
  ctx.data.settings.gap        = settings.gap;
  ctx.data.settings.direction  = settings.direction;
  ctx.data.settings.autoplay   = settings.autoplay;
  ctx.data.settings.pauseHover = settings.pauseHover;
  ctx.data.settings.bgColor    = settings.bgColor;
  ctx.data.settings.height     = settings.height;

  // Update wrapper height
  var wrapEl = document.getElementById('wynk-preview-panel');
  if (wrapEl) wrapEl.style.height = settings.height + 'px';
}

function generatePlaceholders(count) {
  var colors = ['#e8d5ff','#d5e8ff','#d5ffe8','#ffe8d5','#ffd5e8','#e8ffd5'];
  return colors.slice(0, count).map(function(c, i) {
    return { id: 'ph' + i, url: null, width: 800, height: 600, alt: '', color: c };
  });
}
```

**Trigger rules:**
- `schedulePreviewRefresh(false)` — called by range/toggle/color/direction changes → hot-update path
- `schedulePreviewRefresh(true)` — called after media picker adds/removes/reorders images → full rebuild path

> This approach eliminates the GPU teardown/rebuild overhead on every slider movement or curve tweak, making the live preview feel instant and smooth.

---

### Step 4.6 — Form Value Collector

```js
function collectFormValues() {
  return {
    speed:      parseInt(document.getElementById('wynk-speed').value),
    curve:      parseInt(document.getElementById('wynk-curve').value),
    gap:        parseInt(document.getElementById('wynk-gap').value),
    height:     parseInt(document.getElementById('wynk-height').value),
    direction:  document.querySelector('input[name="direction"]:checked').value,
    autoplay:   document.getElementById('wynk-autoplay').checked,
    pauseHover: document.getElementById('wynk-pause-hover').checked,
    bgColor:    document.getElementById('wynk-bg-color').value || '#ffffff',
  };
}
```

---

### Step 4.7 — AJAX Save

```js
function saveSlider() {
  var values = collectFormValues();
  var data = Object.assign({}, values, {
    action:     'wynk_cs_save_slider',
    nonce:      wynkAdminData.nonce,
    id:         document.getElementById('wynk-slider-id').value,
    title:      document.getElementById('wynk-slider-name').value,
    images:     JSON.stringify(state.imageIds),
    bg_color:   values.bgColor,
    pause_hover: values.pauseHover ? 1 : 0,
    autoplay:   values.autoplay ? 1 : 0,
  });

  $.post(wynkAdminData.ajaxUrl, data)
    .done(function(res) {
      if ( res.success ) {
        showNotice('success', 'Slider saved! Shortcode: ' + res.data.shortcode, res.data.shortcode);
        document.getElementById('wynk-slider-id').value = res.data.id;
      } else {
        showNotice('error', res.data || 'Save failed.');
      }
    })
    .fail(function() {
      showNotice('error', 'Network error. Please try again.');
    });
}
```

---

### Step 4.8 — AJAX Delete

```js
function deleteSlider(id, cardEl) {
  if ( ! window.confirm('Delete this slider? This cannot be undone.') ) return;

  $.post(wynkAdminData.ajaxUrl, {
    action: 'wynk_cs_delete_slider',
    nonce:  wynkAdminData.nonce,
    id:     id,
  }).done(function(res) {
    if ( res.success ) {
      cardEl.style.transition = 'opacity 0.4s ease';
      cardEl.style.opacity = '0';
      setTimeout(function() { cardEl.remove(); }, 400);
    }
  });
}
```

---

### Step 4.9 — Shortcode Copy

```js
function copyShortcode(text, triggerEl) {
  navigator.clipboard.writeText(text).then(function() {
    var orig = triggerEl.textContent;
    triggerEl.textContent = 'Copied!';
    setTimeout(function() { triggerEl.textContent = orig; }, 1500);
  });
}
```

---

### Step 4.10 — Event Listener Setup (`init`)

```js
function init() {
  // Range sliders live value display
  ['speed','curve','gap'].forEach(function(name) {
    var slider = document.getElementById('wynk-' + name);
    var display = document.getElementById('wynk-' + name + '-val');
    if ( slider && display ) {
      slider.addEventListener('input', function() {
        display.textContent = this.value;
        schedulePreviewRefresh();
      });
    }
  });

  // All other inputs → debounced preview refresh
  document.querySelectorAll('.wynk-settings-panel input, .wynk-settings-panel select')
    .forEach(function(el) {
      el.addEventListener('input', schedulePreviewRefresh);
      el.addEventListener('change', schedulePreviewRefresh);
    });

  // Media picker button
  var addBtn = document.getElementById('wynk-add-images');
  if ( addBtn ) addBtn.addEventListener('click', openMediaPicker);

  // Save button
  var saveBtn = document.getElementById('wynk-save-btn');
  if ( saveBtn ) saveBtn.addEventListener('click', saveSlider);

  // Shortcode copy buttons (dashboard)
  document.querySelectorAll('[data-shortcode]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      copyShortcode(this.dataset.shortcode, this);
    });
  });

  // Delete buttons (dashboard)
  document.querySelectorAll('[data-delete-id]').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var card = this.closest('.wynk-card');
      deleteSlider(this.dataset.deleteId, card);
    });
  });

  // Color picker
  if ( typeof $.fn.wpColorPicker !== 'undefined' ) {
    $('.wynk-color-picker').wpColorPicker({
      change: function() { schedulePreviewRefresh(); }
    });
  }

  // Init preview if on edit view
  if ( document.getElementById('wynk-preview-canvas') ) {
    refreshPreview();
  }
}

document.addEventListener('DOMContentLoaded', WynkAdmin.init);
```

---

### Phase 4 Checklist

- [ ] All code inside `window.WynkAdmin` closure
- [ ] Media picker: `wp.media()` initialized once, re-opened on subsequent calls
- [ ] Media picker: no duplicate IDs in `state.imageIds`
- [ ] Thumbnails rendered with remove buttons
- [ ] Drag-sort: correct reorder of `state.imageIds` array
- [ ] Preview: debounced 300ms
- [ ] Preview: placeholders when no images selected
- [ ] Save: all form fields collected and POST'd
- [ ] Save: inline notice with shortcode + copy button
- [ ] Delete: confirm → AJAX → fade-out card
- [ ] Copy shortcode: `navigator.clipboard` + 1.5s "Copied!" feedback
- [ ] Range sliders: live value display on `input` event
- [ ] Color picker: `wpColorPicker` integration
- [ ] `WynkAdmin.init()` called on `DOMContentLoaded`

---

## Phase 5 — Shortcode + Asset Enqueuing

**Goal:** Implement the `[wynk_slider]` shortcode and the asset enqueue class.

**Files in this phase:**
- `includes/class-wynk-shortcode.php`
- `includes/class-wynk-assets.php`

---

### Step 5.1 — WYNK_Shortcode Class

```php
class WYNK_Shortcode {
    public function __construct() {
        add_shortcode( 'wynk_slider', [ $this, 'render' ] );
    }

    public function render( $atts ) {
        $atts = shortcode_atts( [ 'id' => 0 ], $atts, 'wynk_slider' );
        $post_id = absint( $atts['id'] );

        if ( ! $post_id ) return '';

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'wynk_slider' || $post->post_status !== 'publish' ) {
            return '';
        }

        // Read meta with defaults
        $image_ids  = json_decode( get_post_meta( $post_id, '_wynk_images', true ), true ) ?: [];
        $speed      = (int) get_post_meta( $post_id, '_wynk_speed',       true ) ?: 30;
        $curve      = (int) get_post_meta( $post_id, '_wynk_curve',       true ) ?: 12;
        $gap        = (int) get_post_meta( $post_id, '_wynk_gap',         true ) ?: 10;
        $direction  =       get_post_meta( $post_id, '_wynk_direction',   true ) ?: 'left';
        $height     = (int) get_post_meta( $post_id, '_wynk_height',      true ) ?: 400;
        $autoplay   = (bool)(int) get_post_meta( $post_id, '_wynk_autoplay',    true ) ?? true;
        $pause      = (bool)(int) get_post_meta( $post_id, '_wynk_pause_hover', true ) ?? true;
        $bg_color   = get_post_meta( $post_id, '_wynk_bg_color', true ) ?: '#ffffff';

        // Build image data
        $images = [];
        foreach ( $image_ids as $img_id ) {
            $src = wp_get_attachment_image_src( $img_id, 'large' );
            if ( $src ) {
                $images[] = [
                    'id'     => $img_id,
                    'url'    => $src[0],
                    'width'  => $src[1],
                    'height' => $src[2],
                    'alt'    => esc_attr( get_post_meta( $img_id, '_wp_attachment_image_alt', true ) ),
                ];
            }
        }

        // Generate unique instance ID
        $instance_id = 'wynk-instance-' . $post_id . '-' . uniqid();

        // Localize slider data
        wp_localize_script( 'wynk-curve-slider', 'wynkSliders', [
            $instance_id => [
                'images'   => $images,
                'settings' => [
                    'speed'      => $speed,
                    'curve'      => $curve,
                    'gap'        => $gap,
                    'direction'  => $direction,
                    'height'     => $height,
                    'autoplay'   => $autoplay,
                    'pauseHover' => $pause,
                    'bgColor'    => $bg_color,
                ],
            ],
        ]);

        // Compute fade width from height/mobile logic (handled in JS, but set CSS var here)
        ob_start();
        ?>
        <div id="<?php echo esc_attr( $instance_id ); ?>"
             class="wynk-curve-slider-wrap"
             style="height:<?php echo absint( $height ); ?>px; --wynk-bg:<?php echo esc_attr( $bg_color ); ?>;">
          <canvas class="wynk-curve-canvas"></canvas>
          <div class="wynk-curve-fade wynk-curve-fade--left"></div>
          <div class="wynk-curve-fade wynk-curve-fade--right"></div>
        </div>
        <?php
        return ob_get_clean();
    }
}
```

> **Single shortcode per page (confirmed):** Only one `[wynk_slider]` shortcode will appear per page. `wp_localize_script` is called once inside the shortcode callback. No merge complexity needed — `window.wynkSliders` will contain exactly one key per page load. The shortcode callback returns `''` (empty string) immediately if the slider has zero images in `_wynk_images`.

---

### Step 5.2 — WYNK_Assets Class

```php
class WYNK_Assets {
    public function __construct() {
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
    }
}
```

**Frontend enqueue:**

```php
public function enqueue_frontend() {
    // Only enqueue on pages/posts that contain [wynk_slider]
    if ( ! is_singular() ) return;
    $content = get_the_content();
    if ( ! has_shortcode( $content, 'wynk_slider' ) ) return;

    wp_register_script(
        'wynk-three',
        WYNK_CS_URL . 'vendor/three.min.js',
        [],
        '0.160.0',
        true // footer
    );

    wp_register_script(
        'wynk-curve-slider',
        WYNK_CS_URL . 'public/js/wynk-slider.js',
        [ 'wynk-three' ],
        WYNK_CS_VERSION,
        true // footer
    );

    wp_enqueue_script( 'wynk-three' );
    wp_enqueue_script( 'wynk-curve-slider' );

    wp_enqueue_style(
        'wynk-curve-slider-css',
        WYNK_CS_URL . 'public/css/wynk-slider.css',
        [],
        WYNK_CS_VERSION
    );
}
```

**Admin enqueue:**

```php
public function enqueue_admin( $hook ) {
    if ( strpos( $hook, 'wynk-curve-slider' ) === false ) return;

    wp_enqueue_media();
    wp_enqueue_style( 'wp-color-picker' );

    wp_enqueue_script(
        'wynk-three',
        WYNK_CS_URL . 'vendor/three.min.js',
        [],
        '0.160.0',
        true
    );

    wp_enqueue_script(
        'wynk-admin-js',
        WYNK_CS_URL . 'admin/js/wynk-admin.js',
        [ 'jquery', 'wynk-three', 'wp-color-picker' ],
        WYNK_CS_VERSION,
        true
    );

    wp_enqueue_style(
        'wynk-admin-css',
        WYNK_CS_URL . 'admin/css/admin.css',
        [],
        WYNK_CS_VERSION
    );

    wp_localize_script( 'wynk-admin-js', 'wynkAdminData', [
        'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
        'nonce'     => wp_create_nonce( 'wynk_cs_nonce' ),
        'uploadsUrl' => wp_upload_dir()['baseurl'],
        'pluginUrl'  => WYNK_CS_URL,
    ]);
}
```

---

### Phase 5 Checklist

- [ ] Shortcode validates post type AND post status (`publish` only)
- [ ] Shortcode handles case where 0 images are stored (outputs empty images array)
- [ ] `wp_localize_script` called after `wp_register_script` but before output
- [ ] Multiple shortcodes on same page: each gets unique `instanceId`
- [ ] Frontend scripts only enqueued when shortcode is present
- [ ] Three.js registered before slider script (dependency order)
- [ ] Admin scripts only enqueued on `wynk-curve-slider` pages
- [ ] `wp_enqueue_media()` called in admin
- [ ] `wynkAdminData` localized with all 4 required fields
- [ ] Nonce generated via `wp_create_nonce('wynk_cs_nonce')`

---

## Phase 6 — Three.js Frontend Engine + CSS

**Goal:** Build the full WebGL slider engine and all CSS files.

**Files in this phase:**
- `public/js/wynk-slider.js`
- `public/css/wynk-slider.css`
- `admin/css/admin.css`

---

### Step 6.1 — Engine IIFE Structure

```js
;(function(window, THREE) {
  'use strict';

  if (!THREE) {
    console.error('[WynkSlider] THREE.js not loaded.');
    return;
  }

  var _instances = {};

  function init() { /* find all wrappers, init each */ }
  function initInstance(instanceId, el) { /* per-element setup */ }
  function buildPlanes(ctx) { /* create/recreate plane meshes */ }
  function animate(ctx) { /* rAF loop */ }
  function onResize() { /* debounced global resize handler */ }
  function destroy(instanceId) { /* cleanup */ }

  // Helpers
  function getWorldWidth(camera, el) { /* ... */ }
  function getResponsivePlaneScale(w) { /* ... */ }
  function clamp(v, min, max) { return Math.max(min, Math.min(max, v)); }
  function debounce(fn, ms) { /* ... */ }

  window.wynkCurve = { init: init, initInstance: initInstance, destroy: destroy };

  document.addEventListener('DOMContentLoaded', init);

})(window, window.THREE);
```

---

### Step 6.2 — Per-Instance Context Object

```js
var ctx = {
  el:         el,               // DOM wrapper element
  instanceId: instanceId,
  data:       window.wynkSliders[instanceId],
  scene:      null,
  camera:     null,
  renderer:   null,
  planes:     [],               // array of THREE.Mesh
  textures:   [],               // array of THREE.Texture
  time:       0,
  lastTick:   null,
  rafId:      null,
  paused:     false,
  width:      el.clientWidth,
  height:     el.clientHeight,
};
_instances[instanceId] = ctx;
```

---

### Step 6.3 — Camera & Renderer Setup

```js
ctx.camera = new THREE.PerspectiveCamera(75, ctx.width / ctx.height, 0.1, 20);
ctx.camera.position.z = 2;

ctx.scene = new THREE.Scene();

ctx.renderer = new THREE.WebGLRenderer({
  canvas:    el.querySelector('.wynk-curve-canvas'),
  alpha:     true,
  antialias: true,
});
ctx.renderer.setSize(ctx.width, ctx.height);
ctx.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
```

---

### Step 6.4 — Shader Materials

```js
var vertexShader = `
  uniform float curve;
  varying vec2 vUV;
  void main() {
    vUV = uv;
    vec3 pos = position;
    float dist = abs((modelMatrix * vec4(position, 1.0)).x);
    pos.y *= 1.0 + (curve / 100.0) * pow(dist, 2.0);
    gl_Position = projectionMatrix * modelViewMatrix * vec4(pos, 1.0);
  }
`;

var fragmentShader = `
  uniform sampler2D tex;
  uniform vec3 solidColor;
  uniform float useSolidColor;
  varying vec2 vUV;
  void main() {
    if (useSolidColor > 0.5) {
      gl_FragColor = vec4(solidColor, 1.0);
    } else {
      gl_FragColor = texture2D(tex, vUV);
    }
  }
`;
```

> The fragment shader has an extra `useSolidColor` uniform to handle placeholder colored planes without needing a separate shader program.

---

### Step 6.5 — Build Planes

```js
function buildPlanes(ctx) {
  var data     = ctx.data;
  var settings = data.settings;
  var images   = data.images;
  var gap      = settings.gap / 100;
  var scale    = getResponsivePlaneScale(ctx.width);
  var dirSign  = settings.direction === 'right' ? 1 : -1;

  var worldW    = getWorldWidth(ctx.camera, ctx.el);
  var spacing   = worldW * (1 + gap);

  var totalPlanes  = Math.ceil(ctx.width / (spacing * (ctx.width / worldW))) + 1 + images.length;
  var initOffset   = Math.ceil(ctx.width / (2 * spacing * (ctx.width / worldW)) - 0.5);

  // Dispose existing planes
  ctx.planes.forEach(function(p) {
    if (p.geometry) p.geometry.dispose();
    if (p.material) p.material.dispose();
    ctx.scene.remove(p);
  });
  ctx.planes = [];

  var loader = new THREE.TextureLoader();

  for (var i = 0; i < totalPlanes; i++) {
    var imgData = images[i % images.length];
    var aspect  = clamp(imgData.height / imgData.width, 0.9, 1.6);

    var geo = new THREE.PlaneGeometry(scale, scale * aspect, 20, 20);
    var mat = new THREE.ShaderMaterial({
      uniforms: {
        curve:        { value: settings.curve },
        tex:          { value: new THREE.Texture() },
        solidColor:   { value: new THREE.Color(imgData.color || '#cccccc') },
        useSolidColor:{ value: imgData.url ? 0.0 : 1.0 },
      },
      vertexShader:   vertexShader,
      fragmentShader: fragmentShader,
      transparent:    true,
    });

    var plane = new THREE.Mesh(geo, mat);
    plane.position.x = -dirSign * (i - initOffset) * (scale * (1 + gap));
    ctx.scene.add(plane);
    ctx.planes.push(plane);

    if (imgData.url) {
      (function(mat) {
        loader.load(imgData.url, function(texture) {
          mat.uniforms.tex.value = texture;
          mat.uniforms.useSolidColor.value = 0.0;
          mat.needsUpdate = true;
        });
      })(mat);
    }
  }
}
```

---

### Step 6.6 — Animation Loop

```js
function animate(ctx) {
  ctx.rafId = requestAnimationFrame(function() { animate(ctx); });

  var now   = performance.now();
  var delta = ctx.lastTick ? Math.min(now - ctx.lastTick, 100) : 16;
  ctx.lastTick = now;

  var settings = ctx.data.settings;
  var gap      = settings.gap / 100;
  var dirSign  = settings.direction === 'right' ? 1 : -1;
  var setWidth = (1 + gap) * ctx.data.images.length;

  if (!ctx.paused && settings.autoplay) {
    ctx.time += delta * 0.001 * (settings.speed * 0.01);
  }

  if (Math.abs(ctx.scene.position.x) >= setWidth) {
    ctx.time = 0;
  }

  ctx.scene.position.x = ctx.time * settings.speed * dirSign * 0.01;

  ctx.renderer.render(ctx.scene, ctx.camera);
}
```

---

### Step 6.7 — Resize Handling

```js
var resizeDebounceTimer = null;

window.addEventListener('resize', function() {
  clearTimeout(resizeDebounceTimer);
  resizeDebounceTimer = setTimeout(function() {
    Object.keys(_instances).forEach(function(id) {
      var ctx = _instances[id];
      var newW = ctx.el.clientWidth;
      var newH = ctx.el.clientHeight;

      if (newW !== ctx.width || newH !== ctx.height || newW < 768) {
        ctx.width  = newW;
        ctx.height = newH;

        ctx.camera.aspect = newW / newH;
        ctx.camera.updateProjectionMatrix();
        ctx.renderer.setSize(newW, newH);
        buildPlanes(ctx);
      }
    });
  }, 150);
});
```

---

### Step 6.8 — Mobile Height Cap

```js
function applyMobileHeightCap(el, configuredHeight) {
  var cap = (window.innerWidth <= 480)
    ? Math.min(configuredHeight, Math.round(window.innerWidth * 0.65))
    : configuredHeight;
  el.style.height = cap + 'px';
  return cap;
}
```

Called during `initInstance` after element setup.

---

### Step 6.9 — Hover Pause / Resume

```js
if (settings.pauseHover) {
  ctx.el.addEventListener('mouseenter', function() { ctx.paused = true; });
  ctx.el.addEventListener('mouseleave', function() { ctx.paused = false; });
}
```

---

### Step 6.10 — Destroy Function

```js
function destroy(instanceId) {
  var ctx = _instances[instanceId];
  if (!ctx) return;

  cancelAnimationFrame(ctx.rafId);

  ctx.planes.forEach(function(p) {
    if (p.geometry) p.geometry.dispose();
    if (p.material) {
      if (p.material.uniforms && p.material.uniforms.tex) {
        p.material.uniforms.tex.value.dispose();
      }
      p.material.dispose();
    }
  });
  ctx.textures.forEach(function(t) { t.dispose(); });

  ctx.renderer.dispose();

  delete _instances[instanceId];
  delete window.wynkSliders[instanceId];
}
```

---

### Step 6.11 — Frontend CSS (`public/css/wynk-slider.css`)

```css
.wynk-curve-slider-wrap {
  position: relative;
  overflow: hidden;
  display: block;
  box-sizing: border-box;
}

.wynk-curve-canvas {
  display: block;
  width: 100%;
  height: 100%;
}

.wynk-curve-fade {
  position: absolute;
  top: 0;
  bottom: 0;
  width: var(--wynk-fade-width, 80px);
  pointer-events: none;
  z-index: 3;
}

.wynk-curve-fade--left {
  left: 0;
  background: linear-gradient(to right, var(--wynk-bg, #fff), transparent);
}

.wynk-curve-fade--right {
  right: 0;
  background: linear-gradient(to left, var(--wynk-bg, #fff), transparent);
}

@media (max-width: 600px) {
  .wynk-curve-fade {
    width: 40px;
  }
}
```

---

### Step 6.12 — Admin CSS (`admin/css/admin.css`)

Key rules (full file is ~400 lines):

```css
/* ---- Root Scope ---- */
.wynk-admin-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }

/* ---- Card Grid ---- */
.wynk-admin-wrap .wynk-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
  margin-top: 24px;
}

.wynk-admin-wrap .wynk-card {
  background: #fff;
  border: 1px solid #e0e0e0;
  border-radius: 8px;
  overflow: hidden;
  transition: box-shadow 0.2s ease;
}
.wynk-admin-wrap .wynk-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.10); }

.wynk-admin-wrap .wynk-card__thumb {
  height: 180px;
  object-fit: cover;
  width: 100%;
  display: block;
  background: #f0f0f0;
}

/* ---- Shortcode Badge ---- */
.wynk-admin-wrap .wynk-card__shortcode {
  font-family: monospace;
  font-size: 12px;
  background: #f5f5f5;
  padding: 4px 8px;
  border-radius: 4px;
  display: flex;
  align-items: center;
  gap: 6px;
}

/* ---- Toggle Switch ---- */
.wynk-admin-wrap .wynk-toggle { display: flex; align-items: center; gap: 10px; }
.wynk-admin-wrap .wynk-toggle__input { display: none; }
.wynk-admin-wrap .wynk-toggle__track {
  width: 44px; height: 24px;
  background: #ccc;
  border-radius: 12px;
  position: relative;
  cursor: pointer;
  transition: background 0.25s;
}
.wynk-admin-wrap .wynk-toggle__track::after {
  content: '';
  position: absolute;
  left: 3px; top: 3px;
  width: 18px; height: 18px;
  border-radius: 50%;
  background: #fff;
  transition: transform 0.25s;
  box-shadow: 0 1px 3px rgba(0,0,0,0.2);
}
.wynk-admin-wrap .wynk-toggle__input:checked + .wynk-toggle__track { background: #2271b1; }
.wynk-admin-wrap .wynk-toggle__input:checked + .wynk-toggle__track::after {
  transform: translateX(20px);
}

/* ---- Two-Column Edit Layout ---- */
@media (min-width: 1100px) {
  .wynk-admin-wrap .wynk-edit-layout {
    display: flex;
    gap: 32px;
    align-items: flex-start;
  }
  .wynk-admin-wrap .wynk-settings-panel { width: 420px; flex-shrink: 0; }
  .wynk-admin-wrap .wynk-preview-panel { flex: 1; position: sticky; top: 32px; }
}

/* ---- Range Sliders ---- */
.wynk-admin-wrap input[type="range"] {
  width: 100%;
  accent-color: #2271b1;
}

/* ---- Image Thumb Grid ---- */
.wynk-admin-wrap .wynk-image-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}
.wynk-admin-wrap .wynk-image-thumb {
  width: 80px; height: 80px;
  border-radius: 6px;
  overflow: hidden;
  position: relative;
  cursor: grab;
  border: 2px solid transparent;
  transition: border-color 0.2s;
}
.wynk-admin-wrap .wynk-image-thumb.is-dragging { border-color: #2271b1; opacity: 0.6; }
.wynk-admin-wrap .wynk-image-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.wynk-admin-wrap .wynk-image-thumb__remove {
  position: absolute; top: 2px; right: 2px;
  background: rgba(0,0,0,0.5); color: #fff;
  border: none; border-radius: 50%;
  width: 18px; height: 18px;
  font-size: 12px; line-height: 18px; text-align: center;
  cursor: pointer; padding: 0;
  opacity: 0; transition: opacity 0.2s;
}
.wynk-admin-wrap .wynk-image-thumb:hover .wynk-image-thumb__remove { opacity: 1; }

/* ---- Section Headings ---- */
.wynk-admin-wrap .wynk-section__heading {
  font-variant: small-caps;
  color: #888;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.05em;
  border-bottom: 1px solid #e0e0e0;
  padding-bottom: 6px;
  margin: 24px 0 16px;
  text-transform: uppercase;
}

/* ---- Inline Notices ---- */
.wynk-admin-wrap .wynk-notice {
  padding: 10px 14px;
  border-radius: 4px;
  margin: 12px 0;
  font-size: 13px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.wynk-admin-wrap .wynk-notice--success { background: #edfaef; border-left: 3px solid #46b450; color: #1a7a1e; }
.wynk-admin-wrap .wynk-notice--error   { background: #fde8e8; border-left: 3px solid #dc3232; color: #8a1515; }

/* ---- Preview Modal ---- */
.wynk-modal {
  position: fixed; inset: 0;
  background: rgba(0,0,0,0.85);
  z-index: 999999;
  display: flex;
  align-items: center;
  justify-content: center;
}
.wynk-modal[aria-hidden="true"] { display: none; }
.wynk-modal__close {
  position: absolute; top: 16px; right: 20px;
  background: none; border: none;
  color: #fff; font-size: 28px; cursor: pointer;
  line-height: 1;
}
```

---

### Phase 6 Checklist

- [ ] Entire engine in IIFE: `(function(window, THREE){ })(window, THREE)`
- [ ] Guard for missing THREE at top of IIFE
- [ ] Per-instance context object fully populated
- [ ] Camera fov=75, z=2, near=0.1, far=20
- [ ] Renderer: alpha=true, antialias=true, setPixelRatio capped at 2
- [ ] Shaders: exact GLSL as specified (vertex + fragment)
- [ ] Plane aspect ratio clamped to 0.9–1.6
- [ ] `getWorldWidth` returns correct pixel-per-unit ratio
- [ ] Responsive scale factors applied on init and resize
- [ ] Placeholder planes: `useSolidColor` uniform, 6 distinct colors
- [ ] TextureLoader: async load with placeholder until ready
- [ ] rAF delta clamped to 100ms
- [ ] Seamless loop: reset time when `|scene.position.x| >= setWidth`
- [ ] Pause on hover: mouseenter/mouseleave listeners conditional on setting
- [ ] Resize: debounced 150ms, full rebuild (dispose + recreate)
- [ ] Mobile height cap: `Math.min(height, innerWidth * 0.65)` at ≤480px
- [ ] `--wynk-fade-width` CSS var set per breakpoint
- [ ] `destroy()`: cancels rAF, disposes all GPU resources, removes from `_instances` map
- [ ] `window.wynkCurve` exposed with `init`, `initInstance`, `destroy`
- [ ] Frontend CSS: all selectors `.wynk-curve-*`
- [ ] Admin CSS: all selectors scoped under `.wynk-admin-wrap`
- [ ] Toggle switch: pure CSS, no JS animation needed
- [ ] Two-column layout: `≥1100px` flex; `<1100px` stacked

---

## Final Delivery Checklist

### Files
- [ ] `webwynk-curve-slider.php`
- [ ] `includes/class-wynk-cpt.php`
- [ ] `includes/class-wynk-shortcode.php`
- [ ] `includes/class-wynk-assets.php`
- [ ] `admin/admin-page.php`
- [ ] `admin/admin-ajax.php`
- [ ] `admin/js/wynk-admin.js`
- [ ] `admin/css/admin.css`
- [ ] `public/js/wynk-slider.js`
- [ ] `public/css/wynk-slider.css`
- [ ] `vendor/three.min.js` (**committed to repo** — Three.js r160 UMD build from cdnjs.cloudflare.com/ajax/libs/three.js/0.160.0/three.min.js)
- [ ] `README.md`

### Naming Audit
- [ ] All PHP functions: `wynk_cs_*` prefix
- [ ] All PHP classes: `WYNK_*` prefix
- [ ] All PHP constants: `WYNK_CS_*` prefix
- [ ] All post meta keys: `_wynk_*` prefix
- [ ] All public CSS classes: `wynk-curve-*` prefix
- [ ] All admin CSS scoped under `.wynk-admin-wrap`
- [ ] No global JS variable unprefixed
- [ ] JS public namespace: `wynkCurve`
- [ ] JS admin namespace: `WynkAdmin`
- [ ] JS data store: `window.wynkSliders`

### Security Audit
- [ ] Every AJAX handler: nonce check + capability check + full sanitization
- [ ] Every admin page: `current_user_can('manage_options')` at top
- [ ] Every form: `wp_nonce_field`
- [ ] All output: escaped with appropriate WP functions
- [ ] REST endpoint: `permission_callback` returns capability check
- [ ] No raw `echo` in AJAX handlers — only `wp_send_json_success/error`

### Browser / WP Compatibility
- [ ] Tested on Chrome 80+, Firefox 78+, Safari 14+, Edge 80+
- [ ] Tested on WordPress 5.8+ with PHP 7.4+
- [ ] No IE support (WebGL + ES6 required)
- [ ] No jQuery in `wynk-slider.js`
- [ ] Three.js r160 UMD confirmed compatible with bundled approach

---

## Resolved Decisions

All design questions are resolved. No open questions remain.

| # | Question | Decision | Impact |
|---|---|---|---|
| 1 | `vendor/three.min.js` sourcing | **Commit to repo** — guarantees offline hosting compatibility, CDN independence, fixed version, no 404 risk | `vendor/three.min.js` must be committed; README documents the source URL for reference |
| 2 | Multiple shortcodes per page | **One shortcode per page only** | Simplifies `wp_localize_script` — single call, single `window.wynkSliders` key, no merge needed |
| 3 | Admin preview update strategy | **Uniform hot-update** — update ShaderMaterial uniforms and scene settings in-place; full rebuild only on image set changes | See Step 4.5 for `previewNeedsRebuild` flag and split code paths |
| 4 | Dashboard card when 0 images | **Hide shortcode badge** — replace with a yellow "No images yet" warning badge instead | Step 3.3: conditional render based on `$img_count > 0` |
| 5 | Frontend with 0 images | **Return `''`** — shortcode outputs nothing if `_wynk_images` is empty | Step 5.1: early return before HTML output |

### Why commit `three.min.js` to the repo?

- **CDN independence** — Many managed WordPress hosts block external CDN requests or require Content-Security-Policy whitelisting. Bundling eliminates this class of problem entirely.
- **Version lock** — Committing `r160` guarantees the shader code and API calls never break due to upstream changes.
- **Offline & staging** — Development and staging environments work without internet access.
- **Simplicity** — No build step, no npm, no CDN fallback logic needed.
- **File size** — `three.min.js` r160 is ~165 KB gzipped (~600 KB raw), acceptable for a plugin vendor asset.

> The README will document the source URL (`cdnjs.cloudflare.com/ajax/libs/three.js/0.160.0/three.min.js`) so future maintainers know exactly where to get an updated copy.
