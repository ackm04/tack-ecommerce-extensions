<?php
/**
 * Handles the storefront "Request a Quote" form POST. Runs server-side so the
 * TackQuote API key never reaches the browser (mirrors the WordPress plugin's
 * admin-ajax handler, Tack_Widget::handle_request(), but as a controller
 * since Magento has no equivalent of WP's public admin-ajax.php by default).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Quote;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Data\Form\FormKey;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;

class Submit extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @var ProductRepositoryInterface
     */
    private $productRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @param Context $context
     * @param Config $config
     * @param Client $client
     * @param ProductRepositoryInterface $productRepository
     * @param LoggerInterface $logger
     * @param FormKey $formKey
     */
    public function __construct(
        Context $context,
        Config $config,
        Client $client,
        ProductRepositoryInterface $productRepository,
        LoggerInterface $logger,
        FormKey $formKey
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->client = $client;
        $this->productRepository = $productRepository;
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
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('TackQuote is not configured for this store.'),
            ]);
        }

        $request = $this->getRequest();
        $email = trim((string) $request->getParam('email', ''));
        $note = trim((string) $request->getParam('note', ''));
        $sku = trim((string) $request->getParam('sku', ''));
        $qty = max(1, (int) $request->getParam('qty', 1));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('A valid email address is required.'),
            ]);
        }

        if ($sku === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('No product to quote.'),
            ]);
        }

        try {
            $product = $this->productRepository->get($sku);
        } catch (NoSuchEntityException $e) {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('That product could not be found.'),
            ]);
        }

        $lineItem = [
            'sku' => $product->getSku(),
            'name' => $product->getName(),
            'quantity' => $qty,
            'unitPrice' => (float) $product->getPrice(),
            'externalProductId' => (string) $product->getId(),
        ];

        $response = $this->client->createQuoteRequest([
            'buyerEmail' => $email,
            'note' => $note,
            'source' => 'magento',
            'lineItems' => [$lineItem],
        ]);

        if (!$response['ok']) {
            $this->logger->warning('TackQuote quote-request failed: ' . ($response['message'] ?? 'unknown error'));

            return $result->setHttpResponseCode(502)->setData([
                'success' => false,
                'message' => $response['message'] ?? __('Could not create the quote. Please try again.'),
            ]);
        }

        return $result->setData([
            'success' => true,
            'quoteId' => $response['id'] ?? null,
            'quoteNumber' => $response['quoteNumber'] ?? null,
            'portalUrl' => $response['portalUrl'] ?? null,
        ]);
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
