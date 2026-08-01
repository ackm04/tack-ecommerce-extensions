<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use TackQuote\TackQuote\Service\TackQuoteApiClient;

/**
 * Handles the storefront "Request a Quote" button (see
 * Resources/views/storefront/page/product-detail/index.html.twig). Proxies
 * the submission server-side to TackQuote's public widget quote-request
 * endpoint rather than calling it directly from the browser, so the tenant
 * slug / API URL config lives only in Shopware's system config.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class QuoteRequestController extends StorefrontController
{
    public function __construct(private readonly TackQuoteApiClient $apiClient)
    {
    }

    #[Route(
        path: '/tackquote/quote-request',
        name: 'frontend.tackquote.quote-request',
        defaults: ['XmlHttpRequest' => true, 'csrf_protected' => true],
        methods: ['POST']
    )]
    public function requestQuote(Request $request, SalesChannelContext $context): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        $firstName = trim((string) $request->request->get('firstName', ''));

        if ($email === '' || $firstName === '') {
            return new JsonResponse([
                'success' => false,
                'message' => 'First name and email are required.',
            ], 422);
        }

        $buyer = [
            'firstName' => $firstName,
            'lastName' => trim((string) $request->request->get('lastName', '')),
            'email' => $email,
            'company' => trim((string) $request->request->get('company', '')),
            'phone' => trim((string) $request->request->get('phone', '')),
        ];

        $quantity = max(1, (int) $request->request->get('quantity', 1));

        $items = [[
            'name' => (string) $request->request->get('productName', 'Product'),
            'sku' => (string) $request->request->get('productNumber', ''),
            'quantity' => $quantity,
            'unitPrice' => (float) $request->request->get('unitPrice', 0),
            'productUrl' => (string) $request->request->get('productUrl', ''),
        ]];

        $message = trim((string) $request->request->get('message', ''));

        try {
            $result = $this->apiClient->submitQuoteRequest($buyer, $items, $message, $context->getSalesChannelId());

            return new JsonResponse([
                'success' => true,
                'quoteNumber' => $result['quoteNumber'] ?? null,
            ]);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], 502);
        }
    }
}
