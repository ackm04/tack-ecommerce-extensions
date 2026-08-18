<?php
/**
 * Handles the storefront "Request a Quote" form POST. Runs server-side so the
 * TackQuote API key never reaches the browser (mirrors the WordPress plugin's
 * admin-ajax handler, Tack_Widget::handle_request(), but as a controller
 * since Magento has no equivalent of WP's public admin-ajax.php by default).
 *
 * From the internet's point of view this is an UNAUTHENTICATED endpoint — the API key
 * authenticates the store, not the visitor driving the browser — so it is rate limited
 * (see SubmissionThrottle) in addition to CSRF validation.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Quote;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\IdempotencyGuard;
use TackQuote\Quotes\Model\ProductQuoteResolver;
use TackQuote\Quotes\Model\SubmissionThrottle;

class Submit extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Built-in company fields TackQuote accepts. Anything else submitted is ignored. */
    private const COMPANY_FIELDS = [
        'phone',
        'website',
        'taxRegistrationNumber',
        'addressLine1',
        'addressLine2',
        'city',
        'state',
        'postalCode',
        'country',
        'contactTitle',
    ];

    private const MAX_NOTE_LENGTH = 2000;
    private const MAX_QTY = 1000000;

    /** Matches MAX_ITEMS on the Tack side, so a list that submits here is never truncated there. */
    private const MAX_ITEMS = 50;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var ProductQuoteResolver
     */
    private $productResolver;

    /**
     * @var SubmissionThrottle
     */
    private $throttle;

    /**
     * @var IdempotencyGuard
     */
    private $idempotency;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * SKUs in this submission that need a variant/option selection before they can be
     * quoted. Populated by resolveLineItems().
     *
     * @var string[]
     */
    private $unresolvedSkus = [];

    /**
     * @param Context $context
     * @param Config $config
     * @param Client $client
     * @param ProductQuoteResolver $productResolver
     * @param SubmissionThrottle $throttle
     * @param IdempotencyGuard $idempotency
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param FormKey $formKey
     */
    public function __construct(
        Context $context,
        Config $config,
        Client $client,
        ProductQuoteResolver $productResolver,
        SubmissionThrottle $throttle,
        IdempotencyGuard $idempotency,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        FormKey $formKey
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->client = $client;
        $this->productResolver = $productResolver;
        $this->throttle = $throttle;
        $this->idempotency = $idempotency;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->formKey = $formKey;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        if (!$this->config->isConfigured()) {
            return $this->fail($result, 400, (string) __('TackQuote is not configured for this store.'));
        }

        // Before any work: this endpoint creates a Buyer and a Quote in the seller's
        // tenant on every accepted request.
        if ($this->throttle->isExceeded()) {
            return $this->fail(
                $result,
                429,
                (string) __('Too many quote requests. Please wait a minute and try again.')
            );
        }

        $request = $this->getRequest();

        $email = trim((string) $request->getParam('email', ''));
        $firstName = trim((string) $request->getParam('firstName', ''));
        $lastName = trim((string) $request->getParam('lastName', ''));
        $phone = trim((string) $request->getParam('phone', ''));
        $companyName = trim((string) $request->getParam('companyName', ''));
        $note = mb_substr(trim((string) $request->getParam('note', '')), 0, self::MAX_NOTE_LENGTH);
        $sku = trim((string) $request->getParam('sku', ''));
        $qty = (int) $request->getParam('qty', 1);

        // A signed-in customer should never have to retype what the store already knows.
        // Their session values win over anything posted, so the quote is attributed to
        // the authenticated identity rather than a typo or a substituted address.
        if ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $email = (string) $customer->getEmail();
            $firstName = $firstName !== '' ? $firstName : (string) $customer->getFirstname();
            $lastName = $lastName !== '' ? $lastName : (string) $customer->getLastname();
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->fail($result, 400, (string) __('A valid email address is required.'), 'email');
        }

        if ($firstName === '') {
            return $this->fail($result, 400, (string) __('Your first name is required.'), 'firstName');
        }

        // Two submission shapes: a multi-product quote list, or the single product whose
        // page the shopper is on. Mirrors the WooCommerce plugin, which offers both.
        $lineItems = $this->resolveLineItems($request, $sku, $qty);

        if ($lineItems === null) {
            return $this->fail($result, 400, (string) __('Enter a quantity of at least 1.'), 'qty');
        }

        if ($lineItems === []) {
            // Distinguish "nothing usable" from "you must choose options first" — they
            // need completely different actions from the shopper.
            if ($this->unresolvedSkus !== []) {
                return $this->fail(
                    $result,
                    400,
                    (string) __(
                        'Choose the available options for %1 on its product page before requesting a quote.',
                        implode(', ', array_unique($this->unresolvedSkus))
                    )
                );
            }

            return $this->fail($result, 400, (string) __('No products to quote.'));
        }

        // Some lines resolved and some did not: quote what we can, but say what was left
        // out rather than silently shipping a shorter quote than the buyer asked for.
        if ($this->unresolvedSkus !== []) {
            $note = trim(
                (string) __(
                    'Buyer could not specify options for: %1',
                    implode(', ', array_unique($this->unresolvedSkus))
                ) . "\n" . $note
            );
        }

        // Configurable selections come back as a display string per line; fold them into
        // the note so the seller sees which variants were requested.
        $optionNotes = [];
        foreach ($lineItems as $i => $line) {
            $options = (string) ($line['options'] ?? '');
            unset($lineItems[$i]['options']);
            if ($options !== '') {
                $optionNotes[] = $line['sku'] . ' — ' . $options;
            }
        }
        if ($optionNotes !== []) {
            $note = trim(implode("\n", $optionNotes) . "\n" . $note);
        }

        $payload = [
            'buyerEmail' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phone,
            'companyName' => $companyName,
            'note' => $note,
            'source' => 'magento',
            'currency' => $this->getCurrencyCode(),
            'lineItems' => array_values($lineItems),
        ];

        $company = $this->collectCompanyDetails($request);
        if ($company !== []) {
            $payload['company'] = $company;
        }

        $customFields = $request->getParam('customFields', []);
        if (is_array($customFields) && $customFields !== []) {
            $payload['customFields'] = $customFields;
        }

        // Collapse a double-click or a retry into the original quote instead of creating
        // a second one.
        $idempotencyKey = $this->buildIdempotencyKey($email, $lineItems);
        $replayed = $this->idempotency->get($idempotencyKey);
        if ($replayed !== null) {
            return $result->setData($replayed);
        }

        $response = $this->client->createQuoteRequest($payload, null, $idempotencyKey);

        if (!$response['ok']) {
            $this->logger->warning('TackQuote quote-request failed: ' . ($response['message'] ?? 'unknown error'));

            return $this->fail(
                $result,
                502,
                (string) ($response['message'] ?? __('Could not create the quote. Please try again.'))
            );
        }

        $payloadOut = [
            'success' => true,
            'quoteId' => $response['id'] ?? null,
            'quoteNumber' => $response['quoteNumber'] ?? null,
            'portalUrl' => $response['portalUrl'] ?? null,
            'company' => $response['company'] ?? null,
            // The storefront must not render a plain success when the company still needs
            // a human to approve it — the buyer would wait for access never granted.
            'awaitingApproval' => !empty($response['awaitingApproval']),
        ];

        // Only successes are remembered; a failure must stay retryable.
        $this->idempotency->remember($idempotencyKey, $payloadOut);

        return $result->setData($payloadOut);
    }

    /**
     * Company details from the submitted form, allow-listed.
     *
     * Only the fields TackQuote actually accepts, so a crafted form cannot smuggle
     * arbitrary keys into the company record.
     *
     * @param RequestInterface $request
     * @return array<string, string>
     */
    private function collectCompanyDetails(RequestInterface $request): array
    {
        $submitted = $request->getParam('company', []);
        if (!is_array($submitted)) {
            return [];
        }

        $company = [];
        foreach (self::COMPANY_FIELDS as $field) {
            if (!isset($submitted[$field])) {
                continue;
            }
            $value = trim((string) $submitted[$field]);
            if ($value !== '') {
                $company[$field] = $value;
            }
        }

        return $company;
    }

    /**
     * Build the quote's line items, re-resolving every product server-side.
     *
     * SECURITY — the browser sends only a SKU and a quantity. Name and, crucially, price
     * are always looked up here. A quote list lives in localStorage where the shopper can
     * edit it freely, so trusting a posted price would let anyone quote themselves any
     * amount. This mirrors the WooCommerce plugin, which re-resolves from the product for
     * the same reason.
     *
     * @param RequestInterface $request
     * @param string $sku Single-product fallback: the SKU of the page being viewed.
     * @param int $qty
     * @return array<int, array<string, mixed>>|null Null when a quantity is invalid.
     */
    private function resolveLineItems(RequestInterface $request, string $sku, int $qty): ?array
    {
        $this->unresolvedSkus = [];
        $submitted = $this->parseItems($request->getParam('items'));

        // No list posted: quote just the product whose page this came from.
        if ($submitted === []) {
            if ($sku === '') {
                return [];
            }
            if ($qty < 1 || $qty > self::MAX_QTY) {
                return null;
            }
            $superAttribute = $request->getParam('super_attribute', []);
            $line = $this->productResolver->resolve(
                $sku,
                $qty,
                is_array($superAttribute) ? $superAttribute : []
            );

            return $this->collectLine($line, $sku) ? [$line] : [];
        }

        $lines = [];
        foreach ($submitted as $row) {
            $rowSku = trim((string) ($row['sku'] ?? ''));
            $rowQty = (int) ($row['qty'] ?? 1);

            if ($rowSku === '') {
                continue;
            }
            if ($rowQty < 1 || $rowQty > self::MAX_QTY) {
                return null;
            }

            /*
             * Per-row selections. Without these a configurable added to the quote list
             * resolved to its PARENT sku, priced at the cheapest variant — an
             * unfulfillable SKU at a plausible-but-wrong price. The single-product path
             * always passed them; the list path did not.
             */
            $rowOptions = $row['superAttribute'] ?? [];
            $line = $this->productResolver->resolve(
                $rowSku,
                $rowQty,
                is_array($rowOptions) ? $rowOptions : []
            );

            if ($this->collectLine($line, $rowSku)) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * Decide what to do with one resolver result.
     *
     * A null line is skipped rather than failing the whole request: a quote list can
     * outlive a product being disabled, delisted or moved to another website, and losing
     * an entire multi-product quote over one stale row is worse than quoting the rest.
     *
     * A line needing a selection is NOT skipped silently — the buyer explicitly chose that
     * product and must be told why it cannot be quoted, so the SKU is recorded and
     * reported.
     *
     * @param array|null $line
     * @param string $sku
     * @return bool True when the line is usable.
     */
    private function collectLine(?array $line, string $sku): bool
    {
        if ($line === null) {
            return false;
        }

        if (!empty($line['unresolvedSelection'])) {
            $this->unresolvedSkus[] = $line['sku'] ?? $sku;

            return false;
        }

        return true;
    }

    /**
     * Decode and bound the submitted quote list.
     *
     * @param mixed $raw JSON array of {sku, qty}, as held in the browser's quote list.
     * @return array<int, array<string, mixed>>
     */
    private function parseItems($raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        // Bound the payload before doing any per-row work.
        return array_slice(array_filter($decoded, 'is_array'), 0, self::MAX_ITEMS);
    }

    /**
     * A key identifying this submission for duplicate suppression.
     *
     * Stable for the same buyer and the same set of lines within a short window, so a
     * double-click or a browser retry collapses into one quote rather than two.
     *
     * @param string $email
     * @param array $lineItems Resolved line items, each with sku and quantity.
     * @return string
     */
    private function buildIdempotencyKey(string $email, array $lineItems): string
    {
        $signature = [];
        foreach ($lineItems as $line) {
            $signature[] = ($line['sku'] ?? '') . ':' . ($line['quantity'] ?? 0);
        }
        // Sorted so the same basket in a different order is still the same request.
        sort($signature);

        return hash('sha256', implode('|', [
            'magento',
            strtolower($email),
            implode(',', $signature),
            // Bucket by minute: a deliberate second request later is a new quote, an
            // accidental resubmit seconds later is not.
            (string) floor(time() / 60),
        ]));
    }

    /**
     * The current store's currency code, defaulting to USD if it cannot be read.
     *
     * @return string
     */
    private function getCurrencyCode(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            // Empty, NOT 'USD'. Tack treats an unusable currency as "not supplied" and
            // falls back to the tenant's own configured currency; hardcoding USD here
            // would override that with a guess, which is the bug this whole change fixes.
            return '';
        }
    }

    /**
     * Build a failed JSON response.
     *
     * @param JsonResult $result
     * @param int $code
     * @param string $message
     * @param string $field Field the storefront should mark, when the error is field-level.
     * @return JsonResult
     */
    private function fail(JsonResult $result, int $code, string $message, string $field = ''): JsonResult
    {
        $data = ['success' => false, 'message' => $message];
        if ($field !== '') {
            $data['field'] = $field;
        }

        return $result->setHttpResponseCode($code)->setData($data);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException(
            $this->resultFactory->create(ResultFactory::TYPE_JSON)->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Invalid form key. Please reload the page and try again.'),
            ])
        );
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // Guest-accessible storefront form: validate the form_key param against
        // the current session's form key instead of Magento's default (which
        // expects an authenticated customer/admin session context).
        $submitted = (string) $request->getParam('form_key', '');

        return $submitted !== '' && $submitted === $this->formKey->getFormKey();
    }
}
