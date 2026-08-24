<?php
/**
 * TackQuote storefront event handlers.
 *
 * WHY EVENTS AND NOT A LAYOUT MODULE.
 *
 * OpenCart renders module output only where Design > Layouts assigns it, and its layout
 * positions are page-level (column_left, content_top, content_bottom, column_right). There
 * is no position beside Add to Cart, so a module can only ever put the quote button above
 * or below the whole product page — in practice under the description, which is where this
 * extension used to land it. WooCommerce has `woocommerce_after_add_to_cart_button` and
 * Magento has `after="product.info.addtocart"`; OpenCart's equivalent is the view event.
 *
 * `system/engine/loader.php:184,192` triggers `view/<route>/before` and `view/<route>/after`
 * with `[&$route, &$data, &$output]` — the rendered HTML by reference. The startup
 * controller (`catalog/controller/startup/event.php`) strips the leading `catalog/` from the
 * DB trigger before registering, so the rows written by the admin controller read
 * `catalog/view/product/product/after`.
 *
 * Injection is anchored on `id="button-cart"`, which is core's own id for the Add to Cart
 * button (`catalog/view/template/product/product.twig:226` in 4.1.0.4 — unchanged since
 * 4.0). If a theme has renamed it, this handler injects NOTHING and leaves the page exactly
 * as the theme rendered it; the layout-module path still works as a fallback, and the
 * settings screen says so. Mangling a stranger's markup on a guess is worse than a button
 * in the wrong place.
 */

namespace Opencart\Catalog\Controller\Extension\Tack\Event;

use Opencart\System\Engine\Controller;

class Quote extends Controller
{
    /**
     * `catalog/view/product/product/after`
     *
     * Injects the Add to Quote / Request a Quote controls immediately after the Add to Cart
     * button on the product page.
     *
     * @param string $route
     * @param array  $data   The view data — carries product_id, but NOT the model, so the
     *                       SKU is re-read from the catalog rather than guessed.
     * @param string $output Rendered HTML, modified in place.
     */
    public function productPage(string &$route, array &$data, string &$output): void
    {
        if (!$this->isActive()) {
            return;
        }

        $quoteOnly = $this->quoteOnlyApplies();

        // The placement toggle is OVERRIDDEN by quote-only mode, and that is deliberate.
        // With the cart refused server-side, skipping this injection because a merchant
        // had "Show beside Add to Cart" switched off would leave the product page with a
        // dead Add to Cart button and no quote button at all — the exact "no way to
        // transact" failure this feature must never produce. So: inject whenever the mode
        // applies, regardless of the placement toggle.
        if (!$quoteOnly && !$this->config->get('module_tackquotes_inline_button')) {
            return;
        }

        $productId = (int) ($data['product_id'] ?? ($this->request->get['product_id'] ?? 0));
        if (!$productId) {
            return;
        }

        // The anchor: core's Add to Cart button. Everything after it is theme territory.
        $anchor = strpos($output, 'id="button-cart"');

        // A theme that renamed the button gives us nothing to anchor on. Normally that
        // means "inject nothing and leave the page alone" (see the class comment). In
        // quote-only mode that answer is not available: the server is already refusing
        // add-to-cart, so a page with no quote CTA is a dead end. The controls are appended
        // to the end of the product page output instead — adjacent rather than perfect,
        // which beats a shopper with no way to ask for a price.
        if ($anchor === false) {
            if (!$quoteOnly) {
                return;
            }

            $controls = $this->renderControls($productId, $quoteOnly);

            if ($controls !== '') {
                $output .= $controls;
            }

            return;
        }

        $insertAt = strpos($output, '</button>', $anchor);
        if ($insertAt === false) {
            return;
        }
        $insertAt += strlen('</button>');

        // Core wraps quantity + Add to Cart in `div.input-group`, a flex row
        // (`catalog/view/template/product/product.twig:223-226`). Inserting straight after
        // the button puts the quote controls INSIDE that row, where they are squeezed
        // alongside Add to Cart — which is what the first build of this did. Stepping past
        // the group's closing `</div>` puts them on their own full-width line directly
        // beneath it: the position WooCommerce gets from
        // woocommerce_after_add_to_cart_button and Magento from
        // after="product.info.addtocart".
        //
        // Bounded on purpose. If the next `</div>` is far away the theme is not laid out the
        // way core is, and the block goes immediately after the button instead — adjacent
        // rather than perfect, but never inserted into some unrelated section further down
        // the page.
        $groupEnd = strpos($output, '</div>', $insertAt);
        if ($groupEnd !== false && ($groupEnd - $insertAt) <= 200) {
            $insertAt = $groupEnd + strlen('</div>');
        }

        $controls = $this->renderControls($productId, $quoteOnly);
        if ($controls === '') {
            return;
        }

        $output = substr($output, 0, $insertAt) . $controls . substr($output, $insertAt);

        // ORDER MATTERS. The Add to Cart button is removed only AFTER the quote controls
        // are in the document, and the removal re-finds its own anchor in the new string.
        //
        // This is the WooCommerce lesson, made structural. There, the quote button hung off
        // a hook that only fires INSIDE the add-to-cart form, so "remove the cart button"
        // would have removed the quote button with it and left the store with nothing.
        // OpenCart has the same coupling in a different shape: `id="button-cart"` is the
        // only anchor this file has, so deleting it first would delete the very landmark
        // the injection needs. Inserting first and stripping second means the two cannot
        // half-happen: if the insertion did not take, the code returned above and the cart
        // button is still there and still refused server-side, which is degraded but alive.
        if ($quoteOnly) {
            $output = self::stripCartButton($output);
        }
    }

