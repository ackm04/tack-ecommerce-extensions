/* global TackQuotes, jQuery */
(function ($) {
  'use strict';

  var modal = null;
  var STORAGE_KEY = 'tack_quote_list';

  // ─── Quote list (browser-side, separate from the WooCommerce cart) ───────

  function getList() {
    try {
      var raw = localStorage.getItem(STORAGE_KEY);
      return raw ? JSON.parse(raw) : [];
    } catch (e) {
      return [];
    }
  }

  function saveList(list) {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
      // Ignore — worst case the list doesn't persist across reloads.
    }
    renderList(list);
  }

  function addToList(item) {
    var list = getList();
    var existing = null;
    for (var i = 0; i < list.length; i++) {
      // Match on product AND variation. Keying on productId alone merged two different
      // variations of one parent into a single line — adding Medium then Large produced one
      // row of quantity 2, and the server then quoted whichever variation was stored on it.
      if (
        list[i].productId === item.productId &&
        (list[i].variationId || 0) === (item.variationId || 0)
      ) {
        existing = list[i];
        break;
      }
    }
    if (existing) {
      existing.quantity += item.quantity;
    } else {
      list.push(item);
    }
    saveList(list);
  }

  // Variation-aware for the same reason addToList's match is: two rows can share a
  // productId and differ only by variation.
  function removeFromList(productId, variationId) {
    var list = getList().filter(function (row) {
      return !(
        row.productId === productId && (row.variationId || 0) === (variationId || 0)
      );
    });
    saveList(list);
  }

  function clearList() {
    saveList([]);
  }

  // ─── Floating quote-list widget (button + drawer) ────────────────────────

  function renderList(list) {
    var $widget = $('#tack-quote-list-widget');
    if (!$widget.length) {
      return;
    }

    $widget.prop('hidden', list.length === 0);
    $('#tack-quote-list-count').text(list.length);

    var $items = $('#tack-quote-list-items').empty();
    list.forEach(function (row) {
      var $li = $('<li class="tack-quote-list-item"></li>');
      $li.append($('<span class="tack-quote-list-item-name"></span>').text(row.name));
      $li.append($('<span class="tack-quote-list-item-qty"></span>').text('×' + row.quantity));
      var $remove = $(
        '<button type="button" class="tack-quote-list-item-remove" aria-label="' +
          escapeHtml(TackQuotes.i18n.remove) +
          '">&times;</button>',
      );
      $remove.on('click', function () {
        removeFromList(row.productId, row.variationId);
      });
      $li.append($remove);
      $items.append($li);
    });

    $('#tack-quote-list-checkout').prop('disabled', list.length === 0);
  }

  function escapeHtml(str) {
    return String(str == null ? '' : str).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  // ─── Request-a-quote modal (single product or the whole quote list) ──────

  // ─── Form fields, driven by the seller's registration policy ────────────────
  //
  // The form used to be a single email box. That is why the API had to invent a buyer name
  // from the email local part (`email.split('@')[0]`), so sellers saw contacts called
  // "woo-buyer" with no way to tell the name was fabricated — and why no company could ever
  // be registered from WooCommerce even when the seller's policy required one.
  //
  // Which fields appear is NOT hardcoded here: it comes from
  // GET /integrations/woocommerce/registration-config (the seller's own policy — company vs
  // individual, which company details are mandatory). `TackQuotes.registration` is null when
  // that call failed, and the fallback is a name + email form rather than nothing, so a
  // shopper can still ask for a quote when our API is unreachable.

  function reg() {
    return TackQuotes.registration || null;
  }

  function field(id, name, label, opts) {
    var o = opts || {};
    return (
      '<div class="tack-quote-field' + (o.half ? ' tack-quote-field-half' : '') + '">' +
      '<label for="' + id + '">' +
      escapeHtml(label) +
      (o.required ? '' : ' <span class="tack-quote-optional">' + escapeHtml(TackQuotes.i18n.optional) + '</span>') +
      '</label>' +
      '<input type="' + (o.type || 'text') + '" id="' + id + '" name="' + name + '"' +
      (o.placeholder ? ' placeholder="' + escapeHtml(o.placeholder) + '"' : '') +
      (o.required ? ' required' : '') +
      ' />' +
      '</div>'
    );
  }

  function buildIdentityFields() {
    var i18n = TackQuotes.i18n;
    var html =
      '<div class="tack-quote-field-row">' +
      field('tack-quote-first-name', 'firstName', i18n.firstNameLabel, { required: true, half: true }) +
      field('tack-quote-last-name', 'lastName', i18n.lastNameLabel, { half: true }) +
      '</div>' +
      field('tack-quote-email', 'email', i18n.emailLabel, {
        type: 'email',
        required: true,
        placeholder: i18n.emailPlaceholder,
      }) +
      field('tack-quote-phone', 'phone', i18n.phoneLabel, { type: 'tel' });

    // Only offer the individual/company choice when the seller actually allows both.
    // A company_only or buyer_only policy has nothing to choose, and rendering a
    // single-option radio group is noise that implies a choice the seller does not offer.
    var r = reg();
    if (r && r.allowCompany && r.allowIndividual) {
      html +=
        '<fieldset class="tack-quote-field tack-quote-buying-as">' +
        '<legend>' + escapeHtml(i18n.buyingAsLabel) + '</legend>' +
        '<label><input type="radio" name="buyingAs" value="individual" checked /> ' +
        escapeHtml(i18n.buyingAsIndividual) + '</label>' +
        '<label><input type="radio" name="buyingAs" value="company" /> ' +
        escapeHtml(i18n.buyingAsCompany) + '</label>' +
        '</fieldset>';
    }
    return html;
  }

  function companyFieldLabel(key) {
    var known = TackQuotes.i18n.companyFields || {};
    if (known[key]) {
      return known[key];
    }
    // Humanise an unknown key rather than printing it raw: the seller can add policy fields
    // we have no translation for, and "taxId" is friendlier than nothing but "Tax Id" is
    // friendlier still.
    return key
      .replace(/([A-Z])/g, ' $1')
      .replace(/[_-]+/g, ' ')
      .replace(/^./, function (c) {
        return c.toUpperCase();
      })
      .trim();
  }

  function buildCompanyFields() {
    var r = reg();
    if (!r || !r.allowCompany) {
      return '';
    }
    var i18n = TackQuotes.i18n;
    // company_only means every shopper is a company, so the section is always shown and
    // always required. When both modes are allowed it starts hidden and the radio reveals it.
    var alwaysCompany = !r.allowIndividual;
    var required = Array.isArray(r.requiredCompanyFields) ? r.requiredCompanyFields : [];

    var html =
      '<div class="tack-quote-company-section"' + (alwaysCompany ? '' : ' hidden') + '>' +
      '<h3 class="tack-quote-company-heading">' + escapeHtml(i18n.companyHeading) + '</h3>' +
      field('tack-quote-company-name', 'companyName', i18n.companyNameLabel, { required: true });

    for (var i = 0; i < required.length; i++) {
      var key = required[i];
      // companyName is rendered above; a policy that also lists it must not duplicate it.
      if (key === 'companyName' || key === 'name') {
        continue;
      }
      html += field('tack-quote-company-' + key, 'company[' + key + ']', companyFieldLabel(key), {
        required: true,
      });
    }

    html += '</div>';
    return html;
  }

  function buildModal() {
    var i18n = TackQuotes.i18n;

    var overlay = document.createElement('div');
    overlay.className = 'tack-quote-modal-overlay';
    overlay.setAttribute('hidden', 'hidden');

    overlay.innerHTML =
      '<div class="tack-quote-modal" role="dialog" aria-modal="true" aria-labelledby="tack-quote-modal-title">' +
      '<button type="button" class="tack-quote-modal-close" aria-label="' +
      escapeHtml(i18n.close) +
      '">&times;</button>' +
      '<h2 id="tack-quote-modal-title">' +
      escapeHtml(i18n.modalTitle) +
      '</h2>' +
      '<form class="tack-quote-modal-form" novalidate>' +
      buildIdentityFields() +
      buildCompanyFields() +
      '<div class="tack-quote-field">' +
      '<label for="tack-quote-note">' +
      escapeHtml(i18n.noteLabel) +
      ' <span class="tack-quote-optional">' +
      escapeHtml(i18n.optional) +
      '</span></label>' +
      '<textarea id="tack-quote-note" name="note" rows="3" placeholder="' +
      escapeHtml(i18n.notePlaceholder) +
      '"></textarea>' +
      '</div>' +
      '<p class="tack-quote-modal-error" hidden></p>' +
      '<p class="tack-quote-modal-success" hidden></p>' +
      '<div class="tack-quote-modal-actions">' +
      '<button type="button" class="tack-quote-modal-cancel">' +
      escapeHtml(i18n.cancel) +
      '</button>' +
      '<button type="submit" class="tack-quote-modal-submit">' +
      escapeHtml(i18n.submit) +
      '</button>' +
      '</div>' +
      '</form>' +
      '</div>';

    document.body.appendChild(overlay);
    return overlay;
  }

  function openModal(context) {
    if (!modal) {
      modal = buildModal();
    }

    var $overlay = $(modal);
    var $form = $overlay.find('form');
    var $email = $overlay.find('#tack-quote-email');
    var $note = $overlay.find('#tack-quote-note');
    var $error = $overlay.find('.tack-quote-modal-error');
    var $success = $overlay.find('.tack-quote-modal-success');
    var $submit = $overlay.find('.tack-quote-modal-submit');

    $form[0].reset();
    $email.val(TackQuotes.customerEmail || '');
    $note.val('');
    $error.hide().text('');
    $success.hide().text('');
    $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
    $form.show();

    modal.removeAttribute('hidden');
    document.body.classList.add('tack-quote-modal-open');
    ($email.val() ? $overlay.find('.tack-quote-modal-submit') : $email).trigger('focus');

    // Company section follows the individual/company choice. Only present when the seller
    // allows both; a company_only policy renders it always-visible with no radio to drive it.
    $overlay
      .off('change.tackBuyingAs')
      .on('change.tackBuyingAs', 'input[name="buyingAs"]', function () {
        var isCompany = $overlay.find('input[name="buyingAs"]:checked').val() === 'company';
        var $section = $overlay.find('.tack-quote-company-section');
        $section.prop('hidden', !isCompany);
        // Required-ness has to follow visibility, or the browser blocks submission on a
        // field the shopper cannot see. novalidate is set on the form, but the server
        // validates too and a hidden required field would fail there instead.
        $section.find('input').prop('disabled', !isCompany);
      });

    $form.off('submit').on('submit', function (e) {
      e.preventDefault();
      submitRequest(context, $overlay);
    });
  }

  function closeModal() {
    if (!modal) {
      return;
    }
    modal.setAttribute('hidden', 'hidden');
    document.body.classList.remove('tack-quote-modal-open');
  }

  function submitRequest(context, $overlay) {
    var $error = $overlay.find('.tack-quote-modal-error');
    var $success = $overlay.find('.tack-quote-modal-success');
    var $submit = $overlay.find('.tack-quote-modal-submit');
    var $form = $overlay.find('form');
    var val = function (sel) {
      return ($overlay.find(sel).val() || '').trim();
    };

    var email = val('#tack-quote-email');
    var note = val('#tack-quote-note');
    var firstName = val('#tack-quote-first-name');

    $error.hide().text('');

    var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    if (!email || !emailPattern.test(email)) {
      $error.text(TackQuotes.i18n.emailRequired).show();
      return;
    }
    // Checked client-side purely so the shopper gets an inline message instead of a round
    // trip; the server is still the authority and rejects it independently.
    if (!firstName) {
      $error.text(TackQuotes.i18n.firstNameRequired).show();
      return;
    }

    var isCompany =
      $overlay.find('input[name="buyingAs"]:checked').val() === 'company' ||
      ($overlay.find('.tack-quote-company-section').length > 0 &&
        !$overlay.find('input[name="buyingAs"]').length);

    var company = {};
    var companyMissing = false;
    if (isCompany) {
      $overlay.find('.tack-quote-company-section input').each(function () {
        var name = this.name;
        var v = (this.value || '').trim();
        if (this.required && !v) {
          companyMissing = true;
        }
        var m = name.match(/^company\[(.+)\]$/);
        if (m) {
          company[m[1]] = v;
        }
      });
      if (companyMissing) {
        $error.text(TackQuotes.i18n.companyRequired).show();
        return;
      }
    }

    var payload = {
      action: 'tack_request_quote',
      nonce: TackQuotes.nonce,
      email: email,
      note: note,
      first_name: firstName,
      last_name: val('#tack-quote-last-name'),
      phone: val('#tack-quote-phone'),
    };

    if (isCompany) {
      payload.company_name = val('#tack-quote-company-name');
      // Sent as a nested object under `company[...]`; jQuery serialises this into
      // company[taxId]=... which PHP parses back into an array, matching the API's
      // CompanyDetailsInput shape without any manual encoding.
      payload.company = company;
    }

    if (context.items) {
      // "Checkout as Quote" from the quote-list drawer — the server
      // re-derives name/SKU/price from each product_id; quantity is the
      // only other value trusted from the client.
      payload.items = JSON.stringify(
        context.items.map(function (row) {
          return {
            product_id: row.productId,
            variation_id: row.variationId || 0,
            quantity: row.quantity,
          };
        }),
      );
    } else {
      // "Request a Quote" — a single product, submitted immediately.
      payload.product_id = context.productId || 0;
      payload.quantity = $('input.qty').val() || 1;
      // On a variable product the button can only carry the PARENT id, so the shopper's
      // chosen variation has to be read from WooCommerce's own variation form, which keeps
      // the selected id in a hidden input[name="variation_id"] (0 when nothing is chosen
      // yet). Without this a quote for "X-Large" was recorded against the parent — wrong
      // SKU, and the parent's cheapest price. The server re-validates that this variation
      // really belongs to product_id before using it.
      var variationId = Number($('input[name="variation_id"]').val()) || 0;
      if (variationId) {
        payload.variation_id = variationId;
      }
    }

    $submit.prop('disabled', true).text(TackQuotes.i18n.sending);

    $.post(TackQuotes.ajaxUrl, payload)
      .done(function (res) {
        if (res && res.success) {
          $form.hide();
          // When the seller's policy requires company approval, the quote IS created but the
          // account is not usable yet. Saying "redirecting you to it now" and then dropping
          // the shopper on a login they cannot pass is worse than telling them the truth.
          var awaiting = res.data && res.data.awaitingApproval;
          $success
            .text(awaiting ? TackQuotes.i18n.awaitingApproval : TackQuotes.i18n.success)
            .show();
          if (context.items) {
            clearList();
          }
          var portalUrl = res.data && res.data.portalUrl;
          if (portalUrl && !awaiting) {
            window.setTimeout(function () {
              window.location.href = portalUrl;
            }, 900);
          }
        } else {
          $error.text((res && res.data && res.data.message) || TackQuotes.i18n.error).show();
          $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
        }
      })
      .fail(function (xhr) {
        var msg = TackQuotes.i18n.error;
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          msg = xhr.responseJSON.data.message;
        }
        $error.text(msg).show();
        $submit.prop('disabled', false).text(TackQuotes.i18n.submit);
      });
  }

  // ─── Event wiring ──────────────────────────────────────────────────────────

  // ─── Variable products: mirror WooCommerce's own button gating ──────────────
  //
  // The server already refuses a variable product with no variation chosen (it would
  // otherwise quote the parent SKU at the cheapest variation's price). This is the UX half:
  // WooCommerce keeps its own add-to-cart button in `disabled wc-variation-selection-needed`
  // until a purchasable variation is found, so a quote button sitting in the same form that
  // stays clickable is inconsistent and invites the error the server then rejects.
  //
  // `show_variation` / `hide_variation` are the events core itself listens to in
  // assets/js/frontend/add-to-cart-variation.js — verified against the installed source
  // rather than assumed, since these names are not part of any documented public API.
  $(document).on('show_variation', 'form.variations_form', function (event, variation, purchasable) {
    $(this)
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', !purchasable)
      .toggleClass('disabled', !purchasable);
  });

  $(document).on('hide_variation reset_data', 'form.variations_form', function () {
    $(this)
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', true)
      .addClass('disabled');
  });

  // Initial state: a variable product loads with nothing selected, so the buttons must
  // start disabled. Non-variable products have no variations_form and are untouched.
  $(function () {
    $('form.variations_form')
      .find('.tack-quote-btn, .tack-add-to-quote-btn')
      .prop('disabled', true)
      .addClass('disabled');
  });

  // "Request a Quote" (product page) — single product, immediate.
  $(document).on('click', '.tack-quote-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    openModal({ productId: $btn.data('product-id') || 0 });
  });

  // "Add to Quote" (product page) — adds to the browser-side quote list.
  // Never touches the WooCommerce cart, so it can't affect stock, cart
  // totals, or normal checkout.
  $(document).on('click', '.tack-add-to-quote-btn', function (e) {
    e.preventDefault();
    var $btn = $(this);
    var quantity = Number($('input.qty').val()) || 1;

    // On a variable product the button carries the PARENT's id/name/sku/price, so the
    // chosen variation has to come from WooCommerce's own hidden input. The server
    // re-derives every value from these ids; the rest is only what the drawer displays.
    var $form = $btn.closest('form.variations_form');
    var variationId = Number($form.find('input[name="variation_id"]').val()) || 0;
    var variationLabel = $form
      .find('select[name^="attribute_"]')
      .map(function () {
        return $(this).val();
      })
      .get()
      .filter(Boolean)
      .join(' / ');

    addToList({
      productId: $btn.data('product-id') || 0,
      variationId: variationId,
      name:
        ($btn.data('product-name') || '') +
        (variationLabel ? ' - ' + variationLabel : ''),
      sku: $btn.data('product-sku') || '',
      price: Number($btn.data('product-price')) || 0,
      quantity: quantity,
    });

    var original = $btn.text();
    $btn.text(TackQuotes.i18n.added);
    window.setTimeout(function () {
      $btn.text(original);
    }, 1200);
  });

  // Floating quote-list widget.
  $(document).on('click', '#tack-quote-list-toggle', function () {
    $('#tack-quote-list-drawer').prop('hidden', false);
  });

  $(document).on('click', '#tack-quote-list-close', function () {
    $('#tack-quote-list-drawer').prop('hidden', true);
  });

  // "Checkout as Quote" — submits the whole quote list.
  $(document).on('click', '#tack-quote-list-checkout', function () {
    var list = getList();
    if (!list.length) {
      return;
    }
    openModal({ items: list });
  });

  $(document).on('click', '.tack-quote-modal-overlay', function (e) {
    if (e.target === this) {
      closeModal();
    }
  });

  $(document).on('click', '.tack-quote-modal-close, .tack-quote-modal-cancel', function (e) {
    e.preventDefault();
    closeModal();
  });

  $(document).on('keydown', function (e) {
    if (e.key === 'Escape' && modal && !modal.hasAttribute('hidden')) {
      closeModal();
    }
  });

  // Initial render on page load (in case the list was populated earlier).
  $(function () {
    renderList(getList());
  });
})(jQuery);
