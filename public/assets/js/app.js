/* ==========================================================================
   LRMS — progressive enhancement only. Every screen works without JavaScript;
   this file adds GPS capture, signature pads, the deadline countdown, the
   mapping helpers and confirmation prompts.
   ========================================================================== */
(function () {
  'use strict';

  /* ------------------------------------------------------------ Sidebar -- */

  var toggle = document.querySelector('[data-menu-toggle]');
  var sidebar = document.querySelector('.sidebar');

  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  /* ------------------------------------------------- Destructive actions -- */

  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');

    if (message && !window.confirm(message)) {
      event.preventDefault();
    }
  });

  document.addEventListener('click', function (event) {
    var link = event.target.closest('[data-confirm-link]');

    if (link && !window.confirm(link.getAttribute('data-confirm-link'))) {
      event.preventDefault();
    }
  });

  /* -------------------------------------------------- Deadline countdown -- */

  document.querySelectorAll('[data-countdown]').forEach(function (element) {
    var remaining = parseInt(element.getAttribute('data-countdown'), 10);

    if (isNaN(remaining)) {
      return;
    }

    function render() {
      if (remaining <= 0) {
        element.textContent = 'Deadline passed';
        element.classList.add('passed');
        return;
      }

      var hours = Math.floor(remaining / 3600);
      var minutes = Math.floor((remaining % 3600) / 60);
      var seconds = remaining % 60;

      element.textContent = (hours > 0 ? hours + 'h ' : '') +
        (minutes < 10 && hours > 0 ? '0' : '') + minutes + 'm ' +
        (seconds < 10 ? '0' : '') + seconds + 's left';

      remaining -= 1;
      window.setTimeout(render, 1000);
    }

    render();
  });

  /* -------------------------------------------------------- GPS capture -- */

  /**
   * Fills hidden latitude/longitude/accuracy inputs inside the container and
   * shows the result. The server re-validates everything, so this is only a
   * convenience for the operator.
   */
  document.querySelectorAll('[data-gps-capture]').forEach(function (box) {
    var button = box.querySelector('[data-gps-button]');
    var output = box.querySelector('[data-gps-output]');
    var latitude = box.querySelector('[name$="[latitude]"], [name="latitude"]');
    var longitude = box.querySelector('[name$="[longitude]"], [name="longitude"]');
    var accuracy = box.querySelector('[name$="[accuracy]"], [name="accuracy"]');
    var capturedAt = box.querySelector('[name$="[captured_at]"], [name="captured_at"]');
    var provider = box.querySelector('[name$="[provider]"], [name="provider"]');

    if (!button) {
      return;
    }

    button.addEventListener('click', function () {
      if (!navigator.geolocation) {
        if (output) {
          output.textContent = 'This browser cannot provide a location. Use a device with GPS.';
        }
        return;
      }

      button.disabled = true;
      if (output) {
        output.textContent = 'Getting your location…';
      }

      navigator.geolocation.getCurrentPosition(function (position) {
        button.disabled = false;

        if (latitude) { latitude.value = position.coords.latitude.toFixed(7); }
        if (longitude) { longitude.value = position.coords.longitude.toFixed(7); }
        if (accuracy) { accuracy.value = Math.round(position.coords.accuracy); }
        if (capturedAt) { capturedAt.value = new Date().toISOString().slice(0, 19).replace('T', ' '); }
        if (provider) { provider.value = 'browser'; }

        if (output) {
          output.innerHTML = '<span class="coords">' + position.coords.latitude.toFixed(6) + ', ' +
            position.coords.longitude.toFixed(6) + '</span> <span class="muted small">(±' +
            Math.round(position.coords.accuracy) + ' m)</span>';
        }
      }, function (error) {
        button.disabled = false;

        if (output) {
          output.textContent = error.code === 1
            ? 'Location permission was denied. Allow location access to record this inspection.'
            : 'Could not get a location fix (' + error.message + ').';
        }
      }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
    });
  });

  /* ------------------------------------------------------ Signature pad -- */

  document.querySelectorAll('canvas.signature').forEach(function (canvas) {
    var target = document.querySelector(canvas.getAttribute('data-target'));
    var clear = document.querySelector(canvas.getAttribute('data-clear'));
    var context = canvas.getContext('2d');
    var drawing = false;
    var dirty = false;

    function resize() {
      var ratio = window.devicePixelRatio || 1;
      var rect = canvas.getBoundingClientRect();
      canvas.width = rect.width * ratio;
      canvas.height = rect.height * ratio;
      context.scale(ratio, ratio);
      context.lineWidth = 2;
      context.lineCap = 'round';
      context.strokeStyle = '#0f172a';
    }

    resize();

    function position(event) {
      var rect = canvas.getBoundingClientRect();
      var point = event.touches ? event.touches[0] : event;
      return { x: point.clientX - rect.left, y: point.clientY - rect.top };
    }

    function start(event) {
      event.preventDefault();
      drawing = true;
      dirty = true;
      var p = position(event);
      context.beginPath();
      context.moveTo(p.x, p.y);
    }

    function move(event) {
      if (!drawing) { return; }
      event.preventDefault();
      var p = position(event);
      context.lineTo(p.x, p.y);
      context.stroke();
    }

    function end() {
      if (!drawing) { return; }
      drawing = false;

      if (target && dirty) {
        target.value = canvas.toDataURL('image/png');
      }
    }

    ['mousedown', 'touchstart'].forEach(function (type) { canvas.addEventListener(type, start); });
    ['mousemove', 'touchmove'].forEach(function (type) { canvas.addEventListener(type, move); });
    ['mouseup', 'mouseleave', 'touchend'].forEach(function (type) { canvas.addEventListener(type, end); });

    if (clear) {
      clear.addEventListener('click', function () {
        context.clearRect(0, 0, canvas.width, canvas.height);
        dirty = false;
        if (target) { target.value = ''; }
      });
    }
  });

  /* --------------------------------------------- Conditional form fields -- */

  /**
   * Shows or hides fields whose data-condition-field / operator / value no
   * longer match. Mirrors App\Services\Forms::isVisible on the server.
   */
  function applyConditions(scope) {
    scope.querySelectorAll('[data-condition-field]').forEach(function (wrapper) {
      var key = wrapper.getAttribute('data-condition-field');
      var operator = wrapper.getAttribute('data-condition-operator') || 'equals';
      var expected = (wrapper.getAttribute('data-condition-value') || '').toLowerCase();
      var source = scope.querySelector('[data-field-key="' + key + '"]');

      if (!source) {
        return;
      }

      var input = source.querySelector('input:checked, select, textarea, input[type=text], input[type=number], input[type=date]');
      var value = input ? String(input.value || '').toLowerCase() : '';
      var visible;

      switch (operator) {
        case 'not_equals': visible = value !== expected; break;
        case 'in': visible = expected.split(',').map(function (v) { return v.trim(); }).indexOf(value) !== -1; break;
        case 'filled': visible = value !== ''; break;
        case 'empty': visible = value === ''; break;
        default: visible = value === expected;
      }

      wrapper.classList.toggle('hidden', !visible);
    });
  }

  var formScopes = document.querySelectorAll('[data-dynamic-form]');

  formScopes.forEach(function (scope) {
    applyConditions(scope);
    scope.addEventListener('change', function () { applyConditions(scope); });
    scope.addEventListener('input', function () { applyConditions(scope); });
  });

  /* ------------------------------------------------------ Mapping screen -- */

  /** Warn when two system fields are pointed at the same Excel column. */
  var mappingForm = document.querySelector('[data-mapping-form]');

  if (mappingForm) {
    var check = function () {
      var used = {};
      var duplicates = false;

      mappingForm.querySelectorAll('select[data-mapping-select]').forEach(function (select) {
        select.classList.remove('has-error');

        if (!select.value) {
          return;
        }

        if (used[select.value]) {
          duplicates = true;
          select.classList.add('has-error');
          used[select.value].classList.add('has-error');
        }

        used[select.value] = select;
      });

      var warning = mappingForm.querySelector('[data-mapping-warning]');

      if (warning) {
        warning.classList.toggle('hidden', !duplicates);
      }
    };

    mappingForm.addEventListener('change', check);
    check();
  }

  /* ------------------------------------------------------- Table helpers -- */

  document.querySelectorAll('[data-check-all]').forEach(function (master) {
    master.addEventListener('change', function () {
      var scope = master.closest('form') || document;
      scope.querySelectorAll('input[type=checkbox][data-row-check]').forEach(function (box) {
        box.checked = master.checked;
      });
    });
  });

  /* -------------------------------------------------- Auto-submit filters -- */

  document.querySelectorAll('[data-auto-submit]').forEach(function (element) {
    element.addEventListener('change', function () {
      var form = element.closest('form');
      if (form) { form.submit(); }
    });
  });
})();