    /**
     * Renders the product-page quote controls, or '' if the product cannot be read.
     *
     * Split out of productPage() because quote-only mode has a second call site: the
     * append-to-the-end fallback used when a theme has renamed the Add to Cart button.
     */
    private function renderControls(int $productId, bool $quoteOnly): string
    {
        $this->load->language('extension/tack/module/tackquotes');
        $this->load->model('catalog/product');

        $product = $this->model_catalog_product->getProduct($productId);
        if (!$product) {
            return '';
        }

        return (string) $this->load->view('extension/tack/quote/controls', [
            'tackquote_product_id' => $productId,
            'tackquote_sku' => (string) ($product['model'] ?? ''),
            'tackquote_name' => (string) ($product['name'] ?? ''),
            'tackquote_add_label' => $this->addToQuoteLabel(),
            'tackquote_request_label' => $this->requestQuoteLabel(),
            'tackquote_show_add' => (bool) $this->config->get('module_tackquotes_quote_list'),
            // Promotes the request button from `secondary` to `primary`: with the cart gone
            // it is no longer the alternative action, it is THE action.
            'tackquote_quote_only' => $quoteOnly,
        ]);
    }

    /**
     * Removes core's Add to Cart button element from already-rendered product HTML.
     *
     * Bounded exactly like the injection above: it looks for core's own `id="button-cart"`,
     * walks back to that tag's `<button`, forward to the first `</button>`, and does nothing
     * at all if either end is missing. It never removes more than one element and never
     * touches a theme it does not recognise.
     *
     * A comment is left in place of the button rather than nothing, so a merchant reading
     * page source (or a support engineer reading a bug report) can see WHY the button is
     * absent instead of suspecting a broken theme.
     *
     * This is presentation. It is not the enforcement — that is
     * catalog/controller/quotemode.php, and it holds whether or not this succeeds.
     */
    private static function stripCartButton(string $output): string
    {
        $anchor = strpos($output, 'id="button-cart"');
        if ($anchor === false) {
            return $output;
        }

        $start = strrpos(substr($output, 0, $anchor), '<button');
        if ($start === false) {
            return $output;
        }

        $end = strpos($output, '</button>', $anchor);
        if ($end === false) {
            return $output;
        }

        return substr($output, 0, $start)
            . '<!-- TackQuote: quote-only mode is on for this visitor, Add to Cart removed -->'
            . substr($output, $end + strlen('</button>'));
    }

