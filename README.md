# Image Curve Slider by WebWynk

A high-performance, premium 3D WebGL curved image slider plugin for WordPress. Powered by Three.js (r160) and custom GPU vertex/fragment shaders, it creates an elegant, continuously scrolling curved band of images that is fully responsive and customizable directly from a dedicated administrative dashboard.

---

## Key Features

- **3D WebGL Curvature:** Curves images dynamically in 3D space on the GPU using a custom vertex shader.
- **Continuous Scroll Loop:** Seamless scroll loop that automatically calculates positions and spacing.
- **Drag-and-Drop Reordering:** Sort and manage images in the WordPress admin panel using standard HTML5 drag-and-drop.
- **Real-time Live Preview:** Admin panel features a hot-updating live preview canvas that reflects setting changes instantly (speed, curve, gaps) without full-page reloads.
- **Responsive Heights:** Auto-adapts size to container and automatically caps height on mobile screens (≤480px) to maintain design aesthetics.
- **Performance Optimized:** Debounced resize listener, GPU resource cleanup on destroy, frame-rate independent physics loop using performance delta clamping, and automatic texture minification filtering.

---

## Installation

1. **Upload the plugin:**
   - Compress the `Image Curve Slider` folder into a `.zip` archive.
   - Go to your WordPress Admin Dashboard -> **Plugins** -> **Add New** -> **Upload Plugin**.
   - Select the zip file and click **Install Now**.
   
2. **Activate the plugin:**
   - Click **Activate Plugin** once the upload completes.

3. **Verify:**
   - A new menu item **Wynk Slider** will appear in the main admin sidebar menu just below Comments.

---

## Usage

### 1. Creating and Managing Sliders
1. Navigate to **Wynk Slider** in the WordPress menu.
2. Click **Create New Slider**.
3. Give your slider a descriptive name.
4. Click **Add / Change Images** to choose images from the WordPress Media Library.
5. Adjust settings in the left-hand column: speed, curve intensity, gap size, and direction. Observe changes instantly in the **Live Preview** panel on the right.
6. Click **Save Slider**. A success notice will appear displaying the unique shortcode.

### 2. Embedding in Pages/Posts
Copy the shortcode generated for your slider and paste it into the editor on any page, post, or widget area:

```text
[wynk_slider id="42"]
```

- If no images are selected or the slider is deleted, the shortcode will return an empty string (`''`) silently, ensuring no broken placeholders or raw text appear on the frontend.

---

## Parameter Table & Settings

When you edit a slider in the administrative panel, the following configurations are saved to the Custom Post Type metadata:

| Control Name | Meta Key | Data Type | Default | Range / Value | Description |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Slider Name** | `post_title` | String | *None* | Non-empty text | Title used to identify the slider in the dashboard. |
| **Slider Height** | `_wynk_height` | Integer | `400` | `200` to `800` (pixels) | The desktop height of the slider. Responsive mobile caps apply automatically. |
| **Images** | `_wynk_images` | JSON Array | `[]` | Array of attachment IDs | Ordered list of WordPress attachment IDs. Drag to reorder. |
| **Scroll Speed** | `_wynk_speed` | Integer | `30` | `5` to `150` | Speed of the automatic scrolling animation loop. |
| **Curve Intensity** | `_wynk_curve` | Integer | `12` | `0` to `50` | `0` renders a flat plane. `50` creates a strongly curved band of images. |
| **Image Gap** | `_wynk_gap` | Integer | `10` | `0` to `50` (%) | Visual spacing between plane meshes. |
| **Scroll Direction** | `_wynk_direction` | String | `left` | `left` or `right` | Direction of the auto-scroll animation. |
| **Autoplay** | `_wynk_autoplay` | Integer | `1` | `0` (off) or `1` (on) | Toggle automatic scrolling on load. |
| **Pause on Hover** | `_wynk_pause_hover`| Integer | `1` | `0` (off) or `1` (on) | Pauses scrolling when mouse enters the slider area. |
| **Desktop Visible Images** | `_wynk_desktop_visible`| Integer | `8` | `3` to `12` | Controls how many image planes are shown simultaneously on desktop. |
| **Tablet Visible Images**  | `_wynk_tablet_visible` | Integer | `5` | `2` to `8`  | Controls how many image planes are shown simultaneously on tablet. |
| **Mobile Visible Images**  | `_wynk_mobile_visible` | Integer | `3` | `1` to `5`  | Controls how many image planes are shown simultaneously on mobile. |

---

## Technical details & Architecture

### Backend (PHP)
- **Custom Post Type (`wynk_slider`):** Registered with `public => false` and `show_ui => true` to keep edit screens clean while maintaining WP capabilities.
- **REST API Route (`/wp-json/wynk/v1/slider/{id}`):** Secure, authenticated REST route used to query slider assets and details for dashboard previews.
- **AJAX Endpoints:** Secure WordPress AJAX hooks (`wynk_cs_save_slider`, `wynk_cs_delete_slider`, `wynk_cs_get_slider`) utilizing WP nonces for validation.

### Frontend Engine (WebGL & JavaScript)
- **Shader Customizations:**
  - **Vertex Shader:** Distorts plane vertices along the Y-axis based on distance from the camera view center to create the curved projection.
  - **Fragment Shader:** Binds texture coordinates. If an image is slow to load or fails, it falls back to custom-colored solid placeholders to ensure the slider remains functional.
- **Resource Management:** Whenever settings are changed or sliders are deleted, active Three.js elements, textures, materials, and geometries are explicitly disposed (`.dispose()`) and the `requestAnimationFrame` loop is cancelled to prevent CPU/GPU memory leaks.

---

## Compatibility

- **WordPress:** Version 5.8 or higher.
- **PHP:** Version 7.4 or higher.
- **Browsers:** Modern versions of Chrome, Safari, Firefox, Edge, and mobile browsers (iOS/Android) with full WebGL support.
