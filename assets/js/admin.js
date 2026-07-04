(function () {
  'use strict';

  var aibimaConfig = window.AibimaAdmin || {
    ajaxUrl: '',
    nonce: '',
    samplePrompt: '',
    strings: {}
  };

  function t(key, fallback) {
    return aibimaConfig.strings && aibimaConfig.strings[key] ? aibimaConfig.strings[key] : fallback;
  }

  function qs(root, selector) {
    return root.querySelector(selector);
  }

  function qsa(root, selector) {
    return Array.prototype.slice.call(root.querySelectorAll(selector));
  }

  function setMessage(box, text, type) {
    if (!box) return;
    box.hidden = !text;
    box.textContent = text || '';
    box.className = 'aibill-message' + (type ? ' is-' + type : '');
  }

  function invoiceText(card) {
    if (!card) return '';
    return card.innerText.replace(/\n{3,}/g, '\n\n').trim();
  }

  function bindInvoiceActions(root) {
    qsa(root, '[data-aibill-print]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        window.print();
      });
    });
    qsa(root, '[data-aibill-copy]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var card = qs(root, '[data-aibill-invoice-card]');
        var text = invoiceText(card);
        if (navigator.clipboard && text) {
          navigator.clipboard.writeText(text).then(function () {
            var original = btn.textContent;
            btn.textContent = t('copied', 'Copied');
            setTimeout(function () { btn.textContent = original || (t('copy', 'Copy invoice text')); }, 1600);
          });
        }
      });
    });
  }

  function initModal() {
    var wrap = qs(document, '.aibill-admin-wrap');
    var modal = qs(document, '[data-aibill-modal]');
    if (!modal) return;
    var form = qs(modal, '[data-aibill-prompt-form]');
    var textarea = qs(modal, 'textarea[name="prompt"]');
    var message = qs(modal, '[data-aibill-message]');
    var result = qs(modal, '[data-aibill-result]');
    var submit = form ? qs(form, 'button[type="submit"]') : null;

    function openModal() {
      modal.hidden = false;
      document.body.classList.add('aibill-modal-open');
      if (textarea) {
        setTimeout(function () { textarea.focus(); }, 50);
      }
    }

    function closeModal() {
      modal.hidden = true;
      document.body.classList.remove('aibill-modal-open');
    }

    qsa(document, '[data-aibill-open-modal]').forEach(function (btn) {
      btn.addEventListener('click', openModal);
    });
    qsa(modal, '[data-aibill-close-modal]').forEach(function (btn) {
      btn.addEventListener('click', closeModal);
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !modal.hidden) closeModal();
    });

    var sample = qs(modal, '[data-aibill-use-sample]');
    if (sample && textarea) {
      sample.addEventListener('click', function () {
        textarea.value = aibimaConfig.samplePrompt || '';
        textarea.focus();
      });
    }

    if (wrap && wrap.getAttribute('data-aibill-open-prompt') === '1') {
      openModal();
    }

    if (!form || !textarea || !result) return;
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      var prompt = textarea.value.trim();
      if (prompt.length < 3) {
        setMessage(message, 'Please write invoice details first.', 'error');
        return;
      }

      if (submit) submit.disabled = true;
      setMessage(message, t('generating', 'Generating invoice…'), 'info');
      result.hidden = true;
      result.innerHTML = '';

      var body = new URLSearchParams();
      body.append('action', 'aibima_generate_invoice');
      body.append('nonce', aibimaConfig.nonce || '');
      body.append('prompt', prompt);

      fetch(aibimaConfig.ajaxUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: body.toString()
      })
        .then(function (response) {
          return response.json().then(function (json) {
            if (!response.ok || !json.success) {
              var errorMessage = json && json.data && json.data.message ? json.data.message : 'Request failed';
              throw new Error(errorMessage);
            }
            return json.data;
          });
        })
        .then(function (data) {
          setMessage(message, data.message || (t('generated', 'Invoice generated and saved.')), 'success');
          result.hidden = false;
          result.innerHTML = '<div class="aibill-generated-actions">' +
            '<a class="button button-primary" href="' + data.viewUrl + '">' + (t('viewInvoice', 'View invoice')) + '</a>' +
            '<a class="button" href="' + data.editUrl + '">' + (t('editInvoice', 'Edit invoice')) + '</a>' +
            '<a class="button" target="_blank" rel="noopener" href="' + data.printUrl + '">' + (t('printInvoice', 'Print invoice')) + '</a>' +
            '<a class="button" href="' + data.downloadUrl + '">' + (t('downloadPdf', 'Download PDF')) + '</a>' +
            '</div>' + (data.html || '');
          bindInvoiceActions(result);
        })
        .catch(function (error) {
          setMessage(message, error.message || (t('error', 'Something went wrong.')), 'error');
        })
        .finally(function () {
          if (submit) submit.disabled = false;
        });
    });
  }

  function initDeleteConfirm() {
    qsa(document, '[data-aibill-confirm-delete]').forEach(function (link) {
      link.addEventListener('click', function (event) {
        if (!window.confirm(t('confirmDelete', 'Delete this invoice?'))) {
          event.preventDefault();
        }
      });
    });
  }

  function initItemsEditor() {
    var table = qs(document, '[data-aibill-items-table]');
    var add = qs(document, '[data-aibill-add-item]');
    if (!table || !add) return;
    var tbody = qs(table, 'tbody');

    function nextIndex() {
      return qsa(tbody, 'tr').length + Date.now();
    }

    function rowHtml(index) {
      return '<tr>' +
        '<td><input type="text" name="items[' + index + '][name]" value="" class="regular-text" /></td>' +
        '<td><input type="number" step="0.001" min="0" name="items[' + index + '][quantity]" value="1" /></td>' +
        '<td><input type="text" name="items[' + index + '][unit]" value="pcs" /></td>' +
        '<td><input type="number" step="0.01" min="0" name="items[' + index + '][unit_price]" value="0" /></td>' +
        '<td><input type="number" step="0.01" min="0" max="100" name="items[' + index + '][tax_rate]" value="0" /></td>' +
        '<td><label><input type="checkbox" name="items[' + index + '][tax_inclusive]" value="1" /> Yes</label></td>' +
        '<td><input type="text" name="items[' + index + '][hsn_sac]" value="" /></td>' +
        '<td><button type="button" class="button-link-delete" data-aibill-remove-item>Remove</button></td>' +
        '</tr>';
    }

    add.addEventListener('click', function () {
      tbody.insertAdjacentHTML('beforeend', rowHtml(nextIndex()));
      var last = tbody.lastElementChild;
      var firstInput = last ? qs(last, 'input') : null;
      if (firstInput) firstInput.focus();
    });

    tbody.addEventListener('click', function (event) {
      var btn = event.target.closest('[data-aibill-remove-item]');
      if (!btn) return;
      var rows = qsa(tbody, 'tr');
      if (rows.length <= 1) return;
      var tr = btn.closest('tr');
      if (tr) tr.remove();
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    initModal();
    initDeleteConfirm();
    initItemsEditor();
    bindInvoiceActions(document);
  });
})();
