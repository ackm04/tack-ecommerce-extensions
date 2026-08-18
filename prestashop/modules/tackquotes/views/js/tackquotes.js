/**
 * TackQuote for PrestaShop
 *
 * Adds a "Request a Quote" button to product pages and connects this store to a
 * TackQuote B2B quoting account.
 *
 * This program is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A
 * PARTICULAR PURPOSE. See the GNU General Public License for more details.
 *
 * @author    TackQuote
 * @copyright Since 2026 TackQuote
 * @license   https://www.gnu.org/licenses/old-licenses/gpl-2.0.html GPL-2.0-or-later
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
                (result.data && result.data.message) ||
                'Could not create the quote. Please try again.';
              errorEl.style.display = 'block';
              return;
            }
            successEl.textContent = openBtn.getAttribute('data-msg-success');
            successEl.style.display = 'block';
          })
          .catch(function () {
            submitBtn.disabled = false;
            errorEl.textContent = openBtn.getAttribute('data-msg-network-error');
            errorEl.style.display = 'block';
          });
      });
    }
  });
})();
