# Design Document — Image Curve Slider by WebWynk

---

## 1. Product Overview

**Image Curve Slider by WebWynk** is a WordPress plugin that renders images on a continuously scrolling curved band using WebGL (Three.js). Editors manage sliders entirely within a custom admin dashboard; the slider is embedded on any page or post via the shortcode `[wynk_slider id="X"]`.

### Core Value Proposition

| Feature | Benefit |
|---|---|
| WebGL curved plane rendering | Visually premium — no CSS-only approximation |
| Fully in-dashboard editor | No page-builder dependency |
| Live 3D preview while editing | Eliminates guess-and-refresh workflow |
| Isolated CSS/JS namespaces | Zero conflicts with themes or other plugins |
| Bundled Three.js (no CDN) | Works in restricted hosting environments |

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────────────┐
│  WordPress Admin                                             │
│                                                              │
│  ┌──────────────┐   AJAX (wynk_cs_*)   ┌──────────────────┐ │
│  │ admin-page   │ ─────────────────────▶ admin-ajax.php   │ │
│  │ (Views 1–3)  │ ◀───────────────────── (save/delete/get)│ │
│  └──────┬───────┘                      └──────────────────┘ │
│         │ wp.media()                           │             │
│         ▼                                      ▼             │
│  ┌──────────────┐                    ┌──────────────────┐   │
│  │ wynk-admin.js│                    │  wp_postmeta     │   │
│  │ WynkAdmin{}  │◀── Three.js ───────│  _wynk_*  keys  │   │
│  │ Live Preview │    (same engine)   └──────────────────┘   │
│  └──────────────┘                                            │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│  WordPress Frontend                                          │
│                                                              │
│  [wynk_slider id="5"]                                        │
│         │                                                    │
│         ▼                                                    │
│  class-wynk-shortcode.php                                    │
│  → reads _wynk_* meta                                        │
│  → wp_localize_script → window.wynkSliders[instanceId]       │
│  → outputs .wynk-curve-slider-wrap + canvas                  │
│         │                                                    │
│         ▼                                                    │
│  wynk-slider.js (IIFE)                                       │
│  → THREE.WebGLRenderer on canvas                             │
│  → ShaderMaterial + PlaneGeometry array                      │
│  → rAF loop → seamless scroll                                │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Data Flow

### 3.1 Slider Save Flow

```
User fills form
    │
    ▼
wynk-admin.js collects values
    │
    ├─ title (text)
    ├─ images (JSON array of IDs)
    ├─ speed, curve, gap, height (integers)
    ├─ direction (string)
    ├─ autoplay, pause_hover (booleans)
    └─ bg_color (hex)
    │
    ▼
AJAX POST → admin-ajax.php → wynk_cs_save_slider
    │
    ├─ check_ajax_referer('wynk_cs_nonce', 'nonce')
    ├─ current_user_can('manage_options')
    ├─ Sanitize all values
    ├─ wp_insert_post / wp_update_post
    └─ update_post_meta for each _wynk_* key
    │
    ▼
JSON Response: { success: true, id, shortcode }
    │
    ▼
Admin JS shows inline notice + shortcode copy button
```

### 3.2 Frontend Render Flow

```
Page loads with [wynk_slider id="5"]
    │
    ▼
WYNK_Shortcode::render()
    ├─ Reads all _wynk_* meta
    ├─ Builds image array via wp_get_attachment_image_src()
    ├─ Generates instanceId: 'wynk-instance-5-<uniqid>'
    ├─ wp_localize_script → window.wynkSliders[instanceId]
    └─ Outputs HTML wrapper + canvas
    │
    ▼
DOMContentLoaded in wynk-slider.js
    ├─ querySelectorAll('.wynk-curve-slider-wrap')
    └─ Per element:
         ├─ Read instanceId from element.id
         ├─ Look up window.wynkSliders[instanceId]
         ├─ Init camera, renderer, scene
         ├─ Load textures via THREE.TextureLoader
         ├─ Build PlaneGeometry array with ShaderMaterial
         └─ Start rAF loop
```

### 3.3 Live Preview Data Flow

```
Admin Edit Form → input/change event
    │
    ▼ (debounced 300ms)
WynkAdmin.refreshPreview()
    ├─ Reads current form values
    ├─ Calls wynkCurve.destroy(previewInstanceId)
    └─ Rebuilds Three.js scene in #wynk-preview-canvas
         ├─ If images selected: load from WP media library
         └─ If no images: generate 6 placeholder colored planes
```

