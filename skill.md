# Skill: Build WordPress Plugin — Image Curve Slider by WebWynk

---

## Skill Identity

| Field        | Value                                      |
|--------------|--------------------------------------------|
| Skill Name   | `webwynk-curve-slider-build`               |
| Domain       | WordPress Plugin Development               |
| Tech Stack   | PHP 7.4+, Three.js r160, Vanilla CSS/JS    |
| Slug         | `webwynk-curve-slider`                     |
| Entry File   | `webwynk-curve-slider.php`                 |

---

## Purpose

This skill defines the full capability set required to build, test, and deliver the **Image Curve Slider by WebWynk** WordPress plugin. It documents what every developer or AI agent working on this codebase must understand and be able to do.

---

## Core Capabilities Required

### 1. PHP / WordPress Backend

| Capability | Details |
|---|---|
| Register Custom Post Types | `register_post_type('wynk_slider', [...])` with custom labels, `show_ui: true`, `show_in_menu: false` |
| Post Meta Management | `add_post_meta`, `update_post_meta`, `get_post_meta` for all `_wynk_*` keys |
| AJAX Handlers | `wp_ajax_wynk_cs_*` actions for save, delete, get |
| Nonce Verification | `check_ajax_referer` + `wp_nonce_field` on all forms |
| Input Sanitization | `sanitize_text_field`, `absint`, `sanitize_hex_color`, `in_array` checks, range clamping |
| Capability Checks | `manage_options` for write operations; `edit_posts` for reads |
| REST API Endpoint | `register_rest_route('wynk/v1', '/slider/(?P<id>\d+)', ...)` |
| Asset Enqueueing | `wp_enqueue_script`, `wp_enqueue_style`, `wp_localize_script` |
| Shortcode Registration | `add_shortcode('wynk_slider', [$this, 'render'])` |
| Admin Menu Registration | `add_menu_page(...)` with dashicons |
| Media Library Integration | `wp_enqueue_media()` + JS `wp.media()` API |
| Color Picker Integration | `wp_enqueue_style('wp-color-picker')` + init in admin JS |
| Output Escaping | `esc_html`, `esc_attr`, `esc_url`, `absint`, `wp_kses_post` |

### 2. Three.js / WebGL Frontend

| Capability | Details |
|---|---|
| Scene Setup | `THREE.Scene`, `THREE.PerspectiveCamera`, `THREE.WebGLRenderer` |
| Custom ShaderMaterial | Vertex shader for curve distortion; fragment shader for texture sampling |
| PlaneGeometry | `PlaneGeometry(1, 1, 20, 20)` with world-unit spacing math |
| TextureLoader | Async image loading with placeholder swap on load |
| rAF Animation Loop | `requestAnimationFrame` with `performance.now()` delta clamping |
| Seamless Loop | Scene X-position reset when `abs(scene.position.x) >= setWidth` |
| Responsive Rebuild | Dispose all geometries/materials/textures on resize, rebuild planes |
| Memory Cleanup | `geometry.dispose()`, `material.dispose()`, `texture.dispose()`, cancel rAF |
| IIFE Pattern | Entire engine inside `(function(window, THREE){ })(window, THREE)` |
| Instance Management | `window.wynkSliders[instanceId]` map, `wynkCurve.destroy(instanceId)` |

### 3. Vanilla JS Admin UI

| Capability | Details |
|---|---|
| HTML5 Drag & Drop | `draggable=true`, `dragstart`, `dragover`, `drop`, `dragend` events |
| Debounce Utility | 300ms debounce on form changes → preview rebuild; 150ms on resize |
| Clipboard API | `navigator.clipboard.writeText(shortcode)` with "Copied!" tooltip |
| DOM Manipulation | Card grid render, fade-out on delete, inline notices |
| Form State Collection | Read all inputs → build settings object → AJAX POST |

### 4. CSS Architecture

| Capability | Details |
|---|---|
| Prefix Discipline | All public selectors: `.wynk-curve-*`; all admin selectors scoped under `.wynk-admin-wrap` |
| CSS Grid | `repeat(auto-fill, minmax(280px, 1fr))` card grid |
| CSS Custom Properties | `--wynk-bg`, `--wynk-fade-width` injected per-instance via JS |
| Toggle Switch | Pure CSS pill toggle (no JS animation) |
| Glassmorphism / Card | `border-radius`, `box-shadow`, `object-fit: cover` |
| Responsive Breakpoints | `≥1100px` two-column form; `≤768px` stacked; `≤480px` mobile caps |

### 5. Security Practices

