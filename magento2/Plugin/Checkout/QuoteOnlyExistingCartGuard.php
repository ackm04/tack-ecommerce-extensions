<?php
/**
 * Quote-only mode — the EXISTING cart.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * STANDING DECISION ON PRE-EXISTING CARTS
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Switching quote-only mode on does NOT empty anybody's cart. A cart is the shopper's data,
 * and Magento persists it server-side in `quote`/`quote_item` for signed-in customers, so
 * "silently deleted" could mean weeks later, on a different device, with no way to get it
 * back. Deleting it would also make the switch destructive and effectively one-way, which is
 * a terrible property for a setting a merchant may want to try for an afternoon.
 *
 * So the cart page stays reachable, and removing items stays allowed. What a cart CANNOT do
 * while the mode applies is GROW or CONVERT:
 *
 *   grow     Quote::addProduct is refused by Plugin\Quote\QuoteOnlyCartGuard, and the three
 *            methods below close the paths that mutate an EXISTING line rather than adding
 *            a new one. `updateItems` is not decoration: without it a cart holding one item
 *            from before the switch could be edited to quantity 10 000 and checked out, so
 *            guarding only the add would be a policy with a hole in it.
 *   convert  Plugin\Checkout\QuoteOnlyCheckoutGuard refuses the checkout route.
 *
 * A pre-existing cart is therefore INERT rather than destroyed, and becomes live again,
 * untouched, the moment the merchant switches the mode off.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHY DECREASES AND REMOVALS ARE STILL ALLOWED
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * beforeUpdateItems() refuses only quantity INCREASES. Refusing the whole call would be
 * simpler and is wrong: Magento routes "remove" and "set qty to 0" through the same
 * `updateItems()` (module-checkout/Model/Cart.php:533 handles `remove` / `qty == '0'`), so a
 * blanket refusal would trap a shopper with a cart they can see, cannot use, and cannot
 * clear. Letting them shrink or empty it costs the policy nothing — the cart still cannot
 * be checked out — and is the difference between a catalog and a hostage situation.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Plugin\Checkout;

use Magento\Checkout\Model\Cart;
use Magento\Framework\Exception\LocalizedException;
use TackQuote\Quotes\Model\QuoteOnlyMode;

class QuoteOnlyExistingCartGuard
{
    /**
     * @var QuoteOnlyMode
     */
    private $quoteOnlyMode;

    /**
     * @param QuoteOnlyMode $quoteOnlyMode
     */
    public function __construct(QuoteOnlyMode $quoteOnlyMode)
    {
        $this->quoteOnlyMode = $quoteOnlyMode;
    }

    /**
     * Cart page "Update Shopping Cart". Refuses increases; allows decreases and removals.
     *
     * Mirrors Magento\Checkout\Model\Cart::updateItems() (module-checkout/Model/Cart.php:517).
     * The `$data` shape is `[itemId => ['qty' => n, 'remove' => …], …]`, read exactly as core
     * reads it at Cart.php:527-543.
     *
     * @param Cart $subject
     * @param array<int|string, mixed> $data
     * @return void
     * @throws LocalizedException
     */
    public function beforeUpdateItems(Cart $subject, $data): void
    {
        if (!$this->quoteOnlyMode->isActive() || !is_array($data)) {
            return;
        }

        foreach ($data as $itemId => $itemInfo) {
            if (!is_array($itemInfo)) {
                continue;
            }

            // A removal, however it is spelled. Core treats both as "remove" at Cart.php:533.
            if (!empty($itemInfo['remove']) || (isset($itemInfo['qty']) && (string) $itemInfo['qty'] === '0')) {
                continue;
            }

            if (!isset($itemInfo['qty'])) {
                continue;
            }

            $item = $subject->getQuote()->getItemById($itemId);

            if (!$item) {
                // Core skips unknown ids (Cart.php:529-531); so do we, rather than refusing a
                // whole cart update because one stale row was posted.
                continue;
            }

            if ((float) $itemInfo['qty'] > (float) $item->getQty()) {
                $this->refuse();
            }
        }
    }

    /**
     * "Edit item" / reconfigure from the cart page. Always refused: it can raise the quantity
     * and change the configuration, and there is no shrink-only version of it worth carving
     * out.
     *
     * Mirrors Magento\Checkout\Model\Cart::updateItem() (module-checkout/Model/Cart.php:716).
     *
     * @param Cart $subject
     * @param int|string $itemId
     * @param mixed $requestInfo
     * @param mixed $updatingParams
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeUpdateItem(Cart $subject, $itemId, $requestInfo = null, $updatingParams = null): void
    {
        $this->refuse();
    }

    /**
     * Reorder ("Order Again" on a past order). An add by another name.
     *
     * Mirrors Magento\Checkout\Model\Cart::addOrderItem() (module-checkout/Model/Cart.php:267).
     * It reaches Quote::addProduct() eventually, so this is belt-and-braces — but it is the
     * one path where a shopper can refill a cart in a single click, and a clear refusal here
     * is a better message than one raised three frames deeper.
     *
     * @param Cart $subject
     * @param mixed $orderItem
     * @param mixed $qtyFlag
     * @return void
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function beforeAddOrderItem(Cart $subject, $orderItem, $qtyFlag = null): void
    {
        $this->refuse();
    }

    /**
     * @return void
     * @throws LocalizedException
     */
    private function refuse(): void
    {
        if (!$this->quoteOnlyMode->isActive()) {
            return;
        }

        throw new LocalizedException(
            __(
                'This store is quote-only. You can review or empty your basket, but it cannot '
                . 'be increased or checked out — request a quote instead.'
            )
        );
    }
}
