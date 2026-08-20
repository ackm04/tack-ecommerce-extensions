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
        $firstName = trim((string) Tools::getValue('firstName'));
        $lastName = trim((string) Tools::getValue('lastName'));
        $productId = (int) Tools::getValue('product_id');
        $quantity = max(1, (int) Tools::getValue('quantity', 1));

        if (!Validate::isEmail($email)) {
            $this->jsonError($this->module->getTranslator()->trans('A valid email address is required.', [], 'Modules.Tackquotes.Shop'), 400);

            return;
        }

        // Validate::isName is PrestaShop's own name validator — /^[^0-9!<>,;?=+()@#"°{}_$%:¤|]*$/u
        // per the validator table in PrestaShop's webservice reference. Deliberately NOT
        // isCustomerName, which PrestaShop uses for customers.firstname/lastname but which
        // only exists from 1.7.6; this module declares ps_versions_compliancy min 1.7.0.0,
        // so calling it would fatal on the oldest shop it claims to support.
        //
        // A blank value is not invalid — it is a shopper who declined to give a name, and
        // the empty string matches isName anyway. It is dropped from the payload below
        // rather than sent as '', because TackQuote treats a blank as "not supplied" and
        // leaves the column NULL. Never substituted with anything derived from the email.
        if ($firstName !== '' && !Validate::isName($firstName)) {
            $this->jsonError($this->module->getTranslator()->trans('Please check the first name.', [], 'Modules.Tackquotes.Shop'), 400);

            return;
        }

        if ($lastName !== '' && !Validate::isName($lastName)) {
            $this->jsonError($this->module->getTranslator()->trans('Please check the last name.', [], 'Modules.Tackquotes.Shop'), 400);

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

        // The whole reason these fields exist. Before them the endpoint knew nothing but
        // the email address and wrote its local part into buyers.first_name, so a request
        // from ps-probe@example.com produced a buyer named "ps-probe" that a seller could
        // not distinguish from a real name. Verified against the live API, not assumed:
        // quote TK-2026-001085 was created that way.
        //
        // Omitted when blank, never sent as ''. TackQuote's identity merge treats a value
        // as an observation about the buyer, so '' would be an assertion that their name
        // is the empty string and could overwrite one supplied on an earlier visit.
        // Passed through exactly as typed. Not truncated to fit `buyers.first_name`
        // (varchar(255)): silently storing half a surname is the same "quietly wrong data"
        // problem this change exists to remove, and the endpoint answers an over-long
        // value with a message naming the field and the limit.
        if ($firstName !== '') {
            $payload['firstName'] = $firstName;
        }

        if ($lastName !== '') {
            $payload['lastName'] = $lastName;
        }

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
            // Surface the real error rather than pretending it worked. This comment used
            // to claim the call "fails with a 404 today because the Tack API has no
            // /integrations/prestashop/quote-requests route yet"; that route exists
            // (PrestaShopPluginController) and answers 201, so the claim was stale.
            //
            // A 400 IS reachable here and is not a bug: the endpoint applies the seller's
            // TackQuote registration policy, so a shop whose tenant is set to
            // company_only, or requires a business email for company accounts, refuses a
            // submission that does not satisfy it. The message is written for the shopper.
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
