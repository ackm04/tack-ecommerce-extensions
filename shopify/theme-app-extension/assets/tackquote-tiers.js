/*
 * The volume/tier pricing table block.
 *
 * A READ, so it works exactly like `tackquote-price.js` and for the same
 * reasons: the request goes to the MERCHANT's own domain at the app proxy path,
 * Shopify signs the shop and the logged-in customer id with the app secret, and
 * no tenant id travels from this page at all. The identity is asserted by
 * Shopify, never by this file.
 *
 * https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
 *
 * The three resilience rules from `tackquote-price.js` are not restated here in
 * full; they are the same rules and this file obeys them:
 *
 *   1. Every request has a deadline (`ns.fetchJson`).
 *   2. A failure REMOVES the block rather than printing an error beside a
 *      product. Suspended in the theme editor, where a merchant needs to see it
 *      failing and a shopper does not.
 *   3. Cache first, revalidate behind it, with the customer marker in the key.
 *
 * ---------------------------------------------------------------------------
 * Why the table is built from a `<table>` and not a stack of divs
 * ---------------------------------------------------------------------------
 * A quantity-break ladder is tabular data: two columns with a shared header,
 * where a row means nothing without its column. A screen reader user navigating
 * a grid of divs hears a list of unattached numbers. `<caption>` carries the
 * heading so the table is self-describing when a reader lands on it out of
 * context, and `scope` on the header cells is what lets the reader announce
 * "Quantity 10, Price $9.00" rather than reading the two columns separately.
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  /** Matches the price block. B2B contract prices change on the order of weeks. */
  const CACHE_TTL_MS = 5 * 60 * 1000;

  ns.boot('.tackquote-block[data-tackquote-mode="tiers"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-tiers-body]');
    if (!proxy || !body) return;

    const variants = ns.variants(root);
    const designMode = root.dataset.tackquoteDesign === 'true';
    // Not an identity — an identity would have to be signed. This only
    // partitions the cache, so that logging out in the same tab cannot surface
    // a ladder the previous session was entitled to see.
    const customerMarker = root.dataset.tackquoteCustomer || 'anon';
    let inFlight = 0;

    function show(node) {
      root.hidden = false;
      body.textContent = '';
      body.appendChild(node);
    }

    function line(text, className) {
      const p = document.createElement('p');
      if (className) p.className = className;
      p.textContent = text;
      return p;
    }

    function money(amount, currency) {
      try {
        return new Intl.NumberFormat(document.documentElement.lang || 'en', {
          style: 'currency',
          currency: currency || 'USD',
        }).format(amount);
      } catch (_err) {
        return `${String(amount)} ${String(currency || '')}`;
      }
    }

    /* Rule 2. No answer, so get out of the way — hiding rather than emptying,
     * because an empty block still occupies its heading and its spacing, which
     * reads as a broken widget rather than an absent one. */
    function standDown() {
      if (designMode) {
        show(line(root.dataset.msgError, 'tackquote-tiers__error'));
        return;
      }
      root.hidden = true;
    }

    function cell(tag, text, scope) {
      const el = document.createElement(tag);
      if (scope) el.scope = scope;
      el.textContent = text;
      return el;
    }

    function table(data) {
      const el = document.createElement('table');
      el.className = 'tackquote-tiers__table';

      const caption = document.createElement('caption');
      caption.className = 'tackquote-tiers__caption';
      caption.textContent = root.dataset.msgCaption;
      el.appendChild(caption);

      const head = document.createElement('thead');
      const headRow = document.createElement('tr');
      headRow.appendChild(cell('th', root.dataset.msgQuantity, 'col'));
      headRow.appendChild(cell('th', root.dataset.msgPrice, 'col'));
      head.appendChild(headRow);
      el.appendChild(head);

      const bodyEl = document.createElement('tbody');
      data.rows.forEach((row) => {
        const tr = document.createElement('tr');
        // `10+` rather than `10-49`: the server sends only the quantity at which
        // each price STARTS, because that is the only thing it can state without
        // inventing the top of a band. The last rung has no top at all.
        tr.appendChild(cell('th', `${row.minQty}+`, 'row'));
        tr.appendChild(cell('td', money(row.unitPrice, data.currency)));
        bodyEl.appendChild(tr);
      });
      el.appendChild(bodyEl);
      return el;
    }

    function render(data) {
      // `anonymous` here means "logged out AND this merchant has not published a
      // public ladder". There is nothing useful to say about a ladder that does
      // not exist for this viewer, and a login prompt would promise one — so the
      // block hides, exactly as it does for `unpriced`.
      if (data.status !== 'priced') {
        if (designMode) {
          show(line(root.dataset.msgEmpty, 'tackquote-tiers__error'));
          return;
        }
        root.hidden = true;
        return;
      }

      const wrap = document.createElement('div');
      wrap.appendChild(table(data));
      // The server says whether these are this buyer's negotiated rates or the
      // tenant's list prices. Labelling one as the other is the failure the
      // whole wholesale surface exists to avoid.
      if (!data.accountSpecific) {
        wrap.appendChild(line(root.dataset.msgListNote, 'tackquote-tiers__note'));
      }
      show(wrap);
    }

    function refresh() {
      const variant = ns.findVariant(variants, ns.variantId(root));
      const sku = variant ? variant.sku : '';
      if (!sku) {
        standDown();
        return;
      }

      const cacheKey = `tiers:${customerMarker}:${proxy}:${sku}`;
      const cached = ns.cache.read(cacheKey);
      const token = ++inFlight;

      // Rule 3, first half: paint what we already know before asking anything.
      if (cached) render(cached);

      ns.fetchJson(`${proxy}/volume-tiers?sku=${encodeURIComponent(sku)}`)
        .then((data) => {
          // A slow reply for a variant the shopper has already navigated away
          // from must not overwrite a newer one.
          if (token !== inFlight) return;

          // Only outcomes that do not depend on session state are remembered.
          // `anonymous` and `unlinked` can both change without this page
          // reloading — caching them would keep hiding the ladder from someone
          // who has just signed in.
          if (data.status === 'priced' || data.status === 'unpriced') {
            ns.cache.write(cacheKey, data, CACHE_TTL_MS);
          }
          render(data);
        })
        .catch(() => {
          if (token !== inFlight) return;
          // Rule 3, second half. A stale ladder already on screen survives an
          // outage, and there is nothing better to replace it with.
          if (cached) return;
          standDown();
        });
    }

    // The ladder depends on the SKU but NOT on the quantity box — unlike the
    // price block, which re-asks on every quantity change. Listening to the form
    // anyway is what keeps it correct across a variant switch.
    ns.onChange(root, refresh);
    refresh();
  });
});
