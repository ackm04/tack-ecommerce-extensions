<?php
/**
 * TackQuote for OpenCart — storefront "Request a Quote" module + AJAX
 * endpoint.
 *
 * Registered like any other OpenCart content module (Banner, HTML Content,
 * etc.): once installed, a merchant adds "TackQuote" to a layout position on
 * the Product page via Design > Layouts. `index()` then renders the button
 * for that product; `quote()` is the AJAX action the button's JS posts to.
 *
 * Mirrors TackQuotes::hookDisplayProductActions() +
 * controllers/front/quoterequest.php in the PrestaShop module
 * (integrations/prestashop/modules/tackquotes/) and Tack_Product_Button in
 * the WooCommerce plugin — same JSON contract, same TackQuote API endpoints.
 */

namespace Opencart\Catalog\Controller\Extension\Tack\Module;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\ApiClient;

class Tackquotes extends Controller
{
    /**
     * Renders the "Request a Quote" button + modal for the product currently
     * being viewed. Returns '' (renders nothing) outside of a product page,
     * when disabled, or when not yet configured with an API key.
     *
     * @param array $setting Per-instance layout settings (unused beyond
     *                        status; TackQuote's connection settings are
     *                        store-wide, see admin controller).
     */
    public function index(array $setting = []): string
    {
        if (!$this->config->get('module_tackquotes_status')) {
            return '';
        }
        if (!$this->config->get('module_tackquotes_api_key')) {
            // Not configured yet — don't show a button that can't work.
            return '';
        }

        $productId = (int) ($this->request->get['product_id'] ?? 0);
        if (!$productId) {
            return '';
        }

        $this->load->language('extension/tack/module/tackquotes');
        $this->load->model('catalog/product');

        $product = $this->model_catalog_product->getProduct($productId);
        if (!$product) {
            return '';
        }

        $data['tackquote_product_id'] = $productId;
        $data['tackquote_button_label'] = $this->config->get('module_tackquotes_button_label')
            ?: $this->language->get('button_default_label');
        $data['tackquote_ajax_url'] = $this->url->link('extension/tack/module/tackquotes.quote', '', true);
        $data['text_email'] = $this->language->get('text_email');
        $data['text_quantity'] = $this->language->get('text_quantity');
        $data['text_note'] = $this->language->get('text_note');
        $data['button_send'] = $this->language->get('button_send');

        return $this->load->view('extension/tack/module/tackquotes', $data);
    }

    /**
     * AJAX action the storefront modal posts to. Route:
     * extension/tack/module/tackquotes.quote
     */
    public function quote(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $json = [];

        $email = (string) ($this->request->post['email'] ?? '');
        $note = (string) ($this->request->post['note'] ?? '');
        $productId = (int) ($this->request->post['product_id'] ?? 0);
        $quantity = max(1, (int) ($this->request->post['quantity'] ?? 1));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $json['error'] = $this->language->get('error_email');
        }

        $lineItems = [];
        if (!$json && $productId) {
            $this->load->model('catalog/product');
            $product = $this->model_catalog_product->getProduct($productId);

            if ($product) {
                $lineItems[] = [
                    'sku' => $product['model'] ?? '',
                    'name' => $product['name'] ?? ('Product ' . $productId),
                    'quantity' => $quantity,
                    'unitPrice' => (float) ($product['price'] ?? 0),
                    'externalProductId' => (string) $productId,
                ];
            }
        }

        if (!$json && !$lineItems) {
            $json['error'] = $this->language->get('error_product');
        }

        if (!$json) {
            $apiUrl = (string) $this->config->get('module_tackquotes_api_url');
            $apiKey = (string) $this->config->get('module_tackquotes_api_key');

            if (!$apiKey) {
                $json['error'] = $this->language->get('error_not_configured');
            } else {
                $payload = [
                    'buyerEmail' => $email,
                    'note' => $note,
                    'source' => 'opencart',
                    'lineItems' => $lineItems,
                ];

                // The prices in $lineItems are in the store's active currency, so the quote
                // has to say which one. Without this Tack fell back to a hardcoded 'USD',
                // so a store selling in EUR produced USD quotes with no error anywhere.
                //
                // Resolution order mirrors OpenCart's own Startup\Currency controller:
                // the session value is the active code (its checkout writes
                // `currency_code` straight from `$this->session->data['currency']`), and
                // `config_currency` is the store default when the session has none. Sent
                // only when it looks like ISO 4217 alpha-3, so a misconfigured store falls
                // back to the tenant's configured currency instead of receiving junk.
                $currency = (string) ($this->session->data['currency']
                    ?? $this->config->get('config_currency')
                    ?? '');
                $currency = strtoupper(trim($currency));

                if (preg_match('/^[A-Z]{3}$/', $currency)) {
                    $payload['currency'] = $currency;
                }

                $client = new ApiClient($apiUrl, $apiKey);
                $result = $client->createQuoteRequest($payload);

                if (is_string($result)) {
                    // OpenCartPluginController::quoteRequest() failed (bad
                    // key, network error, etc.) — surface the real message
                    // rather than pretending it worked.
                    $json['error'] = $result;
                } else {
                    $json['success'] = $this->language->get('text_success');
                    $json['quoteId'] = $result['id'] ?? null;
                    $json['portalUrl'] = $result['portalUrl'] ?? '';
                }
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        // No per-instance config table rows required; connection settings
        // live in oc_setting (see admin controller install()/uninstall()).
    }

    public function uninstall(): void
    {
        // Handled by the admin controller's uninstall(), which owns the
        // shared `module_tackquotes` setting group.
    }
}
