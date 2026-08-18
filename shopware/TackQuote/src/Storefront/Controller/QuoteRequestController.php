<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Storefront\Controller;

use Shopware\Core\Content\Product\SalesChannel\SalesChannelProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\Entity\SalesChannelRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TackQuote\TackQuote\Service\TackQuoteApiClient;

/**
 * Handles the storefront "Request a Quote" button (see
 * Resources/views/storefront/component/buy-widget/buy-widget.html.twig). Proxies
 * the submission server-side to TackQuote's public widget quote-request
 * endpoint rather than calling it directly from the browser, so the tenant
 * slug / API URL config lives only in Shopware's system config.
 *
 * ── The browser is not trusted for anything about the product ──────────────────
 *
 * This endpoint is unauthenticated (any storefront visitor can POST to it), and it
 * used to take the product's NAME, SKU, UNIT PRICE and URL straight from the request
 * body, because that is what the Twig template put in its data- attributes. Those
 * four values are exactly the ones that end up on a quote a salesperson then works
 * from, so anyone could curl this route and create a TackQuote quote for
 * "Enormous Copper Car" at 0.01, or with a productUrl pointing anywhere they liked.
 * Nothing downstream re-checked the price — the API stores what it is given.
 *
 * So the only product input accepted now is `productId`, and every commercial fact
 * is resolved server-side from that id through the sales-channel-scoped product
 * repository, which applies this channel's rules, tax and currency. An unknown or
 * unavailable id is rejected rather than quoted.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class QuoteRequestController extends StorefrontController
{
    public function __construct(
        private readonly TackQuoteApiClient $apiClient,
        private readonly SalesChannelRepository $productRepository,
    ) {
    }

    /**
     * Note: no 'csrf_protected' default. Shopware removed CSRF tokens in 6.5 (same-site
     * cookies replaced them) — nothing in core reads that flag any more, so declaring it
     * only implies a protection that is not there.
     */
    #[Route(
        path: '/tackquote/quote-request',
        name: 'frontend.tackquote.quote-request',
        defaults: ['XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function requestQuote(Request $request, SalesChannelContext $context): JsonResponse
    {
        $email = trim((string) $request->request->get('email', ''));
        $firstName = trim((string) $request->request->get('firstName', ''));

        if ($email === '' || $firstName === '') {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('tackQuote.message.requiredFields'),
            ], 422);
        }

        $product = $this->loadProduct((string) $request->request->get('productId', ''), $context);

        if ($product === null) {
            return new JsonResponse([
                'success' => false,
                'message' => $this->trans('tackQuote.message.productUnavailable'),
            ], 422);
        }

        $buyer = [
            'firstName' => $firstName,
            'lastName' => trim((string) $request->request->get('lastName', '')),
            'email' => $email,
            'company' => trim((string) $request->request->get('company', '')),
            'phone' => trim((string) $request->request->get('phone', '')),
        ];

        $quantity = $this->resolveQuantity((int) $request->request->get('quantity', 1), $product);

        $items = [[
            // All four resolved from $product, never from the request body.
            'name' => (string) ($product->getTranslation('name') ?? $product->getName() ?? ''),
            'sku' => (string) $product->getProductNumber(),
            'quantity' => $quantity,
            'unitPrice' => $this->resolveUnitPrice($product, $quantity),
            'productUrl' => $this->generateUrl(
                'frontend.detail.page',
                ['productId' => $product->getId()],
                UrlGeneratorInterface::ABSOLUTE_URL
            ),
        ]];

        $message = trim((string) $request->request->get('message', ''));

        try {
            // The prices in $items are this sales channel's, so the quote has to be
            // denominated in this sales channel's currency. TackQuote's widget endpoint
            // otherwise defaults to USD, which silently mis-denominates every EUR store.
            $result = $this->apiClient->submitQuoteRequest(
                $buyer,
                $items,
                $message,
                $context->getSalesChannelId(),
                $context->getCurrency()->getIsoCode(),
            );

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

    /**
     * Loads the product through the SALES-CHANNEL-scoped repository, which is what makes
     * this a real authorisation check and not just a lookup: that repository applies the
     * channel's visibility and availability rules, so a product not published to this
     * storefront simply is not found. A plain `product.repository` would happily return
     * it, letting a visitor quote something the store does not sell them.
     *
     * The 'prices' association is requested explicitly because ProductSubscriber only
     * calculates the advanced (quantity-tiered) prices when that association is loaded;
     * without it every tier collapses to the single-unit price.
     */
    private function loadProduct(string $productId, SalesChannelContext $context): ?SalesChannelProductEntity
    {
        // Not a defensive nicety: the DAL throws InconsistentCriteriaIdsException on a
        // malformed id, which would surface as a 500 on unvalidated public input.
        if (!preg_match('/^[0-9a-f]{32}$/i', $productId)) {
            return null;
        }

        $criteria = new Criteria([mb_strtolower($productId)]);
        $criteria->addAssociation('prices');

        $product = $this->productRepository->search($criteria, $context)->first();

        return $product instanceof SalesChannelProductEntity ? $product : null;
    }

    /**
     * Honours the product's own purchase constraints rather than accepting any integer.
     * These are per-product settings a merchant configures (a pallet-only item has
     * minPurchase 48, purchaseSteps 48), so quoting 1 of it is not a quote the store
     * could ever fulfil.
     */
    private function resolveQuantity(int $requested, SalesChannelProductEntity $product): int
    {
        $min = max(1, $product->getMinPurchase() ?? 1);
        $quantity = max($min, $requested);

        $max = $product->getMaxPurchase();
        if ($max !== null && $max > 0) {
            $quantity = min($quantity, max($min, $max));
        }

        // Round UP to the next valid step, measured from $min — rounding down could land
        // below the minimum, and the merchant's step is the unit they actually ship in.
        $steps = $product->getPurchaseSteps();
        if ($steps !== null && $steps > 1 && ($quantity - $min) % $steps !== 0) {
            $quantity = $min + (int) ceil(($quantity - $min) / $steps) * $steps;

            if ($max !== null && $max > 0 && $quantity > $max) {
                $quantity -= $steps;
            }
        }

        return max(1, $quantity);
    }

    /**
     * Picks the unit price for THIS quantity, which for a B2B quote is the whole point:
     * a store with a 100+ tier must quote the 100+ rate, not the single-unit rate.
     *
     * `calculatedPrices` is the advanced-pricing ladder. Core builds it in
     * ProductPriceCalculator::calculateAdvancePrices(), sorted ascending, stamping each
     * entry's `quantity` with the tier's `quantityEnd ?? quantityStart` — so the correct
     * tier is the first whose quantity covers the requested amount, and the final entry
     * (the open-ended top tier, stamped with its quantityStart) covers everything above.
     * Verified against vendor/shopware/core/.../Price/ProductPriceCalculator.php; core
     * ships no helper for this lookup.
     */
    private function resolveUnitPrice(SalesChannelProductEntity $product, int $quantity): float
    {
        $tiers = $product->getCalculatedPrices();

        if ($tiers->count() > 0) {
            foreach ($tiers as $tier) {
                if ($tier->getQuantity() >= $quantity) {
                    return $tier->getUnitPrice();
                }
            }

            $last = $tiers->last();
            if ($last !== null) {
                return $last->getUnitPrice();
            }
        }

        return $product->getCalculatedPrice()->getUnitPrice();
    }
}
