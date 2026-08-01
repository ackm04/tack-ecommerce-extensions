/**
 * TackQuote for Zen Cart — storefront "Request a Quote" button behavior.
 * No dependency on jQuery; posts form-encoded data to ajax_tack_quote_request.php.
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    var openBtn = document.getElementById('tackQuoteOpenBtn');
    var modal = document.getElementById('tackQuoteModal');
    var closeBtn = document.getElementById('tackQuoteClose');
    var submitBtn = document.getElementById('tackQuoteSubmit');
    var errorEl = document.getElementById('tackQuoteError');
    var successEl = document.getElementById('tackQuoteSuccess');

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
        var email = document.getElementById('tackQuoteEmail').value;
        var quantity = document.getElementById('tackQuoteQuantity').value;
        var note = document.getElementById('tackQuoteNote').value;
        var ajaxUrl = openBtn.getAttribute('data-ajax-url');
        var productId = openBtn.getAttribute('data-product-id');

        errorEl.style.display = 'none';
        successEl.style.display = 'none';
        submitBtn.disabled = true;

        var body = new URLSearchParams();
        body.set('email', email);
        body.set('note', note);
        body.set('quantity', quantity);
        body.set('products_id', productId);

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
