/**
 * Job & Inventory Request System - Frontend JS
 * Vanilla JavaScript, no jQuery dependency.
 */

document.addEventListener('DOMContentLoaded', function () {
  'use strict';

  // ── Namespace ──
  window.JIR = window.JIR || {};

  // ══════════════════════════════════════════════════════════════════
  // Theme switcher (light / dark)
  // ══════════════════════════════════════════════════════════════════
  (function () {
    var STORAGE_KEY = 'jir_theme';
    var html = document.documentElement;

    function currentTheme() {
      return html.getAttribute('data-bs-theme') || 'light';
    }

    function applyTheme(theme) {
      html.setAttribute('data-bs-theme', theme);
      localStorage.setItem(STORAGE_KEY, theme);
      updateIcons(theme);
    }

    function updateIcons(theme) {
      var icons = document.querySelectorAll('#themeIcon, #themeIconGuest');
      icons.forEach(function (icon) {
        if (theme === 'dark') {
          icon.classList.remove('fa-moon');
          icon.classList.add('fa-sun');
        } else {
          icon.classList.remove('fa-sun');
          icon.classList.add('fa-moon');
        }
      });
    }

    // Set correct icon on load (inline script may have already set dark)
    updateIcons(currentTheme());

    // Bind toggle buttons
    var toggles = document.querySelectorAll('#themeToggle, #themeToggleGuest');
    toggles.forEach(function (btn) {
      btn.addEventListener('click', function () {
        applyTheme(currentTheme() === 'dark' ? 'light' : 'dark');
      });
    });

    // Respond to OS preference changes (only if no explicit user choice)
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (e) {
      if (!localStorage.getItem(STORAGE_KEY)) {
        applyTheme(e.matches ? 'dark' : 'light');
      }
    });
  })();

  // ══════════════════════════════════════════════════════════════════
  // Auto-dismiss flash alerts
  // ══════════════════════════════════════════════════════════════════
  var flashAlerts = document.querySelectorAll('.flash-auto-dismiss');
  flashAlerts.forEach(function (alert) {
    setTimeout(function () {
      alert.style.opacity = '0';
      setTimeout(function () {
        alert.remove();
      }, 500);
    }, 5000);
  });

  // ══════════════════════════════════════════════════════════════════
  // Toast notification system
  // ══════════════════════════════════════════════════════════════════
  JIR.toast = function (message, type) {
    type = type || 'info';
    var container = document.getElementById('toast-container');
    if (!container) return;

    var iconMap = {
      success: 'fa-circle-check text-success',
      danger: 'fa-circle-xmark text-danger',
      warning: 'fa-triangle-exclamation text-warning',
      info: 'fa-circle-info text-info'
    };

    var toast = document.createElement('div');
    toast.className = 'toast toast-notification show align-items-center border-0';
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');
    toast.innerHTML =
      '<div class="d-flex">' +
        '<div class="toast-body">' +
          '<i class="fas ' + (iconMap[type] || iconMap.info) + ' me-2"></i>' +
          message +
        '</div>' +
        '<button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>' +
      '</div>';

    container.appendChild(toast);

    // Auto-remove after 5 seconds
    setTimeout(function () {
      toast.style.opacity = '0';
      setTimeout(function () { toast.remove(); }, 500);
    }, 5000);
  };

  // ══════════════════════════════════════════════════════════════════
  // Prevent double form submission with spinner
  // ══════════════════════════════════════════════════════════════════
  var forms = document.querySelectorAll('form');
  forms.forEach(function (form) {
    form.addEventListener('submit', function () {
      var submitBtn = form.querySelector('[type="submit"]');
      if (submitBtn && !submitBtn.disabled) {
        submitBtn.disabled = true;
        submitBtn.dataset.originalHtml = submitBtn.innerHTML;
        submitBtn.innerHTML =
          '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Processing...';
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // Inline form validation
  // ══════════════════════════════════════════════════════════════════
  var requiredInputs = document.querySelectorAll('input[required], select[required], textarea[required]');
  requiredInputs.forEach(function (input) {
    input.addEventListener('blur', function () {
      if (!input.value.trim()) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
      } else {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
      }
    });

    input.addEventListener('input', function () {
      if (input.classList.contains('is-invalid') && input.value.trim()) {
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
      }
    });
  });

  // Email validation
  var emailInputs = document.querySelectorAll('input[type="email"]');
  emailInputs.forEach(function (input) {
    input.addEventListener('blur', function () {
      if (input.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(input.value)) {
        input.classList.add('is-invalid');
        input.classList.remove('is-valid');
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // Password strength indicator
  // ══════════════════════════════════════════════════════════════════
  var pwField = document.querySelector('input[name="password"]');
  if (pwField && pwField.closest('form')) {
    var strengthDiv = document.createElement('div');
    strengthDiv.className = 'form-text mt-1';
    strengthDiv.id = 'pw-strength';
    pwField.parentNode.appendChild(strengthDiv);

    pwField.addEventListener('input', function () {
      var pw = pwField.value;
      if (!pw) { strengthDiv.textContent = ''; return; }

      var score = 0;
      if (pw.length >= 8) score++;
      if (/[A-Z]/.test(pw)) score++;
      if (/[a-z]/.test(pw)) score++;
      if (/[0-9]/.test(pw)) score++;
      if (/[^A-Za-z0-9]/.test(pw)) score++;

      var labels = ['Very weak', 'Weak', 'Fair', 'Good', 'Strong'];
      var colors = ['text-danger', 'text-danger', 'text-warning', 'text-info', 'text-success'];
      var idx = Math.min(score, 4);

      strengthDiv.className = 'form-text mt-1 ' + colors[idx];
      strengthDiv.textContent = 'Strength: ' + labels[idx];
    });
  }

  // ══════════════════════════════════════════════════════════════════
  // Password confirmation validation
  // ══════════════════════════════════════════════════════════════════
  var passwordField = document.querySelector('input[name="password"]');
  var confirmField = document.querySelector('input[name="password_confirmation"]');

  if (passwordField && confirmField) {
    var parentForm = confirmField.closest('form');
    if (parentForm) {
      parentForm.addEventListener('submit', function (e) {
        if (passwordField.value !== confirmField.value) {
          e.preventDefault();
          confirmField.classList.add('is-invalid');
          confirmField.setCustomValidity('Passwords do not match.');
          confirmField.reportValidity();

          var submitBtn = parentForm.querySelector('[type="submit"]');
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = submitBtn.dataset.originalHtml || 'Submit';
          }
        } else {
          confirmField.classList.remove('is-invalid');
          confirmField.setCustomValidity('');
        }
      });

      confirmField.addEventListener('input', function () {
        confirmField.setCustomValidity('');
        confirmField.classList.remove('is-invalid');
      });
    }
  }

  // ══════════════════════════════════════════════════════════════════
  // Auto-save drafts to localStorage
  // ══════════════════════════════════════════════════════════════════
  var draftForms = document.querySelectorAll('form[data-autosave]');
  draftForms.forEach(function (form) {
    var key = 'jir_draft_' + form.getAttribute('data-autosave');

    // Restore saved draft
    try {
      var saved = JSON.parse(localStorage.getItem(key));
      if (saved) {
        Object.keys(saved).forEach(function (name) {
          var el = form.querySelector('[name="' + name + '"]');
          if (el && !el.value) {
            el.value = saved[name];
          }
        });
      }
    } catch (e) { /* ignore */ }

    // Auto-save on input (debounced)
    var saveTimer = null;
    form.addEventListener('input', function () {
      clearTimeout(saveTimer);
      saveTimer = setTimeout(function () {
        var data = {};
        var inputs = form.querySelectorAll('input:not([type="hidden"]):not([type="password"]):not([type="file"]), textarea, select');
        inputs.forEach(function (el) {
          if (el.name) data[el.name] = el.value;
        });
        localStorage.setItem(key, JSON.stringify(data));
      }, 1000);
    });

    // Clear draft on successful submit
    form.addEventListener('submit', function () {
      localStorage.removeItem(key);
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // Confirm before leaving page with unsaved changes
  // ══════════════════════════════════════════════════════════════════
  var trackedForms = document.querySelectorAll('form[data-track-changes]');
  trackedForms.forEach(function (form) {
    var isDirty = false;
    var isSubmitting = false;

    form.addEventListener('input', function () {
      isDirty = true;
      form.classList.add('form-unsaved');
    });

    form.addEventListener('submit', function () {
      isSubmitting = true;
    });

    window.addEventListener('beforeunload', function (e) {
      if (isDirty && !isSubmitting) {
        e.preventDefault();
        e.returnValue = '';
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // File upload progress
  // ══════════════════════════════════════════════════════════════════
  var fileInputs = document.querySelectorAll('input[type="file"]');
  fileInputs.forEach(function (input) {
    input.addEventListener('change', function () {
      var files = input.files;
      if (!files.length) return;

      // Display selected file names
      var label = input.closest('.mb-3');
      if (!label) return;

      var existing = label.querySelector('.file-preview');
      if (existing) existing.remove();

      var preview = document.createElement('div');
      preview.className = 'file-preview mt-2 small text-muted';
      var names = [];
      for (var i = 0; i < files.length; i++) {
        var size = (files[i].size / 1024 / 1024).toFixed(2);
        names.push(files[i].name + ' (' + size + ' MB)');
      }
      preview.textContent = 'Selected: ' + names.join(', ');
      label.appendChild(preview);
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // Unread message badge — smart polling (pauses when tab hidden)
  // ══════════════════════════════════════════════════════════════════
  (function () {
    var badgeLink = document.querySelector('a[href="/messages"]');
    if (!badgeLink) return;

    var POLL_ACTIVE  = 60000;  // 60s when tab is visible
    var POLL_HIDDEN  = 300000; // 5 min when tab is hidden
    var pollTimer = null;

    function updateUnreadBadge() {
      fetch('/api/messages/unread', { credentials: 'same-origin' })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          var count = data.unread_count || 0;
          var badge = badgeLink.querySelector('.badge');

          if (count > 0) {
            if (badge) {
              badge.textContent = count;
            } else {
              badge = document.createElement('span');
              badge.className = 'position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger';
              badge.textContent = count;
              var sr = document.createElement('span');
              sr.className = 'visually-hidden';
              sr.textContent = 'unread messages';
              badge.appendChild(sr);
              badgeLink.appendChild(badge);
            }
          } else if (badge) {
            badge.remove();
          }
        })
        .catch(function () { /* silent fail */ });
    }

    function startPolling(interval) {
      clearInterval(pollTimer);
      pollTimer = setInterval(updateUnreadBadge, interval);
    }

    // Start with active interval
    startPolling(POLL_ACTIVE);

    // Slow down when tab is hidden, speed up when visible
    document.addEventListener('visibilitychange', function () {
      if (document.hidden) {
        startPolling(POLL_HIDDEN);
      } else {
        updateUnreadBadge(); // Immediate check on return
        startPolling(POLL_ACTIVE);
      }
    });
  })();

  // ══════════════════════════════════════════════════════════════════
  // Mobile navbar auto-close
  // ══════════════════════════════════════════════════════════════════
  var navLinks = document.querySelectorAll('.navbar-collapse .nav-link');
  var navbarCollapse = document.querySelector('.navbar-collapse');

  navLinks.forEach(function (link) {
    link.addEventListener('click', function () {
      if (navbarCollapse && navbarCollapse.classList.contains('show')) {
        var bsCollapse = bootstrap.Collapse.getInstance(navbarCollapse);
        if (bsCollapse) {
          bsCollapse.hide();
        }
      }
    });
  });

  // ══════════════════════════════════════════════════════════════════
  // Keyboard accessibility: Escape to close modals/dropdowns
  // ══════════════════════════════════════════════════════════════════
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      // Close any open Bootstrap dropdowns
      var openDropdowns = document.querySelectorAll('.dropdown-menu.show');
      openDropdowns.forEach(function (dd) {
        var toggle = dd.previousElementSibling;
        if (toggle) {
          var bsDropdown = bootstrap.Dropdown.getInstance(toggle);
          if (bsDropdown) bsDropdown.hide();
        }
      });
    }
  });

  // ══════════════════════════════════════════════════════════════════
  // Smooth scroll for anchor links
  // ══════════════════════════════════════════════════════════════════
  document.querySelectorAll('a[href^="#"]').forEach(function (link) {
    link.addEventListener('click', function (e) {
      var target = document.querySelector(link.getAttribute('href'));
      if (target) {
        e.preventDefault();
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        target.focus();
      }
    });
  });

});
