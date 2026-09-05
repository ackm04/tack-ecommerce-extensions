/*
 * Shared selection helpers for the TackQuote blocks.
 *
 * Loaded from Liquid via `asset_url`, because a block's `javascript` schema key
 * allows exactly one file and each block already spends it on its own runtime.
 * Shopify supports both routes.
 *
 * ---------------------------------------------------------------------------
 * Why the runtimes queue instead of calling into this directly
 * ---------------------------------------------------------------------------
 * Schema-declared JavaScript is injected as `<script async>`, so nothing
 * guarantees this file executes before a block runtime that depends on it — and
 * an ordering bug here would surface as a block that works on a fast connection
 * and silently does nothing on a slow one. So each runtime PUSHES its
 * initialiser onto `window.TackQuoteQ` and this file drains the queue, replacing
 * `push` so later arrivals run immediately. Load order stops mattering in both
 * directions.
 *
 * Design notes for everything else in here live in the extension README.
 */
(() => {
  // A merchant can place several blocks on one page, each emitting this tag.
  if (window.TackQuote) return;

  const ns = {};
  window.TackQuote = ns;

  // `safeApiBase` was removed with the last two blocks that took a merchant-typed
  // API URL (GH-423). Every block now reaches TackQuote through the App Proxy on
  // the merchant's own domain, so no block needs an absolute URL and none should
  // grow one back: an unused validator for a caller-supplied API base is an
  // invitation to re-add the pattern it was guarding.

  /** The proxy path must stay same-origin and relative, so it cannot be pointed off-store. */
  ns.safeProxyPath = (raw) => {
    if (!raw || raw.charAt(0) !== '/' || raw.indexOf('//') === 0) return null;
    return raw.replace(/\/+$/, '');
  };

  /**
   * A fetch with a hard deadline.
   *
   * Without one, a slow TackQuote leaves "Checking your price" on a merchant's
   * product page for as long as the browser is willing to wait. The whole
   * availability argument for this architecture is that our latency must not
   * become the storefront's latency, and a request with no ceiling is exactly
   * that coupling.
   *
   * AbortController is available in every browser Shopify's OS 2.0 themes
   * support, but the guard costs nothing and a missing one would turn a
   * degraded price into a broken page.
   */
  ns.fetchJson = (url, options) => {
    const opts = options || {};
    const timeoutMs = opts.timeoutMs || 2500;
    const controller = typeof AbortController === 'function' ? new AbortController() : null;
    const timer = controller ? setTimeout(() => controller.abort(), timeoutMs) : null;

    return fetch(url, {
      method: opts.method || 'GET',
      headers: opts.headers || { Accept: 'application/json' },
      body: opts.body,
      credentials: 'same-origin',
      signal: controller ? controller.signal : undefined,
    })
      .then((res) => {
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return res.json();
      })
      .finally(() => {
        if (timer) clearTimeout(timer);
      });
  };

  /**
   * A tiny per-tab cache, so repeat views do not each cost a round trip.
   *
   * sessionStorage rather than localStorage on purpose: a B2B price belongs to
   * the session that was authenticated to see it, and a tab close should end it.
   * Every access is wrapped — a browser set to block site data THROWS on the
   * accessor itself rather than returning null, and a price block that explodes
   * on a privacy-hardened browser is worse than one that simply does not cache.
   *
   * Keys must carry the customer marker. Without it a shopper who logs out in
   * the same tab keeps reading the price they saw while signed in.
   */
  ns.cache = {
    read: (key) => {
      try {
        const raw = window.sessionStorage.getItem(`tackquote:${key}`);
        if (!raw) return null;
        const entry = JSON.parse(raw);
        if (!entry || typeof entry.expires !== 'number' || entry.expires < Date.now()) return null;
        return entry.value;
      } catch (_err) {
        return null;
      }
    },
    write: (key, value, ttlMs) => {
      try {
        window.sessionStorage.setItem(
          `tackquote:${key}`,
          JSON.stringify({ value, expires: Date.now() + ttlMs }),
        );
      } catch (_err) {
        // Quota, private mode, or blocked site data. Caching is an optimisation.
      }
    },
  };

  ns.variants = (root) => {
    const node = root.querySelector('.tackquote-variants');
    try {
      const parsed = JSON.parse(node.textContent);
      return Array.isArray(parsed) ? parsed : [];
    } catch (_err) {
      return [];
    }
  };

  /*
   * App blocks can see their parent section's `id` and nothing else, so there is
   * no supported way to ask the theme which form belongs to this product.
   * `form[action*="/cart/add"]` is the one selector every Online Store 2.0 theme
   * shares; widening the search keeps blocks working outside the product form.
   */
  ns.form = (root) => {
    const section = root.closest('.shopify-section') || document;
    return (
      root.closest('form[action*="/cart/add"]') ||
      section.querySelector('form[action*="/cart/add"]') ||
      document.querySelector('form[action*="/cart/add"]')
    );
  };

  ns.quantity = (root) => {
    const form = ns.form(root);
    const field = form?.querySelector('[name="quantity"]');
    const n = field ? Number.parseInt(field.value, 10) : 1;
    return Number.isFinite(n) && n > 0 ? n : 1;
  };

  /*
   * Priority: the `?variant=` search param (kept in sync by every theme, and
   * survives back/forward), then the product form's own `id` field, then whatever
   * Liquid rendered initially.
   */
  ns.variantId = (root) => {
    const fromUrl = new URLSearchParams(window.location.search).get('variant');
    if (fromUrl) return String(fromUrl);
    const form = ns.form(root);
    const field = form?.querySelector('[name="id"]');
    return String(field?.value || root.dataset.tackquoteVariant || '');
  };

  ns.findVariant = (list, id) => list.filter((v) => String(v.id) === String(id))[0] || null;

  /* No cross-theme variant-change event exists, so watch what is standard: the
   * product form, and history changes (variant selection pushes `?variant=`). */
  ns.onChange = (root, handler) => {
    const form = ns.form(root);
    if (form) {
      form.addEventListener('change', handler);
      form.addEventListener('input', handler);
    }
    window.addEventListener('popstate', handler);
  };

  ns.label = (root, variant) => {
    const title = root.dataset.tackquoteProduct;
    return variant?.title && variant.title !== 'Default Title'
      ? `${title} - ${variant.title}`
      : title;
  };

  /**
   * Fill `{name}` placeholders in a localized string.
   *
   * Localized copy has to keep its numbers inside the sentence — "Minimum order
   * 10 units" is one word order in English and another in most other
   * languages, so a block cannot concatenate the number onto a fragment and
   * stay translatable. The locale file owns the whole sentence and names its
   * holes; this fills them.
   *
   * Returns '' for a missing template rather than printing `undefined` beside a
   * product, and leaves an unknown placeholder untouched so a typo in a
   * translation is visible to whoever added it instead of silently blanking.
   */
  ns.format = (template, values) => {
    if (!template) return '';
    return String(template).replace(/\{(\w+)\}/g, (match, key) =>
      Object.prototype.hasOwnProperty.call(values || {}, key) ? String(values[key]) : match,
    );
  };

  /** Initialise `selector` blocks now and again whenever the theme editor reloads a section. */
  ns.boot = (selector, init) => {
    function run(scope) {
      (scope || document).querySelectorAll(selector).forEach((el) => {
        if (el.dataset.tackquoteReady !== 'true') {
          el.dataset.tackquoteReady = 'true';
          init(el);
        }
      });
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', () => {
        run();
      });
    } else {
      run();
    }
    document.addEventListener('shopify:section:load', (event) => {
      run(event.target);
    });
  };
  const queue = window.TackQuoteQ || [];
  queue.forEach((fn) => {
    fn(ns);
  });
  // Anything that loads after this runs straight away.
  window.TackQuoteQ = {
    push: (fn) => {
      fn(ns);
    },
  };
})();