---

## 4. Database Schema

No custom tables. Uses WordPress native storage:

| Storage | Key | Value |
|---|---|---|
| `wp_posts` | `post_type = 'wynk_slider'` | Slider record |
| `wp_posts` | `post_title` | Slider name |
| `wp_postmeta` | `_wynk_images` | JSON: `[12,45,78]` |
| `wp_postmeta` | `_wynk_speed` | Integer |
| `wp_postmeta` | `_wynk_curve` | Integer |
| `wp_postmeta` | `_wynk_gap` | Integer |
| `wp_postmeta` | `_wynk_direction` | `'left'`/`'right'` |
| `wp_postmeta` | `_wynk_height` | Integer (px) |
| `wp_postmeta` | `_wynk_autoplay` | `1`/`0` |
| `wp_postmeta` | `_wynk_pause_hover` | `1`/`0` |
| `wp_postmeta` | `_wynk_bg_color` | Hex string |
| `wp_options` | `wynk_cs_version` | Plugin version |

---

## 5. Three.js Engine Design

### 5.1 Coordinate System

- Camera at `z = 2`, looking at origin
- World width computed from FOV + aspect: `worldW = 2 * tan(fov/2) * z * aspect`
- Planes positioned along X axis; scene scrolls via `scene.position.x`

### 5.2 Plane Spacing Math

```
pxPerUnit = containerWidth / worldW
planeSpacing = pxPerUnit × (1 + gap/100)

totalPlanes = ceil(containerWidth / planeSpacing) + 1 + images.length
initialOffset = ceil(containerWidth / (2 × planeSpacing) − 0.5)

plane[i].position.x = −directionSign × (i − initialOffset) × (1 + gap/100)
```

### 5.3 Shader Design

**Vertex Shader** — applies Y-axis scale distortion based on distance from center:
```glsl
uniform float curve;
varying vec2 vUV;
void main() {
  vUV = uv;
  vec3 pos = position;
  float dist = abs((modelMatrix * vec4(position, 1.0)).x);
  pos.y *= 1.0 + (curve / 100.0) * pow(dist, 2.0);
  gl_Position = projectionMatrix * modelViewMatrix * vec4(pos, 1.0);
}
```

**Fragment Shader** — simple texture lookup:
```glsl
uniform sampler2D tex;
varying vec2 vUV;
void main() {
  gl_FragColor = texture2D(tex, vUV);
}
```

> ⚠️ **Do not modify the shaders.** The curve effect is mathematically calibrated to the planeSpacing formula. Changes to either in isolation will break visual consistency.

### 5.4 Seamless Loop Logic

```
setWidth = (1 + gap/100) × images.length
scene.position.x = time × speed × directionSign

If |scene.position.x| >= setWidth → reset time to 0
```

This resets position before the viewer can detect a jump, producing a seamless infinite scroll.

### 5.5 Responsive Scale Factors

| Container Width | Plane Scale |
|---|---|
| ≤ 480px | 0.72 |
| ≤ 768px | 0.85 |
| ≤ 1024px | 0.92 |
| > 1024px | 1.00 |

Applied as a multiplier to base `PlaneGeometry` width on init and every resize rebuild.

---

## 6. Admin UI Design

### 6.1 Admin Menu Structure

```
WordPress Admin Sidebar
└── 🖼 Wynk Slider                        ← top-level menu (dashicons-images-alt2)
     └── (Managed via ?view= query param)
          ├── ?view=          → Dashboard (card grid)
          ├── ?view=create    → New slider (form + preview)
          ├── ?view=edit&id=X → Edit slider (form + preview)
          └── (modal)         → Full-screen preview overlay
```

### 6.2 View 1 — Dashboard Card Grid

```
┌─────────────────────────────────────────────────────┐
│  Image Curve Slider by WebWynk     [+ Create New]   │
├─────────────────────────────────────────────────────┤
│                                                      │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐           │
│  │[thumb]   │  │[thumb]   │  │[thumb]   │           │
│  │          │  │          │  │          │           │
│  │ Title    │  │ Title    │  │ Title    │           │
│  │[shortcode│  │[shortcode│  │[shortcode│           │
│  │  copy]   │  │  copy]   │  │  copy]   │           │
│  │ imgs • s │  │ imgs • s │  │ imgs • s │           │
│  │[Edit][Prv│  │[Edit][Prv│  │[Edit][Prv│           │
│  │     [Del]│  │     [Del]│  │     [Del]│           │
│  └──────────┘  └──────────┘  └──────────┘           │
└─────────────────────────────────────────────────────┘
```

