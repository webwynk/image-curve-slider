/**
 * WebGL Curved Image Slider — Frontend Engine
 * Image Curve Slider by WebWynk
 *
 * Renders images on a continuously scrolling curved band using Three.js (r160).
 * Runs entirely in an IIFE; exposes only window.wynkCurve.
 *
 * Public API:
 *   wynkCurve.init()                       — auto-init all .wynk-curve-slider-wrap elements
 *   wynkCurve.initInstance(id, el) → ctx   — init a specific instance (used by admin preview)
 *   wynkCurve.destroy(id)                  — dispose GPU resources, cancel rAF
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

/* global THREE */

;(function (window, THREE) {
  'use strict';

  // ── Step 6.1 — Guard ──────────────────────────────────────
  if (!THREE) {
    console.error('[WynkSlider] THREE.js is not loaded. Please ensure vendor/three.min.js is enqueued before wynk-slider.js.');
    return;
  }

  // ── Instance registry ──────────────────────────────────────
  /** @type {Object.<string, Object>} instanceId → context */
  var _instances = {};

  // ── Resize debounce handle ─────────────────────────────────
  var _resizeTimer = null;

  // ── Shared GLSL shaders (Step 6.4) ───────────────────────
  // Vertex shader: curves planes by scaling Y based on world-space X distance.
  // The 'curve' uniform maps 0 → flat, 50 → strongly curved.
  var VERTEX_SHADER = [
    'uniform float curve;',
    'varying vec2 vUV;',
    'void main() {',
    '  vUV = uv;',
    '  vec3 pos = position;',
    '  float dist = abs((modelMatrix * vec4(position, 1.0)).x);',
    '  pos.y *= 1.0 + (curve / 100.0) * pow(dist, 2.0);',
    '  gl_Position = projectionMatrix * modelViewMatrix * vec4(pos, 1.0);',
    '}'
  ].join('\n');

  // Fragment shader: samples texture OR renders a solid colour for placeholders.
  var FRAGMENT_SHADER = [
    'uniform sampler2D tex;',
    'uniform vec3 solidColor;',
    'uniform float useSolidColor;',
    'varying vec2 vUV;',
    'void main() {',
    '  if (useSolidColor > 0.5) {',
    '    gl_FragColor = vec4(solidColor, 1.0);',
    '  } else {',
    '    gl_FragColor = texture2D(tex, vUV);',
    '  }',
    '}'
  ].join('\n');

  // ============================================================
  // Step 6.1 — init(): find all wrappers and initialise
  // ============================================================

  /**
   * Auto-initialise every .wynk-curve-slider-wrap element on the page.
   * Called automatically on DOMContentLoaded.
   */
  function init() {
    var wrappers = document.querySelectorAll('.wynk-curve-slider-wrap');
    wrappers.forEach(function (el) {
      var id = el.id;
      if (!id || !window.wynkSliders || !window.wynkSliders[id]) return;
      initInstance(id, el);
    });
  }

  // ============================================================
  // Step 6.2 + 6.3 — initInstance(): per-element setup
  // ============================================================

  /**
   * Initialise a single slider instance.
   *
   * @param  {string}  instanceId  Key in window.wynkSliders.
   * @param  {Element} el          .wynk-curve-slider-wrap DOM element.
   * @return {Object|null}         The context object, or null on failure.
   */
  function initInstance(instanceId, el) {
    if (!el || !window.wynkSliders || !window.wynkSliders[instanceId]) return null;

    // Destroy any existing instance occupying this ID (e.g. admin preview rebuild).
    if (_instances[instanceId]) {
      destroy(instanceId);
    }

    var data     = window.wynkSliders[instanceId];
    var settings = data.settings;

    // ── Step 6.8 — Mobile height cap ──────────────────────
    var configuredHeight = settings.height || 400;
    var appliedHeight    = applyMobileHeightCap(el, configuredHeight);

    // ── Step 6.2 — Context object ──────────────────────────
    var ctx = {
      el:         el,
      instanceId: instanceId,
      data:       data,
      scene:      null,
      camera:     null,
      renderer:   null,
      planes:     [],
      textures:   [],
      time:       0,
      lastTick:   null,
      rafId:      null,
      paused:     false,
      width:      el.clientWidth  || 800,
      height:     appliedHeight,
    };

    _instances[instanceId] = ctx;

    // ── Step 6.3 — Camera ──────────────────────────────────
    ctx.camera = new THREE.PerspectiveCamera(
      75,                          // FOV
      ctx.width / ctx.height,      // aspect
      0.1,                         // near
      20                           // far
    );
    ctx.camera.position.z = 2;

    // ── Step 6.3 — Scene ───────────────────────────────────
    ctx.scene = new THREE.Scene();

    // ── Step 6.3 — Renderer ────────────────────────────────
    var canvas = el.querySelector('.wynk-curve-canvas');
    if (!canvas) {
      console.error('[WynkSlider] Canvas element not found in wrapper:', instanceId);
      delete _instances[instanceId];
      return null;
    }

    ctx.renderer = new THREE.WebGLRenderer({
      canvas:    canvas,
      alpha:     true,
      antialias: true,
    });
    ctx.renderer.setSize(ctx.width, ctx.height);
    ctx.renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

    // ── Step 6.5 — Build plane meshes ─────────────────────
    buildPlanes(ctx);

    // ── Step 6.9 — Hover pause ─────────────────────────────
    if (settings.pauseHover) {
      el.addEventListener('mouseenter', function () { ctx.paused = true;  });
      el.addEventListener('mouseleave', function () { ctx.paused = false; });
    }

    // ── Step 6.6 — Start animation loop ───────────────────
    animate(ctx);

    // ── Fade overlay width ─────────────────────────────────
    updateFadeWidth(el, ctx.width);

    return ctx;
  }

  // ============================================================
  // Step 6.5 — buildPlanes(): create / recreate all plane meshes
  // ============================================================

  /**
   * Create Three.js Mesh objects for every image slot in the scroll band.
   * Disposes any previously created geometries and materials first.
   *
   * @param {Object} ctx Instance context.
   */
  function buildPlanes(ctx) {
    var data     = ctx.data;
    var settings = data.settings;
    var images   = data.images;

    if (!images || images.length === 0) return;

    var gap     = settings.gap / 100;
    var dirSign = settings.direction === 'right' ? 1 : -1;

    // ── Dynamic visible images scaling math ──────────────────
    var targetVisible = 8; // default desktop visible
    if (ctx.width <= 480) {
      targetVisible = settings.mobileVisible || 3;
    } else if (ctx.width <= 1024) {
      targetVisible = settings.tabletVisible || 5;
    } else {
      targetVisible = settings.desktopVisible || 8;
    }

    // ── World-unit math ────────────────────────────────────
    // getWorldWidth returns pixels-per-world-unit; invert to get world-per-pixel.
    var pxPerUnit  = getWorldWidth(ctx.camera, ctx.el);
    var unitPerPx  = 1 / pxPerUnit;
    var worldW     = ctx.width * unitPerPx;
    var spacing    = worldW / targetVisible;   // world units between plane centres
    ctx.spacing    = spacing;                  // save spacing to context for loop calculations
    var scale      = spacing / (1 + gap);      // calculated plane width scale factor

    // Number of planes needed to fill the viewport + image set.
    var planesPerView = Math.ceil(worldW / spacing);
    var totalPlanes   = planesPerView + 2 + images.length;
    var initOffset    = Math.floor(planesPerView / 2);

    // ── Dispose existing planes ────────────────────────────
    ctx.planes.forEach(function (p) {
      if (p.geometry) p.geometry.dispose();
      if (p.material) {
        if (p.material.uniforms && p.material.uniforms.tex && p.material.uniforms.tex.value) {
          p.material.uniforms.tex.value.dispose();
        }
        p.material.dispose();
      }
      ctx.scene.remove(p);
    });
    ctx.planes   = [];
    ctx.textures = [];

    var loader = new THREE.TextureLoader();

    for (var i = 0; i < totalPlanes; i++) {
      var imgData = images[i % images.length];

      // Aspect ratio clamped to a reasonable portrait–landscape range.
      var imgW   = imgData.width  || 800;
      var imgH   = imgData.height || 600;
      var aspect = clamp(imgH / imgW, 0.9, 1.6);

      // ── Geometry ─────────────────────────────────────────
      // 20×20 segments give the vertex shader enough vertices to
      // produce a smooth curve without too many triangles.
      var geo = new THREE.PlaneGeometry(scale, scale * aspect, 20, 20);

      // ── Material ──────────────────────────────────────────
      var isSolid = !imgData.url;
      var mat = new THREE.ShaderMaterial({
        uniforms: {
          curve:        { value: settings.curve },
          tex:          { value: new THREE.Texture() },
          solidColor:   { value: new THREE.Color(imgData.color || '#c0c0c0') },
          useSolidColor:{ value: isSolid ? 1.0 : 1.0}, // start solid; swap on load
        },
        vertexShader:   VERTEX_SHADER,
        fragmentShader: FRAGMENT_SHADER,
        transparent:    true,
        side:           THREE.FrontSide,
      });

      // ── Plane positioning ─────────────────────────────────
      var plane = new THREE.Mesh(geo, mat);
      plane.position.x = -dirSign * (i - initOffset) * spacing;
      ctx.scene.add(plane);
      ctx.planes.push(plane);

      // ── Async texture load ────────────────────────────────
      if (imgData.url) {
        // IIFE captures mat so the closure doesn't reference the loop variable.
        (function (capMat, capCtx) {
          loader.load(
            imgData.url,
            function (texture) {
              // Success: replace placeholder color with the loaded texture.
              texture.minFilter = THREE.LinearFilter;
              texture.magFilter = THREE.LinearFilter;
              if (capMat.uniforms.tex.value) capMat.uniforms.tex.value.dispose();
              capMat.uniforms.tex.value          = texture;
              capMat.uniforms.useSolidColor.value = 0.0;
              capMat.needsUpdate = true;
              capCtx.textures.push(texture);
            },
            undefined,
            function () {
              // Error: keep solid color placeholder — no crash.
              console.warn('[WynkSlider] Failed to load image:', imgData.url);
            }
          );
        }(mat, ctx));
      }
    }
  }

  // ============================================================
  // Step 6.6 — animate(): rAF loop
  // ============================================================

  /**
   * The per-frame render loop. Uses performance.now() delta for smooth,
   * frame-rate-independent animation. Delta is clamped to 100ms to
   * prevent large jumps after tab-switch or browser sleep.
   *
   * @param {Object} ctx Instance context.
   */
  function animate(ctx) {
    ctx.rafId = requestAnimationFrame(function () { animate(ctx); });

    var now   = performance.now();
    var delta = ctx.lastTick ? Math.min(now - ctx.lastTick, 100) : 16;
    ctx.lastTick = now;

    var settings = ctx.data.settings;
    var gap      = settings.gap / 100;
    var dirSign  = settings.direction === 'right' ? 1 : -1;

    // ── Advance time ───────────────────────────────────────
    if (!ctx.paused && settings.autoplay) {
      // Accumulate elapsed time in seconds.
      ctx.time += delta * 0.001;
    }

    // ── Seamless loop reset ────────────────────────────────
    // setWidth = the world-space width of one full image set.
    // period = the exact cycle time in seconds for the slider to traverse setWidth.
    var spacing  = ctx.spacing || 1.0;
    var setWidth = spacing * ctx.data.images.length;
    var speedW   = settings.speed * 0.01; // world units per second
    var period   = setWidth / speedW;

    if (ctx.time >= period) {
      ctx.time = ctx.time % period;
    }

    ctx.scene.position.x = ctx.time * speedW * dirSign;

    ctx.renderer.render(ctx.scene, ctx.camera);
  }

  // ============================================================
  // Step 6.7 — Resize handler (debounced 150ms)
  // ============================================================

  window.addEventListener('resize', function () {
    clearTimeout(_resizeTimer);
    _resizeTimer = setTimeout(function () {
      Object.keys(_instances).forEach(function (id) {
        var ctx  = _instances[id];
        var newW = ctx.el.clientWidth;

        // Step 6.8: recompute mobile height cap on resize.
        var newH = applyMobileHeightCap(ctx.el, ctx.data.settings.height || 400);

        var widthChanged  = newW !== ctx.width;
        var heightChanged = newH !== ctx.height;
        var isMobile      = newW < 768;

        // Rebuild if dimensions changed, or always on mobile (handles orientation change).
        if (widthChanged || heightChanged || isMobile) {
          ctx.width  = newW;
          ctx.height = newH;

          ctx.camera.aspect = newW / newH;
          ctx.camera.updateProjectionMatrix();
          ctx.renderer.setSize(newW, newH);

          updateFadeWidth(ctx.el, newW);
          buildPlanes(ctx);
        }
      });
    }, 150);
  });

  // ============================================================
  // Step 6.8 — Mobile height cap
  // ============================================================

  /**
   * Cap the slider height on small screens to prevent overly tall sliders.
   * On screens ≤480px: height = min(configuredHeight, floor(innerWidth × 0.65)).
   *
   * @param  {Element} el               Wrapper element.
   * @param  {number}  configuredHeight  Height from settings (px).
   * @return {number}                   Applied height in px.
   */
  function applyMobileHeightCap(el, configuredHeight) {
    var cap = (window.innerWidth <= 480)
      ? Math.min(configuredHeight, Math.round(window.innerWidth * 0.65))
      : configuredHeight;

    el.style.height = cap + 'px';
    return cap;
  }

  // ============================================================
  // Step 6.10 — destroy(): full GPU cleanup
  // ============================================================

  /**
   * Cancel the rAF loop and free all GPU resources for a slider instance.
   * Also removes the instance from the _instances registry.
   *
   * @param {string} instanceId
   */
  function destroy(instanceId) {
    var ctx = _instances[instanceId];
    if (!ctx) return;

    // ── Stop animation loop ────────────────────────────────
    cancelAnimationFrame(ctx.rafId);

    // ── Dispose plane geometries + materials + textures ────
    ctx.planes.forEach(function (p) {
      if (p.geometry) p.geometry.dispose();
      if (p.material) {
        // Dispose the texture stored in the tex uniform.
        if (p.material.uniforms && p.material.uniforms.tex && p.material.uniforms.tex.value) {
          p.material.uniforms.tex.value.dispose();
        }
        p.material.dispose();
      }
      if (ctx.scene) ctx.scene.remove(p);
    });

    // ── Dispose any additionally tracked textures ──────────
    ctx.textures.forEach(function (t) {
      if (t && t.dispose) t.dispose();
    });

    // ── Dispose renderer ───────────────────────────────────
    if (ctx.renderer) {
      ctx.renderer.dispose();
      // Don't remove the canvas from the DOM — it may be reused by a new instance.
    }

    // ── Clean up registry ──────────────────────────────────
    delete _instances[instanceId];

    // Leave window.wynkSliders[instanceId] in place — the shortcode may render
    // again without a page reload (e.g. SPA / partial refresh scenarios).
  }

  // ============================================================
  // Helper: getWorldWidth
  // ============================================================

  /**
   * Return the number of pixels per Three.js world unit at z=0 for this camera.
   * Used to convert between pixel measurements and world-unit plane spacing.
   *
   * @param  {THREE.PerspectiveCamera} camera
   * @param  {Element}                 el      Wrapper element.
   * @return {number}                          Pixels per world unit.
   */
  function getWorldWidth(camera, el) {
    var vFov   = camera.fov * Math.PI / 180;
    var worldH = 2 * Math.tan(vFov / 2) * camera.position.z;
    var worldW = worldH * (el.clientWidth / el.clientHeight);
    return el.clientWidth / worldW; // pixels per world unit
  }



  // ============================================================
  // Helper: updateFadeWidth (Section 11)
  // ============================================================

  /**
   * Set the --wynk-fade-width CSS custom property on the wrapper element.
   * Controls the left/right gradient fade overlay width.
   *
   * @param {Element} el
   * @param {number}  containerWidth
   */
  function updateFadeWidth(el, containerWidth) {
    var fadeWidth;
    if (containerWidth <= 480)      fadeWidth = 30;
    else if (containerWidth <= 768) fadeWidth = 50;
    else                            fadeWidth = 80;

    el.style.setProperty('--wynk-fade-width', fadeWidth + 'px');
  }

  // ============================================================
  // Helper: clamp
  // ============================================================

  /**
   * @param {number} v
   * @param {number} min
   * @param {number} max
   * @return {number}
   */
  function clamp(v, min, max) {
    return Math.max(min, Math.min(max, v));
  }

  // ============================================================
  // Public API — exposed as window.wynkCurve
  // ============================================================

  window.wynkCurve = {
    /** Auto-init all .wynk-curve-slider-wrap elements on the page. */
    init:        init,

    /** Init a specific instance (instanceId, el) → returns context or null. */
    initInstance: initInstance,

    /** Destroy a specific instance by ID, freeing all GPU resources. */
    destroy:     destroy,
  };

  // ── Auto-init on DOMContentLoaded ──────────────────────────
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    // DOM already parsed (e.g. script in footer after content).
    init();
  }

}(window, window.THREE));
