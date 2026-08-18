<?php

/**
 * Front controller: handles the "Request a Quote" AJAX submission from the
 * product page and forwards it to the Tack API as a quote request.
 *
 * URL: index.php?fc=module&module=tackquotes&controller=quoterequest
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class TackQuotesQuoteRequestModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    /**
     * Handle the POST body and call the Tack API.
     */
    public function postProcess()
    {
        $this->ajaxRender(); // ensures ajax response mode even if ajax param is missing
        header('Content-Type: application/json');

        if (Tools::getValue('ajax') === false && !Tools::isSubmit('email')) {
            $this->jsonError($this->module->getTranslator()->trans('Invalid request.', [], 'Modules.Tackquotes.Shop'), 400);

            return;
        }

        $email = Tools::getValue('email');
        $note = Tools::getValue('note');
        $productId = (int) Tools::getValue('product_id');
        $quantity = max(1, (int) Tools::getValue('quantity', 1));

        if (!Validate::isEmail($email)) {
            $this->jsonError($this->module->getTranslator()->trans('A valid email address is required.', [], 'Modules.Tackquotes.Shop'), 400);

            return;
        }

        $lineItems = $this->buildLineItems($productId, $quantity);
        if (empty($lineItems)) {
            $this->jsonError($this->module->getTranslator()->trans('No product to quote.', [], 'Modules.Tackquotes.Shop'), 400);

            return;
        }

        $apiUrl = Configuration::get('TACKQUOTES_API_URL');
        $apiKey = Configuration::get('TACKQUOTES_API_KEY');

        if (!$apiKey) {
            $this->jsonError($this->module->getTranslator()->trans('TackQuote is not configured on this store yet.', [], 'Modules.Tackquotes.Shop'), 503);

            return;
        }

        $payload = [
            'buyerEmail' => $email,
            'note' => $note,
            'source' => 'prestashop',
            'lineItems' => $lineItems,
        ];

        // The prices in $lineItems are in the shop's active currency, so the quote has to
        // be denominated in it. Without this Tack fell back to a hardcoded 'USD' and a EUR
        // shop produced USD quotes silently.
        //
        // Per PrestaShop's Context docs, a Currency object is ALWAYS available on the
        // context from inside a controller ("set with the customer currency or the shop's
        // default currency"), and `$this->context` is the documented shortcut here. Sent
        // only when it looks like ISO 4217 alpha-3, so a misconfigured shop falls back to
        // the tenant's configured currency rather than pushing junk.
        if (isset($this->context->currency)) {
            $currency = Tools::strtoupper(trim((string) $this->context->currency->iso_code));

            if (preg_match('/^[A-Z]{3}$/', $currency)) {
                $payload['currency'] = $currency;
            }
        }

        $client = new TackApiClient($apiUrl, $apiKey);
        $result = $client->createQuoteRequest($payload);

        if (is_string($result)) {
            // See TackApiClient::createQuoteRequest() — this fails with a 404
            // today because the Tack API has no /integrations/prestashop/quote-requests
            // route yet. Surface the real error rather than pretending it worked.
            $this->jsonError($result, 502);

            return;
        }

        $this->jsonSuccess([
            'quoteId' => isset($result['id']) ? $result['id'] : null,
            'portalUrl' => isset($result['portalUrl']) ? $result['portalUrl'] : (isset($result['quoteUrl']) ? $result['quoteUrl'] : ''),
        ]);
    }

    /**
     * Build a single-product line item from the current product + quantity.
     *
     * @param int $productId
     * @param int $quantity
     *
     * @return array
     */
    protected function buildLineItems($productId, $quantity)
    {
        if (!$productId || !Validate::isUnsignedId($productId)) {
            return [];
        }

        $product = new Product($productId, true, $this->context->language->id);
        if (!Validate::isLoadedObject($product)) {
            return [];
        }

        return [
            [
                'sku' => $product->reference,
                'name' => $product->name,
                'quantity' => $quantity,
                'unitPrice' => (float) Product::getPriceStatic($productId, false),
                'externalProductId' => (string) $productId,
            ],
        ];
    }

    /**
     * @param string $message
     * @param int $httpCode
     */
    protected function jsonError($message, $httpCode = 400)
    {
        http_response_code($httpCode);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }

    /**
     * @param array $data
     */
    protected function jsonSuccess($data)
    {
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }
}
