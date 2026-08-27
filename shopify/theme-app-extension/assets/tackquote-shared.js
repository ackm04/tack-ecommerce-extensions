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

  /** Reject anything that is not an absolute https URL, so a mistyped setting cannot become an exfiltration target. */
  ns.safeApiBase = (raw) => {
    try {
      const url = new URL(raw);
      return url.protocol === 'https:' ? url.origin + url.pathname.replace(/\/+$/, '') : null;
    } catch (_err) {
      return null;
    }
  };

  /** The proxy path must stay same-origin and relative, so it cannot be pointed off-store. */
  ns.safeProxyPath = (raw) => {
    if (!raw || raw.charAt(0) !== '/' || raw.indexOf('//') === 0) return null;
    return raw.replace(/\/+$/, '');
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