    /**
     * `catalog/view/common/footer/after`
     *
     * Injects the quote list widget, the multi-step form and the assets once per page.
     *
     * Site-wide rather than product-page-only for the same reason WooCommerce hangs its
     * drawer off `wp_footer` and Magento renders its list in `before.body.end`: a buyer
     * builds a list across category pages and search results, and a list that only exists
     * on product pages is stranded everywhere else.
     */
    public function footer(string &$route, array &$data, string &$output): void
    {
        if (!$this->isActive()) {
            return;
        }

        $this->load->language('extension/tack/module/tackquotes');

        // Prefill for a logged-in customer, exactly as Magento's form prefills from
        // customerData. Guests get empty fields; nothing is invented.
        $customer = [
            'email' => '',
            'firstName' => '',
            'lastName' => '',
            'telephone' => '',
        ];

        if ($this->customer->isLogged()) {
            $customer['email'] = (string) $this->customer->getEmail();
            $customer['firstName'] = (string) $this->customer->getFirstName();
            $customer['lastName'] = (string) $this->customer->getLastName();
            $customer['telephone'] = (string) $this->customer->getTelephone();
        }

        $output .= $this->load->view('extension/tack/quote/drawer', [
            // Read once here and handed to the JS, rather than re-derived in the browser.
            // The storefront script uses it for PRESENTATION ONLY — swapping a category
            // tile's cart button for an add-to-quote button. The refusal itself is
            // catalog/controller/quotemode.php and does not consult this value.
            'tackquote_quote_only' => $this->quoteOnlyApplies(),
            'tackquote_submit_url' => $this->url->link('extension/tack/module/tackquotes.quoteList', 'language=' . $this->config->get('config_language'), true),
            'tackquote_asset_base' => 'extension/tack/catalog/view/',
            'tackquote_customer' => $customer,
            'tackquote_listing_buttons' => (bool) $this->config->get('module_tackquotes_listing_button'),
            'tackquote_quote_list' => (bool) $this->config->get('module_tackquotes_quote_list'),
            'tackquote_add_label' => $this->addToQuoteLabel(),
            'tackquote_request_label' => $this->requestQuoteLabel(),
            'text_drawer_title' => $this->language->get('text_drawer_title'),
            'text_drawer_empty' => $this->language->get('text_drawer_empty'),
            'text_step_items' => $this->language->get('text_step_items'),
            'text_step_details' => $this->language->get('text_step_details'),
            'text_step_done' => $this->language->get('text_step_done'),
            'text_email' => $this->language->get('text_email'),
            'text_first_name' => $this->language->get('text_first_name'),
            'text_last_name' => $this->language->get('text_last_name'),
            'text_company' => $this->language->get('text_company'),
            'text_telephone' => $this->language->get('text_telephone'),
            'text_note' => $this->language->get('text_note'),
            'text_quantity' => $this->language->get('text_quantity'),
            'text_remove' => $this->language->get('text_remove'),
            'text_items_count' => $this->language->get('text_items_count'),
            'text_review_hint' => $this->language->get('text_review_hint'),
            'text_added' => $this->language->get('text_added'),
            'text_price_on_request' => $this->language->get('text_price_on_request'),
            'button_send' => $this->language->get('button_send'),
            'button_back' => $this->language->get('button_back'),
            'button_next' => $this->language->get('button_next'),
            'button_close' => $this->language->get('button_close'),
            'button_view_quote' => $this->language->get('button_view_quote'),
            'error_email' => $this->language->get('error_email'),
            'error_empty_list' => $this->language->get('error_empty_list'),
        ]);
    }

    /**
     * Both handlers stay silent unless the module is enabled AND configured. A button that
     * cannot reach TackQuote is worse than no button: the shopper fills in a form and the
     * request goes nowhere.
     */
    private function isActive(): bool
    {
        return (bool) $this->config->get('module_tackquotes_status')
            && (string) $this->config->get('module_tackquotes_api_key') !== '';
    }

    /**
     * Does quote-only mode apply to this visitor?
     *
     * DELEGATED, not re-implemented. The guard controller owns the answer, and if this file
     * derived it separately the two could disagree — with the worst disagreement being
     * "enforcement on, CTA off", i.e. a storefront nobody can transact with in either
     * direction. Constructing the controller is cheap: it holds no state, and its own
     * cheap-checks-first ordering means the admin-preview DB query only happens once
     * quote-only is otherwise switched on.
     */
    private function quoteOnlyApplies(): bool
    {
        return (new \Opencart\Catalog\Controller\Extension\Tack\Quotemode($this->registry))->applies();
    }

    private function addToQuoteLabel(): string
    {
        return (string) ($this->config->get('module_tackquotes_add_label')
            ?: $this->language->get('button_add_to_quote'));
    }

    private function requestQuoteLabel(): string
    {
        return (string) ($this->config->get('module_tackquotes_button_label')
            ?: $this->language->get('button_default_label'));
    }
}