### 6.3 View 2 — Create/Edit (Two-Column Layout ≥1100px)

```
┌──────────────────────────────┬──────────────────────┐
│  SETTINGS                    │  LIVE PREVIEW        │
│                              │  (Updates as you type)│
│  Section A: General          │                      │
│  ┌─────────────────────────┐ │  ┌────────────────┐  │
│  │ Slider Name  [________] │ │  │                │  │
│  │ BG Color     [#ffffff]  │ │  │  [WebGL Canvas]│  │
│  │ Height (px)  [400     ] │ │  │                │  │
│  └─────────────────────────┘ │  └────────────────┘  │
│                              │                      │
│  Section B: Images           │                      │
│  [+ Add / Change Images]     │                      │
│  ┌──┐┌──┐┌──┐               │                      │
│  │  ││  ││  │ ← drag sort   │                      │
│  └──┘└──┘└──┘               │                      │
│                              │                      │
│  Section C: Motion           │                      │
│  Speed     ├────────┤ 30     │                      │
│  Curve     ├────────┤ 12     │                      │
│  Gap %     ├────────┤ 10     │                      │
│  Direction ○ Left  ○ Right   │                      │
│                              │                      │
│  Section D: Behavior         │                      │
│  Autoplay      [●───]        │                      │
│  Pause Hover   [●───]        │                      │
│                              │                      │
│  [Save Slider]  [Cancel]     │                      │
└──────────────────────────────┴──────────────────────┘
```

### 6.4 View 3 — Full-Screen Preview Modal

```
┌─────────────────────────────────────────────────────┐
│                                              [✕ Close]│
│                                                      │
│                                                      │
│        [Full-viewport WebGL Slider Render]           │
│                                                      │
│                                                      │
└─────────────────────────────────────────────────────┘
```

---

## 7. Frontend HTML Structure

```html
<!-- Shortcode output -->
<div id="{instanceId}"
     class="wynk-curve-slider-wrap"
     style="height:{height}px; --wynk-bg:{bg_color}; --wynk-fade-width:{fadeWidth}px;">

  <canvas class="wynk-curve-canvas"></canvas>

  <div class="wynk-curve-fade wynk-curve-fade--left"></div>
  <div class="wynk-curve-fade wynk-curve-fade--right"></div>

</div>
```

### CSS Custom Properties per Instance

| Property | Set By | Used By |
|---|---|---|
| `--wynk-bg` | PHP shortcode inline style | Left/right fade gradients |
| `--wynk-fade-width` | JS on init + resize | Fade overlay width |

---

## 8. CSS Architecture

### 8.1 Public Namespace — `.wynk-curve-*`

```
.wynk-curve-slider-wrap    ← outer container
.wynk-curve-canvas         ← <canvas> element
.wynk-curve-fade           ← base for both fade overlays
.wynk-curve-fade--left     ← left gradient fade
.wynk-curve-fade--right    ← right gradient fade
```

### 8.2 Admin Namespace — `.wynk-admin-wrap *`

All admin selectors are nested inside `.wynk-admin-wrap` to prevent bleeding into WP core styles.

Key admin classes:
```
.wynk-admin-wrap           ← Page root wrapper
.wynk-card-grid            ← CSS grid container for slider cards
.wynk-card                 ← Individual slider card
.wynk-card__thumb          ← Thumbnail image area
.wynk-card__title          ← Slider title
.wynk-card__shortcode      ← Shortcode badge + copy button
.wynk-card__stats          ← Stat pills (images, speed, curve)
.wynk-card__actions        ← Edit / Preview / Delete buttons
.wynk-settings-panel       ← Left column of edit view
.wynk-preview-panel        ← Right column (sticky)
.wynk-section              ← Settings section block
.wynk-section__heading     ← Section label
.wynk-range-row            ← Range slider + live value label row
.wynk-toggle               ← Toggle switch wrapper
.wynk-toggle__input        ← Hidden checkbox
.wynk-toggle__track        ← Visible pill track
.wynk-image-grid           ← Thumbnail drag-sort grid
.wynk-image-thumb          ← Individual thumbnail tile
.wynk-image-thumb__remove  ← × remove button
.wynk-modal                ← Full-screen preview overlay
.wynk-modal__close         ← Close button
.wynk-notice               ← Inline save/error notice
```

