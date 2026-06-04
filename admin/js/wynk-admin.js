/**
 * Admin JavaScript — Image Curve Slider by WebWynk
 *
 * All logic lives inside window.WynkAdmin to prevent global namespace pollution.
 * jQuery is used only for $.post() (required for wp.media integration) and
 * jQuery is used only for $.post() (required for wp.media integration).
 * All DOM manipulation is vanilla JS.
 *
 * Exposes:
 *   WynkAdmin.init()           — wire all event listeners (called on DOMContentLoaded)
 *   WynkAdmin.openMediaPicker()
 *   WynkAdmin.renderThumbs()
 *   WynkAdmin.refreshPreview()
 *   WynkAdmin.saveSlider()
 *   WynkAdmin.deleteSlider(id, cardEl)
 *   WynkAdmin.copyShortcode(text, triggerEl)
 *
 * @package WebWynk_Curve_Slider
 * @since   1.0.0
 */

/* global jQuery, wp, wynkAdminData, wynkCurve */

window.WynkAdmin = (function ($) {
  'use strict';

  // ============================================================
  // Step 4.1 — Internal state object
  // ============================================================

  /**
   * Shared mutable state for this module.
   * @type {Object}
   */
  var state = {
    /** @type {number[]}           Ordered array of WP attachment IDs. */
    imageIds: [],

    /** @type {Object.<number,{id:number,url:string,thumb:string,alt:string}>}
     *  Map of attachment ID → image data (populated from wp.media selection). */
    imageData: {},

    /** @type {string}             Fixed instance ID for the admin live preview. */
    previewInstanceId: 'wynk-admin-preview',

    /** @type {Object|null}        Active Three.js context returned by wynkCurve.initInstance(). */
    previewCtx: null,

    /** @type {number|null}        Index of the thumb tile currently being dragged. */
    dragSrcIdx: null,

    /** @type {wp.media.view.MediaFrame|null}  wp.media frame, created once and reused. */
    mediaFrame: null,
  };

  // ============================================================
  // Step 4.5 — Preview debounce state
  // ============================================================

  var previewDebounceTimer = null;

  /**
   * True when the next preview refresh must do a full Three.js scene rebuild
   * (image set changed) rather than a uniform hot-update (settings changed).
   * @type {boolean}
   */
  var previewNeedsRebuild = false;

  // ============================================================
  // Step 4.2 — WordPress Media Library picker
  // ============================================================

  /**
   * Open the WP media library frame.
   * The frame is created once and re-opened on subsequent calls.
   *
   * Called when the user clicks "Add / Change Images".
   * After selection: updates state.imageIds, state.imageData, rerenders
   * the thumb grid, and triggers a full preview rebuild.
   */
  function openMediaPicker() {
    if (state.mediaFrame) {
      // Re-open the existing frame (faster, retains previous selection state).
      state.mediaFrame.open();
      return;
    }

    state.mediaFrame = wp.media({
      title:   wynkAdminData.i18n ? wynkAdminData.i18n.mediaTitle : 'Select Slider Images',
      button:  { text: wynkAdminData.i18n ? wynkAdminData.i18n.mediaButton : 'Add to Slider' },
      multiple: true,
      library:  { type: 'image' },
    });

    state.mediaFrame.on('select', function () {
      var attachments = state.mediaFrame.state().get('selection').toJSON();

      attachments.forEach(function (att) {
        // De-duplicate: only add IDs not already present.
        if (state.imageIds.indexOf(att.id) === -1) {
          state.imageIds.push(att.id);
        }

        // Cache image data for thumb rendering and preview use.
        state.imageData[att.id] = {
          id:     att.id,
          url:    att.url,                                  // full-size URL
          thumb:  att.sizes && att.sizes.thumbnail
                    ? att.sizes.thumbnail.url               // WP thumbnail size
                    : (att.sizes && att.sizes.medium
                        ? att.sizes.medium.url
                        : att.url),
          width:  att.width  || 800,
          height: att.height || 600,
          alt:    att.alt    || att.title || '',
          color:  null,                                     // not a placeholder
        };
      });

      renderThumbs();
      schedulePreviewRefresh(true); // image set changed → full rebuild
    });

    state.mediaFrame.open();
  }

  // ============================================================
  // Step 4.3 — Thumbnail grid renderer
  // ============================================================

  /**
   * Re-render the draggable image thumbnail grid from state.imageIds.
   * Also updates the hidden #wynk-images-json input.
   */
  function renderThumbs() {
    var grid   = document.getElementById('wynk-image-grid');
    var hidden = document.getElementById('wynk-images-json');

    if (!grid || !hidden) return;

    grid.innerHTML = '';

    if (state.imageIds.length === 0) {
      var emptyMsg = document.createElement('p');
      emptyMsg.className = 'wynk-thumb-empty';
      emptyMsg.textContent = 'No images selected yet.';
      grid.appendChild(emptyMsg);
      hidden.value = '[]';
      return;
    }

    state.imageIds.forEach(function (id, idx) {
      var data = state.imageData[id];

      var tile = document.createElement('div');
      tile.className   = 'wynk-image-thumb';
      tile.draggable   = true;
      tile.dataset.idx = idx;
      tile.dataset.id  = id;
      tile.setAttribute('aria-label', 'Image ' + (idx + 1) + '. Drag to reorder.');
      tile.setAttribute('role', 'listitem');

      // Thumbnail image.
      var img = document.createElement('img');
      img.src     = data ? data.thumb : '';
      img.alt     = data ? (data.alt || '') : '';
      img.loading = 'lazy';
      tile.appendChild(img);

      // Drag handle indicator.
      var handle = document.createElement('span');
      handle.className    = 'wynk-image-thumb__handle';
      handle.textContent  = '⠿';
      handle.setAttribute('aria-hidden', 'true');
      tile.appendChild(handle);

      // Remove (×) button.
      var rmBtn = document.createElement('button');
      rmBtn.type        = 'button';
      rmBtn.className   = 'wynk-image-thumb__remove';
      rmBtn.textContent = '×';
      rmBtn.dataset.idx = idx;
      rmBtn.setAttribute('aria-label', 'Remove image ' + (idx + 1));
      rmBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        var removeIdx = parseInt(this.dataset.idx, 10);
        state.imageIds.splice(removeIdx, 1);
        renderThumbs();
        schedulePreviewRefresh(true); // image set changed → full rebuild
      });
      tile.appendChild(rmBtn);

      // Drag-and-drop events (Step 4.4).
      tile.addEventListener('dragstart', onDragStart);
      tile.addEventListener('dragover',  onDragOver);
      tile.addEventListener('drop',      onDrop);
      tile.addEventListener('dragend',   onDragEnd);

      grid.appendChild(tile);
    });

    hidden.value = JSON.stringify(state.imageIds);
  }

  // ============================================================
  // Step 4.4 — HTML5 drag-and-drop reorder
  // ============================================================

  /** @param {DragEvent} e */
  function onDragStart(e) {
    state.dragSrcIdx = parseInt(this.dataset.idx, 10);
    e.dataTransfer.effectAllowed = 'move';
    // Required for Firefox.
    e.dataTransfer.setData('text/plain', String(state.dragSrcIdx));
    this.classList.add('is-dragging');
  }

  /** @param {DragEvent} e */
  function onDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    // Visual indicator: highlight the drop target.
    document.querySelectorAll('.wynk-image-thumb').forEach(function (el) {
      el.classList.remove('is-drop-target');
    });
    this.classList.add('is-drop-target');
    return false;
  }

  /** @param {DragEvent} e */
  function onDrop(e) {
    e.stopPropagation();
    var toIdx = parseInt(this.dataset.idx, 10);

    if (state.dragSrcIdx !== null && state.dragSrcIdx !== toIdx) {
      var moved = state.imageIds.splice(state.dragSrcIdx, 1)[0];
      state.imageIds.splice(toIdx, 0, moved);
      renderThumbs();
      schedulePreviewRefresh(true); // order changed → full rebuild
    }

    return false;
  }

  function onDragEnd() {
    document.querySelectorAll('.wynk-image-thumb').forEach(function (el) {
      el.classList.remove('is-dragging');
      el.classList.remove('is-drop-target');
    });
    state.dragSrcIdx = null;
  }

  // ============================================================
  // Step 4.5 — Live preview (hot-update + full rebuild)
  // ============================================================

  /**
   * Schedule a preview refresh, debounced 300ms.
   *
   * @param {boolean} [forceRebuild=false]
   *   Pass true when the image set changes — forces a full Three.js
   *   scene rebuild instead of a uniform hot-update.
   */
  function schedulePreviewRefresh(forceRebuild) {
    if (forceRebuild) previewNeedsRebuild = true;
    clearTimeout(previewDebounceTimer);
    previewDebounceTimer = setTimeout(refreshPreview, 300);
  }

  /**
   * Refresh the live preview panel.
   *
   * Two paths:
   *  1. Full rebuild — first load, or image set changed.
   *     Destroys existing scene, builds new one from scratch.
   *  2. Hot-update — only settings changed.
   *     Updates ShaderMaterial uniforms and scene data in-place;
   *     zero GPU teardown overhead.
   */
  function refreshPreview() {
    var previewCanvas = document.getElementById('wynk-preview-canvas');
    if (!previewCanvas) return; // Not on the edit view.

    var settings = collectFormValues();
    var ctx      = state.previewCtx;

    // ── Full rebuild path ──────────────────────────────────────
    if (!ctx || previewNeedsRebuild) {
      previewNeedsRebuild = false;

      // Destroy previous scene to free GPU memory.
      if (ctx && window.wynkCurve) {
        wynkCurve.destroy(state.previewInstanceId);
        state.previewCtx = null;
      }

      var images = (state.imageIds.length > 0)
        ? state.imageIds
            .map(function (id) { return state.imageData[id]; })
            .filter(Boolean)
        : generatePlaceholders(6);

      window.wynkSliders = window.wynkSliders || {};
      window.wynkSliders[state.previewInstanceId] = {
        images:   images,
        settings: settings,
      };

      // Mount the engine into the preview wrapper.
      var previewEl = document.getElementById('wynk-admin-preview');
      if (previewEl && window.wynkCurve) {
        // Update parent container height to match the configured value for smooth transition.
        var canvasWrap = previewEl.closest('.wynk-preview-panel__canvas-wrap');
        if (canvasWrap) canvasWrap.style.height = settings.height + 'px';



        state.previewCtx = wynkCurve.initInstance(state.previewInstanceId, previewEl);
      }
      return;
    }



    // Propagate all other settings to the running animation loop.
    if (ctx.data && ctx.data.settings) {
      ctx.data.settings.speed          = settings.speed;
      ctx.data.settings.gap            = settings.gap;
      ctx.data.settings.direction      = settings.direction;
      ctx.data.settings.autoplay       = settings.autoplay;
      ctx.data.settings.pauseHover     = settings.pauseHover;

      ctx.data.settings.height         = settings.height;
      ctx.data.settings.desktopVisible = settings.desktopVisible;
      ctx.data.settings.tabletVisible  = settings.tabletVisible;
      ctx.data.settings.mobileVisible  = settings.mobileVisible;
    }

    // Update the preview panel height live and background color.
    var previewEl = document.getElementById('wynk-admin-preview');
    if (previewEl) {
      var canvasWrap = previewEl.closest('.wynk-preview-panel__canvas-wrap');
      if (canvasWrap) canvasWrap.style.height = settings.height + 'px';
    }
  }

  /**
   * Generate N placeholder image objects for the preview when no real
   * images have been selected yet.
   *
   * @param  {number} count
   * @return {Array}
   */
  function generatePlaceholders(count) {
    var colors = ['#c9b3f5', '#aecff5', '#a9f0d1', '#f5d5a1', '#f5b3cc', '#b3f5c1'];
    return colors.slice(0, count).map(function (c, i) {
      return {
        id:     'ph' + i,
        url:    null,     // signals the engine to render solid color
        width:  800,
        height: 600,
        alt:    '',
        color:  c,
      };
    });
  }

  // ============================================================
  // Step 4.6 — Collect all form values into a settings object
  // ============================================================

  /**
   * Read current form state and return a normalised settings object.
   * Matches the shape expected by the Three.js engine.
   *
   * @return {{speed:number, gap:number, height:number,
   *           direction:string, autoplay:boolean, pauseHover:boolean}}
   */
  function collectFormValues() {
    var dirChecked = document.querySelector('input[name="direction"]:checked');

    return {
      speed:          parseInt(document.getElementById('wynk-speed').value,  10) || 30,
      gap:            parseInt(document.getElementById('wynk-gap').value,    10) || 10,
      height:         parseInt(document.getElementById('wynk-height').value, 10) || 400,
      direction:      dirChecked ? dirChecked.value : 'left',
      autoplay:       document.getElementById('wynk-autoplay')    ? document.getElementById('wynk-autoplay').checked    : true,
      pauseHover:     document.getElementById('wynk-pause-hover') ? document.getElementById('wynk-pause-hover').checked : true,

      desktopVisible: parseInt(document.getElementById('wynk-desktop-visible').value, 10) || 8,
      tabletVisible:  parseInt(document.getElementById('wynk-tablet-visible').value, 10) || 5,
      mobileVisible:  parseInt(document.getElementById('wynk-mobile-visible').value, 10) || 3,
    };
  }

  // ============================================================
  // Step 4.7 — AJAX save
  // ============================================================

  /**
   * Save the current slider via AJAX (insert or update).
   * Shows an inline success/error notice after the response.
   */
  function saveSlider() {
    var nameEl = document.getElementById('wynk-slider-name');
    if (nameEl && nameEl.value.trim() === '') {
      showNotice('error', 'Please enter a slider name before saving.');
      nameEl.focus();
      return;
    }

    var saveBtn = document.getElementById('wynk-save-btn');
    if (saveBtn) {
      saveBtn.disabled = true;
      saveBtn.classList.add('is-loading');
    }

    var values = collectFormValues();

    var data = {
      action:          'wynk_cs_save_slider',
      nonce:           wynkAdminData.nonce,
      id:              document.getElementById('wynk-slider-id').value,
      title:           nameEl ? nameEl.value.trim() : '',
      images:          JSON.stringify(state.imageIds),
      speed:           values.speed,
      gap:             values.gap,
      height:          values.height,
      direction:       values.direction,

      autoplay:        values.autoplay    ? 1 : 0,
      pause_hover:     values.pauseHover  ? 1 : 0,
      desktop_visible: values.desktopVisible,
      tablet_visible:  values.tabletVisible,
      mobile_visible:  values.mobileVisible,
    };

    $.post(wynkAdminData.ajaxUrl, data)
      .done(function (res) {
        if (res && res.success) {
          // Update hidden ID field (important for first-save of a new slider).
          document.getElementById('wynk-slider-id').value = res.data.id;
          showNotice('success', res.data.shortcode);
        } else {
          var msg = (res && res.data && res.data.message) ? res.data.message : 'Save failed. Please try again.';
          showNotice('error', msg);
        }
      })
      .fail(function () {
        showNotice('error', 'Network error — please check your connection and try again.');
      })
      .always(function () {
        if (saveBtn) {
          saveBtn.disabled = false;
          saveBtn.classList.remove('is-loading');
        }
      });
  }

  // ============================================================
  // Step 4.8 — AJAX delete
  // ============================================================

  /**
   * Delete a slider after user confirmation.
   * On success: fade out the card then remove it from the DOM.
   *
   * @param {number} id      Slider post ID.
   * @param {Element} cardEl The .wynk-card DOM element.
   */
  function deleteSlider(id, cardEl) {
    var confirmMsg = (cardEl && cardEl.querySelector('[data-confirm]'))
      ? cardEl.querySelector('[data-confirm]').dataset.confirm
      : 'Delete this slider? This cannot be undone.';

    if (!window.confirm(confirmMsg)) return;

    $.post(wynkAdminData.ajaxUrl, {
      action: 'wynk_cs_delete_slider',
      nonce:  wynkAdminData.nonce,
      id:     id,
    })
    .done(function (res) {
      if (res && res.success) {
        if (cardEl) {
          cardEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease';
          cardEl.style.opacity    = '0';
          cardEl.style.transform  = 'scale(0.95)';
          setTimeout(function () {
            cardEl.remove();
            // If grid is now empty, show a simple empty-state message.
            var grid = document.querySelector('.wynk-card-grid');
            if (grid && grid.children.length === 0) {
              grid.insertAdjacentHTML(
                'afterend',
                '<p class="wynk-no-sliders">' +
                  'All sliders deleted. <a href="' + wynkAdminData.createUrl + '">Create your first one</a>.' +
                '</p>'
              );
              grid.remove();
            }
          }, 420);
        }
      } else {
        var msg = (res && res.data && res.data.message) ? res.data.message : 'Delete failed. Please try again.';
        window.alert(msg);
      }
    })
    .fail(function () {
      window.alert('Network error — could not delete the slider. Please try again.');
    });
  }

  // ============================================================
  // Step 4.9 — Shortcode clipboard copy
  // ============================================================

  /**
   * Copy shortcode text to clipboard.
   * Shows "Copied!" on the trigger element for 1.5 s, then restores original label.
   *
   * @param {string}  text       Shortcode string to copy.
   * @param {Element} triggerEl  Button that was clicked.
   */
  function copyShortcode(text, triggerEl) {
    if (!navigator.clipboard) {
      // Fallback for older browsers.
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity  = '0';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      flashCopied(triggerEl);
      return;
    }

    navigator.clipboard.writeText(text).then(function () {
      flashCopied(triggerEl);
    }).catch(function () {
      window.alert('Could not copy — please copy manually: ' + text);
    });
  }

  /**
   * Flash "Copied!" text on a button for 1.5 s then restore original content.
   *
   * @param {Element} el
   */
  function flashCopied(el) {
    if (!el) return;
    var original = el.innerHTML;
    el.innerHTML = '<span class="dashicons dashicons-yes"></span> Copied!';
    el.classList.add('is-copied');
    setTimeout(function () {
      el.innerHTML = original;
      el.classList.remove('is-copied');
    }, 1500);
  }

  // ============================================================
  // Inline notice renderer
  // ============================================================

  /**
   * Show (or replace) the inline notice below the page header.
   *
   * @param {'success'|'error'} type
   * @param {string}            shortcodeOrMessage
   *   On success: pass the shortcode string — a copy button is added.
   *   On error:   pass the error message string.
   */
  function showNotice(type, shortcodeOrMessage) {
    var area = document.getElementById('wynk-notice-area');
    if (!area) return;

    if (type === 'success') {
      area.innerHTML =
        '<div class="wynk-notice wynk-notice--success">' +
          '<span class="dashicons dashicons-yes-alt"></span>' +
          '<span>Slider saved! Shortcode: ' +
            '<code>' + escapeHtml(shortcodeOrMessage) + '</code>' +
          '</span>' +
          '<button type="button" class="wynk-inline-copy-btn" data-shortcode="' +
            escapeAttr(shortcodeOrMessage) + '">' +
            '<span class="dashicons dashicons-clipboard"></span> Copy' +
          '</button>' +
        '</div>';

      // Wire the inline copy button.
      var copyBtn = area.querySelector('.wynk-inline-copy-btn');
      if (copyBtn) {
        copyBtn.addEventListener('click', function () {
          copyShortcode(this.dataset.shortcode, this);
        });
      }
    } else {
      area.innerHTML =
        '<div class="wynk-notice wynk-notice--error">' +
          '<span class="dashicons dashicons-warning"></span>' +
          '<span>' + escapeHtml(shortcodeOrMessage) + '</span>' +
        '</div>';
    }

    // Scroll notice into view.
    area.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }

  // ============================================================
  // Preview modal (dashboard "Preview" button)
  // ============================================================

  /**
   * Open the full-screen preview modal and mount a slider instance inside it.
   *
   * @param {number} sliderId  Slider post ID.
   */
  function openPreviewModal(sliderId) {
    var modal = document.getElementById('wynk-preview-modal');
    if (!modal) return;

    var inner = document.getElementById('wynk-modal-inner');
    if (inner) inner.innerHTML = '<div class="wynk-modal-loading">Loading preview…</div>';

    modal.setAttribute('aria-hidden', 'false');
    document.body.classList.add('wynk-modal-open');

    // Fetch slider data via AJAX.
    $.get(wynkAdminData.ajaxUrl, {
      action: 'wynk_cs_get_slider',
      nonce:  wynkAdminData.nonce,
      id:     sliderId,
    })
    .done(function (res) {
      if (!res || !res.success || !inner) return;

      var data     = res.data;
      var settings = data.settings;
      var instId   = 'wynk-modal-preview-' + sliderId;
      var height   = settings.height || 400;

      inner.innerHTML =
        '<div id="' + instId + '" class="wynk-curve-slider-wrap"' +
        ' style="height:' + height + 'px;">' +
          '<canvas class="wynk-curve-canvas"></canvas>' +
        '</div>';

      window.wynkSliders = window.wynkSliders || {};
      window.wynkSliders[instId] = {
        images:   data.images,
        settings: settings,
      };

      if (window.wynkCurve) {
        var wrapEl = document.getElementById(instId);
        if (wrapEl) wynkCurve.initInstance(instId, wrapEl);
      }
    })
    .fail(function () {
      if (inner) inner.innerHTML = '<p class="wynk-modal-error">Could not load preview.</p>';
    });
  }

  /**
   * Close the preview modal and clean up the mounted Three.js instance.
   */
  function closePreviewModal() {
    var modal = document.getElementById('wynk-preview-modal');
    if (!modal) return;

    // Destroy any running Three.js instance inside the modal.
    if (window.wynkCurve) {
      Object.keys(window.wynkSliders || {}).forEach(function (id) {
        if (id.indexOf('wynk-modal-preview-') === 0) {
          wynkCurve.destroy(id);
        }
      });
    }

    var inner = document.getElementById('wynk-modal-inner');
    if (inner) inner.innerHTML = '';

    modal.setAttribute('aria-hidden', 'true');
    document.body.classList.remove('wynk-modal-open');
  }

  // ============================================================
  // Helpers
  // ============================================================

  /** Basic HTML entity escaping for text inserted into innerHTML. */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g,  '&amp;')
      .replace(/</g,  '&lt;')
      .replace(/>/g,  '&gt;')
      .replace(/"/g,  '&quot;');
  }

  /** Escape for use inside HTML attribute values. */
  function escapeAttr(str) {
    return String(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  /**
   * Seed imageIds and imageData from the hidden JSON input.
   * Called on page load (edit view) so existing images populate the grid.
   */
  function seedImageDataFromJson() {
    var hidden = document.getElementById('wynk-images-json');
    if (!hidden) return;

    var ids;
    try { ids = JSON.parse(hidden.value); } catch (e) { ids = []; }
    if (!Array.isArray(ids) || ids.length === 0) return;

    state.imageIds = ids;

    // Track how many fetches are still in-flight so we can trigger a
    // single preview rebuild once ALL attachment data has been loaded.
    var remaining = ids.length;

    ids.forEach(function (id) {
      if (typeof wp !== 'undefined' && wp.media) {
        var att = wp.media.attachment(id);
        att.fetch()
          .done(function () {
            var attData = att.toJSON();
            state.imageData[id] = {
              id:     id,
              url:    attData.url,
              thumb:  attData.sizes && attData.sizes.thumbnail
                        ? attData.sizes.thumbnail.url
                        : (attData.sizes && attData.sizes.medium
                            ? attData.sizes.medium.url
                            : attData.url),
              width:  attData.width  || 800,
              height: attData.height || 600,
              alt:    attData.alt    || attData.title || '',
              color:  null,
            };
            renderThumbs();

            // When every fetch is done, trigger a full preview rebuild so
            // real image textures appear instead of colour placeholders.
            remaining--;
            if (remaining <= 0) {
              schedulePreviewRefresh(true);
            }
          })
          .fail(function () {
            // One fetch failed — still decrement so we don't block the rebuild.
            remaining--;
            if (remaining <= 0) {
              schedulePreviewRefresh(true);
            }
          });
      } else {
        remaining--;
      }
    });
  }

  // ============================================================
  // Step 4.10 — init(): wire all event listeners
  // ============================================================

  /**
   * Initialise all admin interactions.
   * Called on DOMContentLoaded.
   */
  function init() {

    // ── Range sliders: live value badge update ─────────────────
    ['speed', 'gap'].forEach(function (name) {
      var slider  = document.getElementById('wynk-' + name);
      var display = document.getElementById('wynk-' + name + '-val');
      if (slider && display) {
        slider.addEventListener('input', function () {
          display.textContent = this.value;
          var rebuild = (name === 'gap'); // gap changes plane width scale, requiring rebuild
          schedulePreviewRefresh(rebuild);
        });
      }
    });

    // ── Responsive range sliders: live value badge update ──────
    ['desktop-visible', 'tablet-visible', 'mobile-visible'].forEach(function (name) {
      var slider  = document.getElementById('wynk-' + name);
      var display = document.getElementById('wynk-' + name + '-val');
      if (slider && display) {
        slider.addEventListener('input', function () {
          display.textContent = this.value;
          schedulePreviewRefresh(true); // visible images changed → force full rebuild to update geometry
        });
      }
    });

    // ── Height input → force rebuild preview ──────────────────
    var heightEl = document.getElementById('wynk-height');
    if (heightEl) {
      heightEl.addEventListener('input', function () {
        schedulePreviewRefresh(true); // height changed → rebuild to update renderer size and camera aspect
      });
    }

    // ── Direction radios → force rebuild preview ──────────────
    document.querySelectorAll('input[name="direction"]').forEach(function (radio) {
      radio.addEventListener('change', function () {
        schedulePreviewRefresh(true); // direction changed → rebuild to correctly reposition plane coordinates
      });
    });

    // ── Toggle switches → hot-update preview ──────────────────
    var autoplayEl = document.getElementById('wynk-autoplay');
    if (autoplayEl) {
      autoplayEl.addEventListener('change', function () {
        schedulePreviewRefresh(false);
      });
    }

    var pauseEl = document.getElementById('wynk-pause-hover');
    if (pauseEl) {
      pauseEl.addEventListener('change', function () {
        schedulePreviewRefresh(false);
      });
    }



    // ── Media picker button ────────────────────────────────────
    var addImagesBtn = document.getElementById('wynk-add-images');
    if (addImagesBtn) {
      addImagesBtn.addEventListener('click', openMediaPicker);
    }

    // ── Save button ────────────────────────────────────────────
    var saveBtn = document.getElementById('wynk-save-btn');
    if (saveBtn) {
      saveBtn.addEventListener('click', saveSlider);
    }

    // ── Dashboard: shortcode copy buttons ─────────────────────
    document.querySelectorAll('.wynk-copy-btn[data-shortcode]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        copyShortcode(this.dataset.shortcode, this);
      });
    });

    // ── Dashboard: delete buttons ──────────────────────────────
    document.querySelectorAll('.wynk-delete-btn[data-slider-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = this.closest('.wynk-card');
        deleteSlider(parseInt(this.dataset.sliderId, 10), card);
      });
    });

    // ── Dashboard: preview buttons ─────────────────────────────
    document.querySelectorAll('.wynk-preview-btn[data-slider-id]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openPreviewModal(parseInt(this.dataset.sliderId, 10));
      });
    });

    // ── Modal close button + Escape key ───────────────────────
    var modalClose = document.getElementById('wynk-modal-close');
    if (modalClose) {
      modalClose.addEventListener('click', closePreviewModal);
    }

    var modal = document.getElementById('wynk-preview-modal');
    if (modal) {
      // Close when clicking outside the slider area.
      modal.addEventListener('click', function (e) {
        if (e.target === modal) closePreviewModal();
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closePreviewModal();
    });

    // ── Edit view: seed existing images + initial preview ──────
    if (document.getElementById('wynk-preview-canvas')) {
      // Show placeholder preview immediately so the panel isn't blank.
      // seedImageDataFromJson() will call schedulePreviewRefresh(true)
      // automatically once all wp.media.attachment().fetch() calls resolve,
      // swapping placeholders for real images.
      previewNeedsRebuild = true;
      refreshPreview();          // shows coloured placeholders instantly
      seedImageDataFromJson();   // kicks off async fetch → rebuilds with real images when done
    }
  }

  // ============================================================
  // Public API
  // ============================================================

  return {
    init:            init,
    refreshPreview:  refreshPreview,
    openMediaPicker: openMediaPicker,
    renderThumbs:    renderThumbs,
    saveSlider:      saveSlider,
    deleteSlider:    deleteSlider,
    copyShortcode:   copyShortcode,
  };

}(jQuery));

// ── Bootstrap ────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  WynkAdmin.init();
});
