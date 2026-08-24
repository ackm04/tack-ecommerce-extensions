/**
 * TackQuote for OpenCart — storefront quote list and request form.
 *
 * Behaviour matches the WooCommerce plugin's drawer and the Magento module's three-step
 * form, so a buyer meets the same flow on any of the three platforms:
 *
 *   1. "Add to Quote" collects products into a list held in localStorage.
 *   2. A floating launcher shows the count and opens a panel.
 *   3. The panel walks items → contact details → confirmation.
 *
 * TWO RULES THIS FILE KEEPS.
 *
 * The list NEVER touches the OpenCart cart. Quoting is not buying: a shopper must be able to
 * price a basket of twelve items without their cart, shipping estimate or abandoned-cart
 * email being affected.
 *
 * PRICES ARE NEVER SENT. Only product ids and quantities go to the server, which re-reads
 * every price from the catalog (see Tackquotes::quoteList()). Anything a page hands the
 * browser can be edited in devtools, so a client-supplied price is a discount anyone can
 * grant themselves. The price shown in the panel is display-only and comes from the tile or
 * product page the buyer was looking at.
 */
(function () {
  'use strict';

  var STORAGE_KEY = 'tack_quote_list';
  var MAX_ITEMS = 50;      // Mirrors the server-side cap; keeps a runaway loop cheap.
  var MAX_QTY = 999999;

  var root = document.getElementById('tackquote-app');
  if (!root) {
    return;
  }

  var config;
  try {
    config = JSON.parse(root.getAttribute('data-config') || '{}');
  } catch (e) {
    return;
  }
  var text = config.text || {};

  // ── Storage ───────────────────────────────────────────────────────────────────────────
  //
  // localStorage throws in Safari private browsing and when the quota is full, and it can
  // hold anything a previous version (or another tab) wrote. Every read is validated field
  // by field; a corrupt entry is dropped rather than rendered.
  function readList() {
    var raw, parsed;
    try {
      raw = window.localStorage.getItem(STORAGE_KEY);
    } catch (e) {
      return [];
    }
    if (!raw) {
      return [];
    }
    try {
      parsed = JSON.parse(raw);
    } catch (e) {
      return [];
    }
    if (!Array.isArray(parsed)) {
      return [];
    }
    return parsed.reduce(function (out, row) {
      if (!row || typeof row !== 'object') {
        return out;
      }
      var id = parseInt(row.productId, 10);
      if (!id || id < 1) {
        return out;
      }
      out.push({
        productId: id,
        sku: typeof row.sku === 'string' ? row.sku : '',
        name: typeof row.name === 'string' && row.name ? row.name : String(id),
        price: typeof row.price === 'string' ? row.price : '',
        qty: Math.min(MAX_QTY, Math.max(1, parseInt(row.qty, 10) || 1))
      });
      return out;
    }, []).slice(0, MAX_ITEMS);
  }

  function writeList(list) {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
    } catch (e) {
      // Worst case the list does not survive a reload. Not worth interrupting the buyer.
    }
    render();
  }

  function addItem(item) {
    var list = readList();
    for (var i = 0; i < list.length; i++) {
      if (list[i].productId === item.productId) {
        list[i].qty = Math.min(MAX_QTY, list[i].qty + item.qty);
        writeList(list);
        return true;
      }
    }
    if (list.length >= MAX_ITEMS) {
      return false;
    }
    list.push(item);
    writeList(list);
    return true;
  }

  function setQty(productId, qty) {
    var list = readList();
    for (var i = 0; i < list.length; i++) {
      if (list[i].productId === productId) {
        list[i].qty = Math.min(MAX_QTY, Math.max(1, qty || 1));
      }
    }
    writeList(list);
  }

  function removeItem(productId) {
    writeList(readList().filter(function (row) {
      return row.productId !== productId;
    }));
  }

  // ── Elements ──────────────────────────────────────────────────────────────────────────
  var els = {
    fab: root.querySelector('[data-tackquote-open]'),
    count: root.querySelector('[data-tackquote-count]'),
    overlay: root.querySelector('[data-tackquote-overlay]'),
    close: root.querySelector('[data-tackquote-close]'),
    items: root.querySelector('[data-tackquote-items]'),
    empty: root.querySelector('[data-tackquote-empty]'),
    steps: root.querySelectorAll('[data-tackquote-step]'),
    stepNav: root.querySelectorAll('[data-tackquote-steps] li'),
    back: root.querySelector('[data-tackquote-back]'),
    next: root.querySelector('[data-tackquote-next]'),
    submit: root.querySelector('[data-tackquote-submit]'),
    portal: root.querySelector('[data-tackquote-portal]'),
    error: root.querySelector('[data-tackquote-error]'),
    done: root.querySelector('[data-tackquote-done]'),
    email: root.querySelector('#tackquote-email')
  };

  var step = 1;

  function render() {
    var list = readList();
    if (els.count) {
      els.count.textContent = String(list.length);
    }
    if (els.fab) {
      els.fab.hidden = list.length === 0;
    }
    if (els.empty) {
      els.empty.hidden = list.length > 0;
    }
    if (!els.items) {
      return;
    }
    els.items.textContent = '';
    list.forEach(function (row) {
      var li = document.createElement('li');
      li.className = 'tackquote-item';

      var main = document.createElement('div');
      main.className = 'tackquote-item__main';

      var name = document.createElement('span');
      name.className = 'tackquote-item__name';
      name.textContent = row.name;
      main.appendChild(name);

      var meta = document.createElement('span');
      meta.className = 'tackquote-item__meta';
      meta.textContent = row.sku ? row.sku : '';
      main.appendChild(meta);
      li.appendChild(main);

      var price = document.createElement('span');
      price.className = 'tackquote-item__price';
      price.textContent = row.price || (text.priceOnRequest || '');
      li.appendChild(price);

      var qty = document.createElement('input');
      qty.type = 'number';
      qty.min = '1';
      qty.className = 'tackquote-item__qty';
      qty.value = String(row.qty);
      qty.setAttribute('aria-label', 'Quantity');
      qty.addEventListener('change', function () {
        setQty(row.productId, parseInt(qty.value, 10));
      });
      li.appendChild(qty);

      var remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'tackquote-item__remove';
      remove.setAttribute('aria-label', 'Remove');
      remove.innerHTML = '&times;';
      remove.addEventListener('click', function () {
        removeItem(row.productId);
      });
      li.appendChild(remove);

      els.items.appendChild(li);
    });
  }

  function showError(message) {
    if (!els.error) {
      return;
    }
    els.error.textContent = message;
    els.error.hidden = !message;
  }

  function goToStep(next) {
    step = next;
    for (var i = 0; i < els.steps.length; i++) {
      var section = els.steps[i];
      var isCurrent = section.getAttribute('data-tackquote-step') === String(step);
      section.hidden = !isCurrent;
      section.classList.toggle('is-current', isCurrent);
    }
    for (var j = 0; j < els.stepNav.length; j++) {
      els.stepNav[j].classList.toggle('is-current', j === step - 1);
      els.stepNav[j].classList.toggle('is-done', j < step - 1);
    }
    if (els.back) {
      els.back.hidden = step !== 2;
    }
    if (els.next) {
      els.next.hidden = step !== 1;
    }
    if (els.submit) {
      els.submit.hidden = step !== 2;
    }
    showError('');
  }

  function openPanel(startStep) {
    if (!els.overlay) {
      return;
    }
    prefill();
    render();
    els.overlay.hidden = false;
    document.body.classList.add('tackquote-locked');
    goToStep(startStep || 1);
  }

  function closePanel() {
    if (els.overlay) {
      els.overlay.hidden = true;
    }
    document.body.classList.remove('tackquote-locked');
  }

  var prefilled = false;
  function prefill() {
    if (prefilled || !config.customer) {
      return;
    }
    prefilled = true;
    var map = {
      '#tackquote-email': config.customer.email,
      '#tackquote-first-name': config.customer.firstName,
      '#tackquote-last-name': config.customer.lastName,
      '#tackquote-telephone': config.customer.telephone
    };
    Object.keys(map).forEach(function (selector) {
      var field = root.querySelector(selector);
      if (field && !field.value && map[selector]) {
        field.value = map[selector];
      }
    });
  }

  // ── Submit ────────────────────────────────────────────────────────────────────────────
  function submit() {
    var list = readList();
    if (!list.length) {
      showError(text.errorEmptyList || 'Your quote list is empty.');
      return;
    }
    var email = (els.email && els.email.value || '').trim();
    // Deliberately loose: a client-side pattern that is stricter than the server's
    // filter_var() rejects addresses the server would have accepted. The server is the
    // authority; this only catches the obvious typo before a round trip.
    if (email.indexOf('@') < 1 || email.length < 5) {
      showError(text.errorEmail || 'Enter a valid email address.');
      if (els.email) {
        els.email.focus();
      }
      return;
    }

    var body = new URLSearchParams();
    body.set('email', email);
    body.set('firstName', value('#tackquote-first-name'));
    body.set('lastName', value('#tackquote-last-name'));
    body.set('company', value('#tackquote-company'));
    body.set('telephone', value('#tackquote-telephone'));
    body.set('note', value('#tackquote-note'));
    list.forEach(function (row, index) {
      body.set('items[' + index + '][product_id]', String(row.productId));
      body.set('items[' + index + '][quantity]', String(row.qty));
    });

    els.submit.disabled = true;
    showError('');

    fetch(config.submitUrl, {
      method: 'POST',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded'
      },
      body: body.toString(),
      credentials: 'same-origin'
    })
      .then(function (response) {
        return response.json().catch(function () {
          throw new Error('bad-json');
        });
      })
      .then(function (json) {
        els.submit.disabled = false;
        if (json.error) {
          showError(json.error);
          return;
        }
        renderDone(json);
        writeList([]);
        goToStep(3);
      })
      .catch(function () {
        els.submit.disabled = false;
        showError('Could not reach the store. Please try again.');
      });
  }

  function renderDone(json) {
    if (!els.done) {
      return;
    }
    els.done.textContent = '';
    var heading = document.createElement('p');
    heading.className = 'tackquote-done__lead';
    heading.textContent = json.success || 'Quote request sent.';
    els.done.appendChild(heading);

    if (json.quoteNumber) {
      var ref = document.createElement('p');
      ref.className = 'tackquote-done__ref';
      ref.textContent = json.quoteNumber;
      els.done.appendChild(ref);
    }
    if (els.portal) {
      if (json.portalUrl) {
        els.portal.href = json.portalUrl;
        els.portal.hidden = false;
      } else {
        els.portal.hidden = true;
      }
    }
  }

  function value(selector) {
    var field = root.querySelector(selector);
    return field ? field.value.trim() : '';
  }

  // ── Product page controls ─────────────────────────────────────────────────────────────
  function bindProductControls() {
    var block = document.querySelector('[data-tackquote-product]');
    if (!block) {
      return;
    }
    var productId = parseInt(block.getAttribute('data-product-id'), 10);
    var sku = block.getAttribute('data-sku') || '';
    var name = block.getAttribute('data-name') || '';
    var note = block.querySelector('[data-tackquote-note]');

    function currentQty() {
      // OpenCart's own quantity input on the product page. Respecting it is what makes
      // "Add to Quote" feel like "Add to Cart" rather than a separate widget.
      var input = document.querySelector('#input-quantity');
      var parsed = input ? parseInt(input.value, 10) : 1;
      return Math.max(1, parsed || 1);
    }

    function currentPrice() {
      var el = document.querySelector('.product-price, #price-special, .price-new, .price');
      return el ? el.textContent.trim().split('\n')[0] : '';
    }

    var add = block.querySelector('[data-tackquote-add]');
    if (add) {
      add.addEventListener('click', function () {
        var ok = addItem({
          productId: productId,
          sku: sku,
          name: name,
          price: currentPrice(),
          qty: currentQty()
        });
        if (note) {
          note.textContent = ok
            ? (text.added || 'Added to your quote list.')
            : 'Your quote list is full.';
          note.hidden = false;
        }
      });
    }

    var request = block.querySelector('[data-tackquote-request]');
    if (request) {
      request.addEventListener('click', function () {
        // Fast path, same as Magento's "Request a Quote" on a product page: put this
        // product in the list if it is not already there, then jump straight to the form.
        var list = readList();
        var present = list.some(function (row) {
          return row.productId === productId;
        });
        if (!present) {
          addItem({
            productId: productId,
            sku: sku,
            name: name,
            price: currentPrice(),
            qty: currentQty()
          });
        }
        openPanel(config.listEnabled ? 1 : 2);
      });
    }
  }

  // Hides the cart submit button on every category/search tile.
  //
  // Anchored on `formaction` rather than on a class: OpenCart's tile template gives the
  // three buttons no distinguishing class at all, only different formaction URLs
  // (catalog/view/template/product/thumb.twig:30-32), so matching `checkout/cart.add` is
  // the only way to hit the cart button and leave wishlist and compare alone. A theme that
  // renamed the route simply keeps its button, and the server still refuses the POST.
  function hideTileCartButtons() {
    var buttons = document.querySelectorAll('.product-thumb form button[formaction*="checkout/cart.add"]');
    for (var i = 0; i < buttons.length; i++) {
      buttons[i].hidden = true;
      buttons[i].setAttribute('data-tackquote-hidden', '1');
    }
  }

  // ── Category / search tiles ───────────────────────────────────────────────────────────
  //
  // OpenCart's tile markup (catalog/view/template/product/thumb.twig) wraps the cart,
  // wishlist and compare buttons in `form > div.button` with a hidden product_id. The button
  // is appended client-side rather than injected server-side because the tile template is
  // rendered once per product inside a loop; string-patching each repetition of a theme's
  // markup is exactly the kind of surgery that breaks on the next theme.
  //
  // QUOTE-ONLY MODE, and what this function is NOT.
  //
  // When config.quoteOnly is set, the tile's own cart submit button is hidden here. That is
  // COSMETIC and nothing else: the server refuses index.php?route=checkout/cart.add whether
  // or not this code ran, whether or not JS is enabled, and whether or not someone deleted
  // the style with devtools (catalog/controller/quotemode.php::guardCart). Hiding it is
  // about not offering a shopper a button that is going to fail.
  //
  // The add-to-quote tile button is FORCED ON in quote-only mode even if the merchant
  // switched tile buttons off, for the same reason productPage() overrides the placement
  // toggle: a tile with a hidden cart button and no quote button offers nothing.
  function bindTiles() {
    var quoteOnly = !!config.quoteOnly;

    if (quoteOnly) {
      hideTileCartButtons();
    }

    // With the multi-product quote list switched off there is no list for a tile button to
    // add to, so tiles keep only their product links; the product page still carries the
    // Request a Quote button. Documented in the settings screen rather than worked around,
    // because inventing a per-tile single-product form here would duplicate the whole
    // request form once per tile.
    if (!config.listEnabled) {
      return;
    }

    if (!config.listingButtons && !quoteOnly) {
      return;
    }
    // Scoped to `.product-thumb`, core's tile wrapper (thumb.twig:1). The first build
    // selected every `form input[name="product_id"]`, which also matches the product page's
    // own add-to-cart and wishlist forms — so a stray "+" appeared beside the wishlist and
    // compare buttons on every product page.
    var forms = document.querySelectorAll('.product-thumb form input[name="product_id"]');
    for (var i = 0; i < forms.length; i++) {
      (function (hidden) {
        var form = hidden.closest('form');
        if (!form || form.querySelector('[data-tackquote-tile]')) {
          return;
        }
        var group = form.querySelector('div.button') || form;
        var productId = parseInt(hidden.value, 10);
        if (!productId) {
          return;
        }
        var card = hidden.closest('.product-thumb');
        if (!card) {
          return;
        }
        var priceEl = card.querySelector('.price');

        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'tackquote-tile-btn';
        button.setAttribute('data-tackquote-tile', '1');
        button.title = config.addLabel || 'Add to Quote';
        button.setAttribute('aria-label', button.title);
        button.textContent = '+';
        button.addEventListener('click', function (event) {
          event.preventDefault();
          addItem({
            productId: productId,
            sku: '',
            name: tileName(card, productId),
            price: priceEl ? priceEl.textContent.trim().split('\n')[0] : '',
            qty: 1
          });
          button.classList.add('is-added');
          window.setTimeout(function () {
            button.classList.remove('is-added');
          }, 1200);
        });
        group.appendChild(button);
      })(forms[i]);
    }
  }

  /**
   * The product name from a tile.
   *
   * A selector LIST is not "try these in order" — querySelector returns whichever match
   * comes first in the document, and in OpenCart's tile the first `a` is the image link,
   * whose text is empty. That put the product id in the list instead of the name. Each
   * selector is therefore tried separately, and the image's alt text is the last resort
   * (thumb.twig sets both alt and title from the product name).
   */
  function tileName(card, productId) {
    var selectors = ['.description h4 a', 'h4 a', '.caption h4 a', '.description a'];
    for (var i = 0; i < selectors.length; i++) {
      var el = card.querySelector(selectors[i]);
      if (el && el.textContent.trim()) {
        return el.textContent.trim();
      }
    }
    var img = card.querySelector('img[alt]');
    if (img && img.getAttribute('alt').trim()) {
      return img.getAttribute('alt').trim();
    }
    return String(productId);
  }

  // ── Wire up ───────────────────────────────────────────────────────────────────────────
  //
  // A STANDALONE quote trigger — a `[data-tackquote-request]` button that is not inside a
  // product block. The blocked-checkout page (catalog/view/template/quote/blocked.twig)
  // renders one, and a theme may place one anywhere. bindProductControls() only ever binds
  // the one inside `[data-tackquote-product]`, so without this the blocked page would offer
  // a "Request a quote" button that did nothing at all — which in quote-only mode is the
  // last button a shopper has.
  //
  // Delegated from the document so it also covers markup added after this file ran.
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }
    var trigger = target.closest('[data-tackquote-request]');
    if (!trigger || trigger.closest('[data-tackquote-product]')) {
      return;
    }
    event.preventDefault();
    // Always step 1. With no product in hand there is nothing to pre-add, and step 1 is the
    // step that explains an empty list instead of submitting one.
    openPanel(1);
  });

  if (els.fab) {
    els.fab.addEventListener('click', function () {
      openPanel(1);
    });
  }
  if (els.close) {
    els.close.addEventListener('click', closePanel);
  }
  if (els.overlay) {
    els.overlay.addEventListener('click', function (event) {
      if (event.target === els.overlay) {
        closePanel();
      }
    });
  }
  if (els.next) {
    els.next.addEventListener('click', function () {
      if (!readList().length) {
        showError(text.errorEmptyList || 'Your quote list is empty.');
        return;
      }
      goToStep(2);
    });
  }
  if (els.back) {
    els.back.addEventListener('click', function () {
      goToStep(1);
    });
  }
  if (els.submit) {
    els.submit.addEventListener('click', submit);
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && els.overlay && !els.overlay.hidden) {
      closePanel();
    }
  });

  bindProductControls();
  bindTiles();
  render();
})();
