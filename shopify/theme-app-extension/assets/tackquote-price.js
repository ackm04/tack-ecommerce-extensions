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
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  ns.boot('.tackquote-block[data-tackquote-mode="price"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-price-body]');
    if (!proxy || !body) return;

    const variants = ns.variants(root);
    let inFlight = 0;

    function show(node) {
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

    function refresh() {
      const variant = ns.findVariant(variants, ns.variantId(root));
      const sku = variant ? variant.sku : '';
      if (!sku) {
        show(line(root.dataset.msgUnpriced));
        return;
      }

      const token = ++inFlight;

      fetch(
        `${proxy}/wholesale-price?sku=${encodeURIComponent(sku)}&quantity=${encodeURIComponent(String(ns.quantity(root)))}`,
        { headers: { Accept: 'application/json' }, credentials: 'same-origin' },
      )
        .then((r) => {
          if (!r.ok) throw new Error(`HTTP ${r.status}`);
          return r.json();
        })
        .then((data) => {
          // A slow reply for a variant the shopper has already navigated away
          // from must not overwrite a newer one.
          if (token !== inFlight) return;

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
            const amount = line(
              `${money(data.unitPrice, data.currency)} ${root.dataset.msgEach}`,
              'tackquote-price__amount',
            );
            box.appendChild(amount);
            // A catalogue list price is a real number but it is NOT this buyer's
            // negotiated rate, and the server says which it is. Labelling one as
            // the other is the failure this whole path exists to avoid.
            if (!data.accountSpecific) {
              box.appendChild(line(root.dataset.msgListNote, 'tackquote-price__note'));
            }
            show(box);
            return;
          }

          show(line(root.dataset.msgUnpriced));
        })
        .catch(() => {
          if (token !== inFlight) return;
          show(line(root.dataset.msgError));
        });
    }

    ns.onChange(root, refresh);
    refresh();
  });
});
