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
    /** A quote list is a shopper's basket, not a bulk import. */
    private const MAX_LINE_ITEMS = 50;

    /** Guards against a quantity that overflows DECIMAL(14,4) downstream. */
    private const MAX_QUANTITY = 999999;

    private const THROTTLE_WINDOW_SECONDS = 600;

    private const THROTTLE_MAX_SUBMISSIONS = 5;

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
            // One outbound path for both entry points: the single-product modal and the
            // quote list. They used to build the payload and read the currency separately,
            // which is how the currency fix had to be applied twice.
            $json = $this->send($email, $note, $lineItems);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * AJAX action for the multi-product quote list. Route:
     * extension/tack/module/tackquotes.quoteList
     *
     * Accepts `items[N][product_id]` + `items[N][quantity]` and NOTHING ELSE about the
     * products. Every price, name and SKU is re-read from the catalog here.
     *
     * That is the whole point of the split: the browser holds the list (so quoting never
     * touches the cart), but the browser is not trusted with money. An earlier design that
     * posted the displayed price would have let anyone set their own price with devtools —
     * the same defect class the WooCommerce and Magento modules avoid by re-resolving
     * server-side, and the same one the TackQuote widget endpoint was hardened against.
     */
    public function quoteList(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $json = [];

        $email = trim((string) ($this->request->post['email'] ?? ''));
        $note = (string) ($this->request->post['note'] ?? '');
        $firstName = trim((string) ($this->request->post['firstName'] ?? ''));
        $lastName = trim((string) ($this->request->post['lastName'] ?? ''));
        $company = trim((string) ($this->request->post['company'] ?? ''));
        $telephone = trim((string) ($this->request->post['telephone'] ?? ''));
        $items = $this->request->post['items'] ?? [];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $json['error'] = $this->language->get('error_email');
        }

        if (!$json && (!is_array($items) || !$items)) {
            $json['error'] = $this->language->get('error_empty_list');
        }

        // A list is a shopper's basket, not a bulk import: 50 lines is far past any real
        // quote and keeps a scripted loop from turning one request into a catalog dump.
        if (!$json && count($items) > self::MAX_LINE_ITEMS) {
            $json['error'] = $this->language->get('error_too_many');
        }

        if (!$json && !$this->withinRateLimit()) {
            $json['error'] = $this->language->get('error_throttled');
        }

        $lineItems = [];

        if (!$json) {
            $this->load->model('catalog/product');

            foreach ($items as $item) {
                $productId = (int) ($item['product_id'] ?? 0);
                $quantity = (int) ($item['quantity'] ?? 1);

                if ($productId < 1) {
                    continue;
                }

                // getProduct() applies the store's own visibility rules (status,
                // date_available, store assignment), so a disabled or out-of-store product
                // cannot be quoted by guessing its id.
                $product = $this->model_catalog_product->getProduct($productId);

                if (!$product) {
                    continue;
                }

                $lineItems[] = [
                    'sku' => (string) ($product['model'] ?? ''),
                    'name' => (string) ($product['name'] ?? ('Product ' . $productId)),
                    'quantity' => max(1, min(self::MAX_QUANTITY, $quantity)),
                    'unitPrice' => (float) ($product['price'] ?? 0),
                    'externalProductId' => (string) $productId,
                ];
            }

            if (!$lineItems) {
                $json['error'] = $this->language->get('error_product');
            }
        }

        if (!$json) {
            $noteParts = [];

            // Name, company and phone have no first-class field on the plugin quote-request
            // contract, so they are carried in the note rather than dropped. Losing a
            // buyer's company name silently would be worse than a slightly verbose note.
            if ($firstName !== '' || $lastName !== '') {
                $noteParts[] = trim($firstName . ' ' . $lastName);
            }
            if ($company !== '') {
                $noteParts[] = $company;
            }
            if ($telephone !== '') {
                $noteParts[] = $telephone;
            }
            if ($note !== '') {
                $noteParts[] = $note;
            }

            $json = $this->send($email, implode(' | ', $noteParts), $lineItems);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    /**
     * Shared submit path for both the single-product modal and the quote list.
     *
     * @param array<int, array<string, mixed>> $lineItems
     * @return array<string, mixed> JSON response body
     */
    private function send(string $email, string $note, array $lineItems): array
    {
        $apiUrl = (string) $this->config->get('module_tackquotes_api_url');
        $apiKey = (string) $this->config->get('module_tackquotes_api_key');

        if ($apiKey === '') {
            return ['error' => $this->language->get('error_not_configured')];
        }

        $payload = [
            'buyerEmail' => $email,
            'note' => $note,
            'source' => 'opencart',
            'lineItems' => $lineItems,
        ];

        $currency = $this->activeCurrency();
        if ($currency !== '') {
            $payload['currency'] = $currency;
        }

        $client = new ApiClient($apiUrl, $apiKey);
        $result = $client->createQuoteRequest($payload);

        if (is_string($result)) {
            // The API call failed (bad key, network error, validation). Surface the real
            // message rather than pretending it worked.
            return ['error' => $result];
        }

        return [
            'success' => $this->language->get('text_success'),
            'quoteId' => $result['id'] ?? null,
            'quoteNumber' => $result['quoteNumber'] ?? '',
            'portalUrl' => $result['portalUrl'] ?? '',
        ];
    }

    /**
     * The prices in every payload are in the store's active currency, so the quote has to
     * say which one. Resolution order mirrors OpenCart's own Startup\Currency controller:
     * the session value is the active code, and `config_currency` is the store default when
     * the session has none. Sent only when it looks like ISO 4217 alpha-3, so a
     * misconfigured store falls back to the tenant's configured currency in TackQuote
     * instead of receiving junk.
     */
    private function activeCurrency(): string
    {
        $currency = strtoupper(trim((string) ($this->session->data['currency']
            ?? $this->config->get('config_currency')
            ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) ? $currency : '';
    }

    /**
     * Per-session submission throttle, mirroring the Magento module's SubmissionThrottle.
     *
     * Session-scoped rather than IP-scoped on purpose: OpenCart sits behind proxies and CDNs
     * often enough that `REMOTE_ADDR` is a shared address, and throttling by it would lock
     * out every shopper on one office network the moment a single buyer submitted twice.
     * This is abuse dampening, not access control — the API key and TackQuote's own limits
     * are the real boundary.
     */
    private function withinRateLimit(): bool
    {
        $now = time();
        $window = $this->session->data['tackquote_submissions'] ?? [];

        if (!is_array($window)) {
            $window = [];
        }

        $window = array_values(array_filter($window, static function ($stamp) use ($now) {
            return is_int($stamp) && ($now - $stamp) < self::THROTTLE_WINDOW_SECONDS;
        }));

        if (count($window) >= self::THROTTLE_MAX_SUBMISSIONS) {
            $this->session->data['tackquote_submissions'] = $window;
            return false;
        }

        $window[] = $now;
        $this->session->data['tackquote_submissions'] = $window;

        return true;
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
