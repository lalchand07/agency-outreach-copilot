/* agency-outreach-copilot: small progressive enhancements. No inline scripts (CSP). */
(function () {
  'use strict';

  var LIMITS = { email: { words: 120 }, followup: { words: 80 }, linkedin: { chars: 300 }, whatsapp: { words: 60 } };

  function words(text) {
    var t = text.trim();
    return t === '' ? 0 : t.split(/\s+/u).length;
  }

  function updateCounter(article) {
    var area = article.querySelector('textarea[data-limit-channel]');
    var out = article.querySelector('[data-counter]');
    if (!area || !out) { return; }
    var channel = area.getAttribute('data-limit-channel');
    if (channel === 'email' && area.getAttribute('data-followup') === '1') { channel = 'followup'; }
    var limit = LIMITS[channel] || {};
    var value = area.value;
    var text, over;
    if (limit.chars) {
      var n = Array.from(value.trim()).length;
      text = n + ' / ' + limit.chars + ' characters';
      over = n > limit.chars;
    } else {
      var w = words(value);
      text = w + ' / ' + limit.words + ' words';
      over = w > limit.words;
    }
    out.textContent = text;
    out.className = 'ml-auto ' + (over ? 'text-red-600 font-semibold' : 'text-slate-400');
  }

  function markDirty(article, dirty) {
    article.dataset.dirty = dirty ? '1' : '';
    var approve = article.querySelector('[data-shortcut="approve"]');
    if (approve) {
      approve.disabled = dirty;
      approve.title = dirty ? 'Save your edit first: approval applies to the saved text.' : '';
    }
  }

  function init(root) {
    root.querySelectorAll('article.draft').forEach(function (article) {
      updateCounter(article);
      article.querySelectorAll('textarea, input[name="subject"]').forEach(function (field) {
        field.addEventListener('input', function () {
          updateCounter(article);
          markDirty(article, true);
        });
      });
    });
  }

  // Confirmation prompts and "busy" buttons for normal forms.
  document.addEventListener('submit', function (event) {
    var form = event.target;
    var message = form.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      event.preventDefault();
      return;
    }
    var busy = form.querySelector('[data-busy]');
    if (busy) {
      setTimeout(function () {
        busy.disabled = true;
        busy.textContent = busy.getAttribute('data-busy');
      }, 0);
    }
  });

  document.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-copy]');
    if (copy) {
      var area = copy.closest('article').querySelector('textarea');
      if (area && navigator.clipboard) {
        navigator.clipboard.writeText(area.value).then(function () {
          copy.textContent = 'Copied';
          setTimeout(function () { copy.textContent = 'Copy'; }, 1500);
        });
      } else if (area) {
        area.select();
        document.execCommand('copy');
      }
    }
    var all = event.target.closest('[data-check-all]');
    if (all) {
      document.querySelectorAll('input[name="' + all.getAttribute('data-check-all') + '"]:not(:disabled)').forEach(function (box) {
        box.checked = all.checked;
      });
    }
  });

  document.addEventListener('change', function (event) {
    if (event.target.matches('[data-autosubmit]')) { event.target.form.submit(); }
  });

  // ---- Review keyboard shortcuts -------------------------------------------
  var selectedId = null;

  function drafts() {
    return Array.from(document.querySelectorAll('article.draft'));
  }

  function select(article, scroll) {
    drafts().forEach(function (a) { a.classList.remove('ring-2', 'ring-indigo-500'); });
    if (!article) { selectedId = null; return; }
    selectedId = article.getAttribute('data-draft-id');
    article.classList.add('ring-2', 'ring-indigo-500');
    if (scroll !== false) { article.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
  }

  function current() {
    return selectedId ? document.getElementById('draft-' + selectedId) : null;
  }

  function move(step) {
    var list = drafts();
    if (list.length === 0) { return; }
    var index = list.indexOf(current());
    var next = index === -1 ? (step > 0 ? 0 : list.length - 1) : Math.min(list.length - 1, Math.max(0, index + step));
    select(list[next]);
  }

  function nextPending(after) {
    var list = drafts();
    var start = list.indexOf(after);
    for (var i = start + 1; i < list.length; i++) {
      if (list[i].classList.contains('draft-pending')) { return list[i]; }
    }
    return null;
  }

  document.addEventListener('keydown', function (event) {
    if (!document.getElementById('review') || event.ctrlKey || event.metaKey || event.altKey) { return; }
    var tag = (event.target.tagName || '').toLowerCase();
    var typing = tag === 'textarea' || tag === 'input' || tag === 'select';
    if (event.key === 'Escape' && typing) {
      event.target.blur();
      return;
    }
    if (typing) { return; }
    var article = current();
    var press = function (name) {
      var button = article && article.querySelector('[data-shortcut="' + name + '"]');
      if (button && !button.disabled) { button.click(); }
    };
    switch (event.key) {
      case 'j': move(1); break;
      case 'k': move(-1); break;
      case 'a': press('approve'); break;
      case 'r': press('reject'); break;
      case 'g': press('regenerate'); break;
      case 's': press('save'); break;
      case 'e':
        if (article) {
          event.preventDefault();
          var area = article.querySelector('textarea');
          if (area) { area.focus(); }
        }
        break;
      default: return;
    }
  });

  document.addEventListener('htmx:afterSwap', function (event) {
    var swapped = event.detail.target && event.detail.target.id ? document.getElementById(event.detail.target.id) : null;
    init(document);
    if (!swapped) { return; }
    var path = (event.detail.requestConfig && event.detail.requestConfig.path) || '';
    if (/\/(approve|reject)$/.test(path) && !swapped.querySelector('.text-red-700.bg-red-50')) {
      var next = nextPending(swapped);
      select(next || swapped);
    } else {
      select(swapped, false);
    }
  });

  document.addEventListener('DOMContentLoaded', function () {
    init(document);
    if (document.getElementById('review')) {
      var first = document.querySelector('article.draft-pending');
      if (first) { select(first, false); }
    }
  });
})();
