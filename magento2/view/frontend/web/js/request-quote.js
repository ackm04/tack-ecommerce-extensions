/**
 * "Request a Quote" button + modal. Plain vanilla JS on purpose — no RequireJS
 * module, no jQuery dependency — mirroring the WordPress plugin's small
 * assets/js/tack-quotes.js footprint.
 *
 * @package TackQuote_Quotes
 */
(function () {
  'use strict';

  function init() {
    var roots = document.querySelectorAll('.tackquote-request-quote');
    roots.forEach(function (root) {
      var openBtn = root.querySelector('.tackquote-open-btn');
      var modal = root.querySelector('.tackquote-modal');
      var closeBtn = root.querySelector('.tackquote-modal-close');
      var form = root.querySelector('.tackquote-form');
      var errorEl = root.querySelector('.tackquote-modal-error');
      var successEl = root.querySelector('.tackquote-modal-success');
      var submitBtn = root.querySelector('.tackquote-submit-btn');

      if (!openBtn || !modal || !form) {
        return;
      }

      openBtn.addEventListener('click', function () {
        modal.hidden = false;
      });

      if (closeBtn) {
        closeBtn.addEventListener('click', function () {
          modal.hidden = true;
        });
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        errorEl.hidden = true;
        successEl.hidden = true;

        var email = form.querySelector('[name="email"]').value.trim();
        var qty = form.querySelector('[name="qty"]').value;
        var note = form.querySelector('[name="note"]').value;
        var sku = root.getAttribute('data-sku');
        var submitUrl = root.getAttribute('data-submit-url');
        var formKey = root.getAttribute('data-form-key');

        if (!email || !sku) {
          errorEl.textContent = 'A valid email address is required.';
          errorEl.hidden = false;
          return;
        }

        submitBtn.disabled = true;

        var body = new URLSearchParams();
        body.set('email', email);
        body.set('qty', qty);
        body.set('note', note);
        body.set('sku', sku);
        body.set('form_key', formKey);

        fetch(submitUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
          },
          body: body.toString(),
          credentials: 'same-origin'
        })
          .then(function (response) {
            return response.json().then(function (data) {
              return { status: response.status, data: data };
            });
          })
          .then(function (result) {
            submitBtn.disabled = false;
            if (!result.data || !result.data.success) {
              errorEl.textContent = (result.data && result.data.message) || 'Could not create the quote. Please try again.';
              errorEl.hidden = false;
              return;
            }
            successEl.textContent = result.data.portalUrl
              ? 'Quote request sent. View it at: ' + result.data.portalUrl
              : 'Quote request sent.';
            successEl.hidden = false;
            form.reset();
          })
          .catch(function () {
            submitBtn.disabled = false;
            errorEl.textContent = 'Could not reach TackQuote. Please try again.';
            errorEl.hidden = false;
          });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