| Rule | Implementation |
|---|---|
| CSRF Protection | `wp_nonce_field` + `check_ajax_referer` on every AJAX action |
| Capability Gates | `current_user_can('manage_options')` for all write paths |
| Input Sanitization | Every `$_POST`/`$_GET` value sanitized before use or storage |
| Output Escaping | All PHP-to-HTML output escaped with appropriate WP functions |
| No Raw Echo | `wp_send_json_success` / `wp_send_json_error` only |
| REST Permission | `permission_callback` returns `current_user_can('edit_posts')` |

---

## Naming Conventions (Mandatory)

```
PHP functions   → wynk_cs_*           (e.g. wynk_cs_save_slider)
PHP classes     → WYNK_*              (e.g. WYNK_CPT, WYNK_Assets)
PHP constants   → WYNK_CS_*           (e.g. WYNK_CS_VERSION)
Post meta keys  → _wynk_*             (e.g. _wynk_speed)
CSS classes     → wynk-curve-*        (public), .wynk-admin-wrap (admin)
JS namespace    → window.WynkAdmin    (admin), wynkCurve (public)
JS data store   → window.wynkSliders  (per-instance map)
AJAX actions    → wynk_cs_*           (e.g. wynk_cs_save_slider)
REST namespace  → wynk/v1
```

---

## File Manifest

```
webwynk-curve-slider/
├── webwynk-curve-slider.php          ← Plugin entry, constants, bootstrap
├── includes/
│   ├── class-wynk-cpt.php            ← CPT + REST endpoint
│   ├── class-wynk-shortcode.php      ← [wynk_slider] rendering
│   └── class-wynk-assets.php         ← Enqueue logic (front + admin)
├── admin/
│   ├── admin-page.php                ← Dashboard, Create/Edit, Preview views
│   ├── admin-ajax.php                ← AJAX handlers (save, delete, get)
│   ├── js/
│   │   └── wynk-admin.js             ← Admin UI logic (WynkAdmin namespace)
│   └── css/
│       └── admin.css                 ← Admin styles (.wynk-admin-wrap scope)
├── public/
│   ├── js/
│   │   └── wynk-slider.js            ← Three.js engine (IIFE, no jQuery)
│   └── css/
│       └── wynk-slider.css           ← Frontend CSS (.wynk-curve-* prefix)
├── vendor/
│   └── three.min.js                  ← Three.js r160 UMD (bundled, no CDN)
└── README.md                         ← Installation + shortcode reference
```

---

## Key Constants

| Constant | Description |
|---|---|
| `WYNK_CS_VERSION` | Plugin version string (`'1.0.0'`) |
| `WYNK_CS_PATH` | Absolute filesystem path with trailing slash |
| `WYNK_CS_URL` | Plugin URL with trailing slash |

---

## Post Meta Reference

| Meta Key | Type | Range / Values | Default |
|---|---|---|---|
| `_wynk_images` | JSON array of int | attachment IDs | `[]` |
| `_wynk_speed` | int | 5 – 150 | 30 |
| `_wynk_curve` | int | 0 – 50 | 12 |
| `_wynk_gap` | int | 0 – 50 | 10 |
| `_wynk_direction` | string | `'left'` / `'right'` | `'left'` |
| `_wynk_height` | int | 200 – 800 (px) | 400 |
| `_wynk_autoplay` | bool int | 0 / 1 | 1 |
| `_wynk_pause_hover` | bool int | 0 / 1 | 1 |
| `_wynk_bg_color` | hex string | `#rrggbb` | `'#ffffff'` |

---

## Known Constraints & Gotchas

1. **Three.js must be bundled** — no CDN. Place `three.min.js` (r160 UMD build) in `/vendor/`. Script handle `wynk-three`.
2. **No jQuery in `wynk-slider.js`** — pure vanilla JS only in the public engine.
3. **jQuery is acceptable** in `wynk-admin.js` only for `wp.media()` integration (WP requires it).
4. **CPT must not appear in nav menus** — `show_in_menu: false`, menu registered manually.
5. **Shortcode only enqueues assets when needed** — use `has_shortcode()` on page content to gate frontend scripts.
6. **rAF delta clamping** — clamp to 100ms max to prevent animation jumps after tab-switch.
7. **Memory leaks** — always call `geometry.dispose()`, `material.dispose()`, `texture.dispose()` on resize and destroy.
8. **Mobile height cap** — `Math.min(configuredHeight, Math.round(window.innerWidth * 0.65))` on screens ≤480px.
9. **Seamless loop** — reset `time = 0` when `Math.abs(scene.position.x) >= setWidth` (not `>=` canvas width).
10. **Placeholder planes** — when no images are loaded, render 6 solid-color `ShaderMaterial` planes so preview is never blank.
