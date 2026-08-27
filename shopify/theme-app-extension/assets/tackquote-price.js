/*
 * The wholesale price block.
 *
 * This block READS, which is why it does not work like the button blocks. A
 * tenant id sitting in the DOM is caller-supplied, and a read keyed on one would
 * let anybody ask any tenant what it charges. So the request goes to the
 * MERCHANT's own domain at the app proxy path, and Shopify forwards it to
 * TackQuote with the shop and the logged-in customer id signed using the app
 * secret. The identity is asserted by Shopify, never by this file — and no
 * tenant id travels from this page at all.
 *
 * https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
 *
 * ---------------------------------------------------------------------------
 * The availability problem this file is built around
 * ---------------------------------------------------------------------------
 * Competing B2B apps resolve their price natively in Liquid; we resolve ours
 * over an App Proxy round trip. That is a deliberate trade — it is what lets one
 * TackQuote price book serve Shopify, WooCommerce and the seller portal
 * identically — but it puts OUR latency and OUR uptime on the merchant's product
 * page, and theirs has no such dependency.
 *
 * Three rules follow, and they are why this file is longer than the markup it
 * produces:
 *
 *   1. Every request has a deadline. A slow answer is a failed answer.
 *   2. A failure degrades to the theme's own public price — the block REMOVES
 *      itself rather than printing an error beside a product. The shopper is
 *      left with a working product page, which is the state they would have been
 *      in had the merchant never installed us.
 *   3. A cached answer beats a fresh failure. The cache renders first and
 *      revalidates behind it, so an outage costs a stale price for the rest of
 *      the session rather than a blank block.
 *
 * Rule 2 is suspended in the theme editor. A merchant placing the block needs to
 * see that it is failing; a shopper does not.
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  /**
   * How long a resolved price may be reused within one tab.
   *
   * B2B contract prices change on the order of weeks, so five minutes is
   * conservative. It is bounded by the tab, not the browser — see `ns.cache`.
   */
  const CACHE_TTL_MS = 5 * 60 * 1000;

  ns.boot('.tackquote-block[data-tackquote-mode="price"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-price-body]');
    if (!proxy || !body) return;

    const variants = ns.variants(root);
    const designMode = root.dataset.tackquoteDesign === 'true';
    // Not an identity — an identity would have to be signed. This only
    // partitions the cache, so that logging out in the same tab cannot surface
    // the price the previous session was entitled to see.
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

    /**
     * Rule 2. We could not get an answer, so we get out of the way.
     *
     * Hiding rather than emptying, because an empty block still occupies its
     * heading and its spacing, which reads as a broken widget rather than as an
     * absent one.
     */
    function standDown() {
      if (designMode) {
        show(line(root.dataset.msgError, 'tackquote-price__error'));
        return;
      }
      root.hidden = true;
    }

    function render(data) {
      if (data.status === 'anonymous') {
        const wrap = document.createElement('div');
        wrap.appendChild(line(root.dataset.msgAnonymous));
        const link = document.createElement('a');
        link.className = 'tackquote-price__login';
        link.href = root.dataset.tackquoteLoginUrl || '/account/login';
        link.textContent = root.dataset.msgLogin;
        wrap.appendChild(link);
        show(wrap);
        return;
      }

      if (data.status === 'unlinked') {
        show(line(root.dataset.msgUnlinked));
        return;
      }

      if (data.status === 'priced') {
        const box = document.createElement('div');
        box.appendChild(
          line(
            `${money(data.unitPrice, data.currency)} ${root.dataset.msgEach}`,
            'tackquote-price__amount',
          ),
        );
        // A catalogue list price is a real number but it is NOT this buyer's
        // negotiated rate, and the server says which it is. Labelling one as the
        // other is the failure this whole path exists to avoid.
        if (!data.accountSpecific) {
          box.appendChild(line(root.dataset.msgListNote, 'tackquote-price__note'));
        }
        show(box);
        return;
      }

      show(line(root.dataset.msgUnpriced));
    }

    function refresh() {
      const variant = ns.findVariant(variants, ns.variantId(root));
      const sku = variant ? variant.sku : '';
      if (!sku) {
        show(line(root.dataset.msgUnpriced));
        return;
      }

      const quantity = ns.quantity(root);
      const cacheKey = `price:${customerMarker}:${proxy}:${sku}:${quantity}`;
      const cached = ns.cache.read(cacheKey);
      const token = ++inFlight;

      // Rule 3, first half: paint what we already know before asking anything,
      // so a repeat view costs no visible wait at all.
      if (cached) render(cached);

      ns.fetchJson(
        `${proxy}/wholesale-price?sku=${encodeURIComponent(sku)}&quantity=${encodeURIComponent(String(quantity))}`,
      )
        .then((data) => {
          // A slow reply for a variant the shopper has already navigated away
          // from must not overwrite a newer one.
          if (token !== inFlight) return;

          // Only a resolved answer is worth remembering. `anonymous` and
          // `unlinked` depend on state that can change without this page
          // reloading, and caching them would keep telling someone to log in
          // after they just did.
          if (data.status === 'priced' || data.status === 'unpriced') {
            ns.cache.write(cacheKey, data, CACHE_TTL_MS);
          }
          render(data);
        })
        .catch(() => {
          if (token !== inFlight) return;
          // Rule 3, second half. A stale price already on screen survives an
          // outage, and there is nothing better to replace it with.
          if (cached) return;
          standDown();
        });
    }

    ns.onChange(root, refresh);
    refresh();
  });
});
