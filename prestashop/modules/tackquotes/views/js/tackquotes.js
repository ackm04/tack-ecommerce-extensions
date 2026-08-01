/**
 * TackQuote for PrestaShop — storefront "Request a Quote" button behavior.
 * No dependency on jQuery beyond what PrestaShop's front theme already loads.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('tackquotes-open-btn');
    var modal = document.getElementById('tackquotes-modal');
    var closeBtn = document.getElementById('tackquotes-close');
    var submitBtn = document.getElementById('tackquotes-submit');
    var errorEl = document.getElementById('tackquotes-error');
    var successEl = document.getElementById('tackquotes-success');

    if (!openBtn || !modal) {
      return;
    }

    openBtn.addEventListener('click', function () {
      modal.style.display = 'flex';
    });

    if (closeBtn) {
      closeBtn.addEventListener('click', function () {
        modal.style.display = 'none';
      });
    }

    if (submitBtn) {
      submitBtn.addEventListener('click', function () {
        var email = document.getElementById('tackquotes-email').value;
        var quantity = document.getElementById('tackquotes-quantity').value;
        var note = document.getElementById('tackquotes-note').value;
        var ajaxUrl = openBtn.getAttribute('data-ajax-url');
        var productId = openBtn.getAttribute('data-product-id');

        errorEl.style.display = 'none';
        successEl.style.display = 'none';
        submitBtn.disabled = true;

        var body = new URLSearchParams();
        body.set('ajax', '1');
        body.set('action', 'quoterequest');
        body.set('email', email);
        body.set('note', note);
        body.set('quantity', quantity);
        body.set('product_id', productId);

        fetch(ajaxUrl, {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: body.toString(),
        })
          .then(function (res) {
            return res.json().then(function (data) {
              return { ok: res.ok, data: data };
            });
          })
          .then(function (result) {
            submitBtn.disabled = false;
            if (!result.ok || !result.data.success) {
              errorEl.textContent =
                (result.data && result.data.message) || 'Could not create the quote. Please try again.';
              errorEl.style.display = 'block';
              return;
            }
            successEl.textContent = 'Quote request sent. Check your email for a link to the quote.';
            successEl.style.display = 'block';
          })
          .catch(function () {
            submitBtn.disabled = false;
            errorEl.textContent = 'Could not reach TackQuote. Please try again.';
            errorEl.style.display = 'block';
          });
      });
    }
  });
})();
