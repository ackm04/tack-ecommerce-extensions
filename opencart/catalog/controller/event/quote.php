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
        if (!$this->isActive() || !$this->config->get('module_tackquotes_inline_button')) {
            return;
        }

        $productId = (int) ($data['product_id'] ?? ($this->request->get['product_id'] ?? 0));
        if (!$productId) {
            return;
        }

        // The anchor: core's Add to Cart button. Everything after it is theme territory.
        $anchor = strpos($output, 'id="button-cart"');
        if ($anchor === false) {
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

        $this->load->language('extension/tack/module/tackquotes');
        $this->load->model('catalog/product');

        $product = $this->model_catalog_product->getProduct($productId);
        if (!$product) {
            return;
        }

        $controls = $this->load->view('extension/tack/quote/controls', [
            'tackquote_product_id' => $productId,
            'tackquote_sku' => (string) ($product['model'] ?? ''),
            'tackquote_name' => (string) ($product['name'] ?? ''),
            'tackquote_add_label' => $this->addToQuoteLabel(),
            'tackquote_request_label' => $this->requestQuoteLabel(),
            'tackquote_show_add' => (bool) $this->config->get('module_tackquotes_quote_list'),
        ]);

        $output = substr($output, 0, $insertAt) . $controls . substr($output, $insertAt);
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
