/*
 * The buyer-group badge.
 *
 * "You are on Tier 2 pricing", sourced from `buyer_groups` via
 * `buyer_group_members`. Same App Proxy identity story as the price and tier
 * blocks — the shop and the logged-in customer id are signed by Shopify, and
 * nothing about who is asking comes from this page.
 *
 * https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
 *
 * ---------------------------------------------------------------------------
 * Why the group name is a separate element instead of being interpolated
 * ---------------------------------------------------------------------------
 * The natural sentence is "You're on {group} pricing", and Shopify's locale
 * files do support that placeholder — shopify.dev's localization guide shows
 * single-brace interpolation (`"All orders must be placed by {date}"`) filled in
 * through the `t` filter.
 *
 * The catch is WHERE the value comes from. `t` interpolates in Liquid, on
 * Shopify's side, at render time — and the group name is not known then. It
 * arrives later, from the proxy, in this file. So the template would have to
 * reach the browser with its placeholder still in it, which means calling `t`
 * and NOT passing `group` and relying on the filter to leave `{group}` alone.
 * shopify.dev does not document what the filter does with an unpassed variable,
 * and the two plausible behaviours — leave the token, or substitute empty —
 * differ by a shopper seeing a literal `{group}` on a product page.
 *
 * UNVERIFIED, therefore avoided rather than guessed at: the badge is a label
 * plus a name in two elements, which needs no interpolation, and as a bonus is
 * not hostage to English word order the way a split sentence would be.
 *
 * ---------------------------------------------------------------------------
 * A shopper in no group renders NOTHING
 * ---------------------------------------------------------------------------
 * The server distinguishes `none` (a real buyer who belongs to no group) from
 * `unlinked` and `anonymous`, and all four non-`grouped` outcomes hide the
 * block. An empty chip beside a product is worse than no chip: it reads as a
 * feature that is broken rather than one that does not apply.
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  /*
   * Deliberately NOT cached.
   *
   * Group membership is pure session state — it is exactly the thing
   * `tackquote-price.js` refuses to cache when it declines to remember
   * `anonymous` and `unlinked`. A merchant moving a buyer into a new tier, or a
   * shopper signing in, must not be answered from a stale chip for the rest of
   * the tab's life. The badge is one small request that already fails silently,
   * so there is nothing to protect it from.
   */
  ns.boot('.tackquote-block[data-tackquote-mode="badge"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-badge-body]');
    if (!proxy || !body) return;

    const designMode = root.dataset.tackquoteDesign === 'true';

    function hide() {
      if (designMode) {
        root.hidden = false;
        body.textContent = root.dataset.msgEmpty;
        return;
      }
      root.hidden = true;
    }

    ns.fetchJson(`${proxy}/buyer-group`)
      .then((data) => {
        if (data.status !== 'grouped' || !data.name) {
          hide();
          return;
        }

        const chip = document.createElement('span');
        chip.className = 'tackquote-badge__chip';

        const label = document.createElement('span');
        label.className = 'tackquote-badge__label';
        label.textContent = root.dataset.msgLabel;

        const name = document.createElement('span');
        name.className = 'tackquote-badge__name';
        // textContent, never innerHTML: the group name is seller-authored and
        // arrives as JSON. It is not hostile input, but it is not markup either.
        name.textContent = data.name;

        chip.appendChild(label);
        chip.appendChild(name);
        body.textContent = '';
        body.appendChild(chip);
        root.hidden = false;
      })
      .catch(hide);
  });
});
