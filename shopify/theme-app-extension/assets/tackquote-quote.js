/*
 * "Add to Quote" and "Request a Quote".
 *
 * Both POST to `<proxy>/quote-request` through the App Proxy, on the MERCHANT's
 * own domain, exactly like the read blocks. Shopify signs `shop` and the server
 * derives the tenant from that signature, so no tenant id travels from this page
 * and there is none to configure in the theme editor.
 *
 * This docblock used to say the opposite — "TackQuote's public widget endpoint
 * with a merchant-configured tenant id" — which was true before these two blocks
 * were moved onto the proxy, and stayed on the file afterwards. It is called out
 * rather than quietly deleted because a comment that understates the security
 * posture is an invitation to "restore" the caller-supplied tenant id it
 * describes.
 *
 * ---------------------------------------------------------------------------
 * There is no "add to quote" ENDPOINT, and that is the design
 * ---------------------------------------------------------------------------
 * `POST /quote-request` is the only write on this surface. "Add to Quote"
 * therefore accumulates lines in localStorage and submits the whole basket
 * through that same endpoint when the shopper sends it; "Request a Quote"
 * submits just the product in front of them. The difference is entirely on this
 * side of the wire — a server-side draft would need a shopper identity to hang
 * itself on, and an anonymous visitor has none to offer.
 *
 * No per-line price is ever sent. See snippets/tackquote-selection.liquid.
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  const KEY = `tackquote:draft:${window.location.host}`;
  const MAX_ITEMS = 100;

  function loadDraft() {
    try {
      const parsed = JSON.parse(window.localStorage.getItem(KEY) || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (_err) {
      // Private mode, blocked storage, or corrupt JSON. An empty draft is a
      // working block; a thrown error is a dead button.
      return [];
    }
  }

  function saveDraft(items) {
    try {
      window.localStorage.setItem(KEY, JSON.stringify(items));
    } catch (_err) {
      /* the draft simply will not persist across pages */
    }
  }

  function drawer() {
    return document.querySelector('[data-tackquote-drawer]');
  }

  function renderItems(el, items, persist) {
    const list = el.querySelector('[data-tackquote-items]');
    list.textContent = '';
    el.querySelector('[data-tackquote-empty]').hidden = items.length > 0;

    items.forEach((item, index) => {
      const li = document.createElement('li');

      const name = document.createElement('span');
      name.className = 'tackquote-drawer__name';
      // textContent, never innerHTML — product titles are merchant data.
      name.textContent = item.name;

      const qty = document.createElement('input');
      qty.type = 'number';
      qty.min = '1';
      qty.value = String(item.quantity);
      qty.setAttribute('aria-label', el.dataset.msgQuantity);
      qty.addEventListener('change', () => {
        const n = Number.parseInt(qty.value, 10);
        items[index].quantity = Number.isFinite(n) && n > 0 ? n : 1;
        if (persist) saveDraft(items);
      });

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'tackquote-drawer__remove';
      remove.textContent = el.dataset.msgRemove;
      remove.addEventListener('click', () => {
        items.splice(index, 1);
        if (persist) saveDraft(items);
        renderItems(el, items, persist);
      });

      li.appendChild(name);
      li.appendChild(qty);
      li.appendChild(remove);
      list.appendChild(li);
    });
  }

  function send(el, ctx, button, status) {
    const name = el.querySelector('[name="name"]').value.trim();
    const email = el.querySelector('[name="email"]').value.trim();
    if (!name || !email || !ctx.items.length) {
      status.textContent = el.dataset.msgRequired;
      return;
    }

    button.disabled = true;
    status.textContent = el.dataset.msgSending;

    // Through the App Proxy, on the MERCHANT'S OWN domain. Shopify signs `shop`
    // and forwards it, so the server derives the tenant from that signature —
    // there is no tenantId in this body, and no tenant id in the theme editor.
    fetch(`${ctx.proxy}/quote-request`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        currency: ctx.currency,
        buyer: {
          name: name,
          email: email,
          company: el.querySelector('[name="company"]').value.trim() || undefined,
        },
        // Honeypot. Real browsers leave it empty; the API treats a filled value
        // as a bot and returns a success-shaped response creating nothing.
        hp: el.querySelector('[name="hp"]').value,
        items: ctx.items.map((i) => ({
          name: i.name,
          sku: i.sku || undefined,
          quantity: i.quantity,
        })),
      }),
    })
      .then((r) => {
        if (!r.ok) throw new Error(`HTTP ${r.status}`);
        return r.json();
      })
      .then(() => {
        status.textContent = el.dataset.msgSuccess;
        if (ctx.persist) saveDraft([]);
        renderItems(el, [], ctx.persist);
      })
      .catch(() => {
        status.textContent = el.dataset.msgFailure;
        button.disabled = false;
      });
  }

  function open(ctx) {
    const el = drawer();
    if (!el) return;

    if (ctx.customerName) el.querySelector('[name="name"]').value = ctx.customerName;
    if (ctx.customerEmail) el.querySelector('[name="email"]').value = ctx.customerEmail;

    renderItems(el, ctx.items, ctx.persist);

    const status = el.querySelector('[data-tackquote-drawer-status]');
    status.textContent = '';

    // Replace the button to drop listeners from a previous open.
    const old = el.querySelector('[data-tackquote-submit]');
    const button = old.cloneNode(true);
    button.disabled = false;
    old.parentNode.replaceChild(button, old);
    button.addEventListener('click', () => {
      send(el, ctx, button, status);
    });

    if (!el.open) el.showModal();
  }

  ns.boot(
    '.tackquote-block[data-tackquote-mode="add"], .tackquote-block[data-tackquote-mode="request"]',
    (root) => {
      const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
      const button = root.querySelector('[data-tackquote-action]');
      const status = root.querySelector('[data-tackquote-status]');
      if (!proxy || !button) return;

      const variants = ns.variants(root);

      button.addEventListener('click', () => {
        const el = drawer();
        const variant = ns.findVariant(variants, ns.variantId(root));
        if (variant && variant.available === false) {
          status.textContent = el ? el.dataset.msgUnavailable : '';
          return;
        }

        const item = {
          variantId: ns.variantId(root),
          name: ns.label(root, variant),
          sku: variant ? variant.sku : '',
          quantity: ns.quantity(root),
        };

        const ctx = {
          proxy: proxy,
          currency: root.dataset.tackquoteCurrency,
          customerName: root.dataset.tackquoteCustomerName,
          customerEmail: root.dataset.tackquoteCustomerEmail,
        };

        if (root.dataset.tackquoteMode === 'add') {
          const items = loadDraft();
          const existing = items.filter((i) => i.variantId === item.variantId)[0];

          if (existing) {
            existing.quantity += item.quantity;
          } else if (items.length >= MAX_ITEMS) {
            // The API caps a request at 100 lines and rejects the whole body past
            // that, so stop here rather than building a payload it will refuse.
            return;
          } else {
            items.push(item);
          }

          saveDraft(items);
          status.textContent = existing ? el.dataset.msgAlready : el.dataset.msgAdded;
          ctx.items = items;
          ctx.persist = true;
        } else {
          // "Request a Quote" is about THIS product, so it never picks up whatever
          // else is sitting in the shopper's accumulated draft.
          ctx.items = [item];
          ctx.persist = false;
        }

        open(ctx);
      });
    },
  );
});