---

## 9. JavaScript API Surface

### 9.1 Public Engine (`wynkCurve`)

```js
// Initialize all sliders on page (called automatically on DOMContentLoaded)
wynkCurve.init()

// Destroy a specific slider instance
wynkCurve.destroy(instanceId: string): void

// Internal — not called externally
wynkCurve._instances: Map<string, InstanceObject>
```

### 9.2 Admin Namespace (`WynkAdmin`)

```js
WynkAdmin.init(): void              // Called on DOMContentLoaded
WynkAdmin.openMediaPicker(): void   // Opens wp.media() frame
WynkAdmin.renderThumbs(ids): void   // Renders draggable image grid
WynkAdmin.refreshPreview(): void    // Rebuilds Three.js preview scene
WynkAdmin.saveSlider(): void        // AJAX save, shows notice
WynkAdmin.deleteSlider(id): void    // AJAX delete with confirm + DOM fade
WynkAdmin.copyShortcode(text): void // Clipboard copy + tooltip
```

### 9.3 Localized Data

**Frontend** (per instance, via `wp_localize_script`):
```js
window.wynkSliders[instanceId] = {
  images: [{ id, url, width, height, alt }],
  settings: {
    speed, curve, gap, direction,
    height, autoplay, pauseHover, bgColor
  }
}
```

**Admin** (global, via `wp_localize_script`):
```js
window.wynkAdminData = {
  ajaxUrl: '/wp-admin/admin-ajax.php',
  nonce: '<wp_nonce>',
  uploadsUrl: 'https://example.com/wp-content/uploads/',
  pluginUrl: 'https://example.com/wp-content/plugins/webwynk-curve-slider/'
}
```

---

## 10. REST API Design

**Endpoint:** `GET /wp-json/wynk/v1/slider/{id}`

**Authentication:** Cookie auth (WordPress admin session)

**Permission:** `current_user_can('edit_posts')`

**Response:**
```json
{
  "id": 42,
  "title": "Homepage Hero",
  "images": [
    { "id": 12, "url": "https://...", "thumb": "https://...", "alt": "Alt text" }
  ],
  "settings": {
    "speed": 30,
    "curve": 12,
    "gap": 10,
    "direction": "left",
    "height": 400,
    "autoplay": true,
    "pauseHover": true,
    "bgColor": "#ffffff"
  }
}
```

> Note: No user data, capabilities, or private data is returned.

---

## 11. Security Design

| Attack Vector | Mitigation |
|---|---|
| CSRF | `wp_nonce_field` in forms; `check_ajax_referer` in all AJAX handlers |
| Privilege Escalation | `current_user_can('manage_options')` gate on all write operations |
| XSS (stored) | All meta values sanitized on save; all output escaped with WP functions |
| XSS (reflected) | `$_GET` params sanitized with `sanitize_text_field` / `absint` |
| SQL Injection | No raw queries; only WP API functions (`wp_insert_post`, `update_post_meta`) |
| File Inclusion | No dynamic require/include; all file paths are hardcoded constants |
| IDOR | Slider ID validated as `wynk_slider` post type before returning/modifying |

---

## 12. Browser Support

| Browser | Support |
|---|---|
| Chrome 80+ | ✅ Full |
| Firefox 78+ | ✅ Full |
| Safari 14+ | ✅ Full |
| Edge 80+ | ✅ Full |
| iOS Safari 14+ | ✅ Full |
| Internet Explorer | ❌ Not supported (no WebGL 2, no ES6) |

**Minimum requirement:** WebGL support. The plugin will not render on browsers/devices without WebGL; consider adding a graceful fallback (`<noscript>` or a static image fallback) in a future version.

---

## 13. Performance Considerations

| Concern | Design Decision |
|---|---|
| Script loading | Scripts loaded in footer; only enqueued on pages with the shortcode |
| Image loading | Async via `THREE.TextureLoader`; placeholder planes shown during load |
| resize handler | Debounced 150ms to avoid excessive rebuilds |
| form preview | Debounced 300ms to avoid rebuilding on every keypress |
| rAF delta cap | Max 100ms prevents jumps after tab switching |
| Memory cleanup | Full `dispose()` chain on resize + destroy to prevent GPU memory leaks |
| Multiple instances | Each slider fully independent; no shared state except `window.wynkSliders` map |
