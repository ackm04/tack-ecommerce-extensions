<?php
/**
 * TackQuote for OpenCart — quote-only (B2B catalog) mode ENFORCEMENT.
 *
 * Route: extension/tack/quotemode
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * HOW A REQUEST IS ACTUALLY REFUSED (this is the whole point of the file)
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Hiding the Add to Cart button is not a policy — it is a CSS class, and anyone can POST
 * `index.php?route=checkout/cart.add` with curl. So the refusal happens in PHP, before core's
 * cart controller is constructed, by REWRITING THE ROUTE the framework is about to dispatch.
 *
 * OpenCart 4 passes the route to the `controller/<route>/before` event BY REFERENCE and then
 * builds the Action from the value the handlers left behind. Verified against 4.1.0.4 source,
 * not documentation:
 *
 *   system/framework.php:262   $event->trigger('controller/' . $trigger . '/before', [&$route, &$args]);
 *   system/framework.php:268   if (!$action) {
 *   system/framework.php:269       $action = new \Opencart\System\Engine\Action($route);
 *   system/framework.php:275   $output = $action->execute($registry, $args);
 *
 * `$action` is initialised to `''` at system/framework.php:214 and is only set before that
 * point by a pre-action that returns one (maintenance mode, the error handler). So on an
 * ordinary storefront request the `if (!$action)` branch is taken and the Action is built
 * from OUR value. Core `checkout/cart.add` is never constructed and never runs; nothing can
 * reach `$this->cart->add()` (catalog/controller/checkout/cart.php:286).
 *
 * The same by-reference rewrite works for internal `$this->load->controller(...)` calls,
 * because system/engine/loader.php:73-80 triggers the identical event and then splits the
 * possibly-modified `$route`. That matters for checkout/confirm, which the checkout page
 * loads as a sub-controller as well as exposing as a route.
 *
 * Two consequences worth stating plainly:
 *
 *   - The event rows MUST exist in `oc_event`. They are written by the admin controller's
 *     install() and are visible to the merchant under Extensions > Events. If a merchant
 *     disables the `tackquotes_guard_*` rows there, enforcement stops — that is OpenCart's
 *     design, and the settings screen says so.
 *   - `Opencart\System\Engine\Event::trigger()` matches a registered trigger as a PREFIX
 *     (system/engine/event.php:70, `preg_match('/^…/')`), so every trigger registered for
 *     this file is written out in full, ending in `/before`. A shorter one such as
 *     `catalog/controller/checkout/cart` would also fire on `/after` — too late to refuse
 *     anything, and it would fire on cart.remove, which must keep working.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS GUARDED, AND WHAT IS DELIBERATELY NOT
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Guarded (see Tackquotes::events() in the admin controller for the registered rows):
 *
 *   checkout/cart.add           the add-to-cart POST — the headline of the feature
 *   checkout/cart.edit          quantity change on an EXISTING line. Not decoration: without
 *                               it a session cart holding one item from before the switch
 *                               could be edited to quantity 10 000 and checked out, so
 *                               blocking only .add would be a policy with a hole in it.
 *   checkout/checkout           the checkout page
 *   checkout/confirm            where the order is actually created
 *                               (catalog/controller/checkout/confirm.php:280 addOrder)
 *   checkout/confirm.confirm    the same code reached by its own route (confirm.php:359
 *                               is `$this->response->setOutput($this->index());`)
 *
 * NOT guarded, on purpose:
 *
 *   checkout/cart               VIEWING the cart. See the standing decision below.
 *   checkout/cart.remove        emptying it.
 *   api/*                       the admin/API session. OpenCart's admin "Sales > Orders >
 *                               Add Order" drives the storefront `api/cart.add`
 *                               (catalog/controller/api/cart.php:183) — a different route
 *                               from checkout/cart.add — and TackQuote's own
 *                               extension/tack/api/order.php places quote-accepted orders.
 *                               Quote-only mode is a STOREFRONT policy; blocking these
 *                               would stop the merchant taking phone orders and would stop
 *                               TackQuote converting the very quotes the mode exists to
 *                               collect. Nothing here registers an `api/…` trigger, and
 *                               tests/run.php asserts that none ever appears.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────
 * STANDING DECISION — EXISTING CARTS AND THE CHECKOUT ROUTE
 * ─────────────────────────────────────────────────────────────────────────────────────────
 *
 * Turning the mode on does NOT empty anybody's cart. A session cart is the shopper's data,
 * and silently deleting it is both hostile and unrecoverable; OpenCart also keeps carts in
 * `oc_cart` across sessions for logged-in customers, so "silently" could mean weeks later.
 * The cart page therefore stays reachable and cart.remove stays allowed, so a shopper can
 * still see and clear what they had.
 *
 * What the cart CANNOT do while the mode applies is grow or convert: add and edit are
 * refused, and checkout/confirm — the only storefront path to addOrder() — is refused.
 * A pre-existing cart is thus inert rather than deleted, and becomes live again untouched
 * the moment a merchant switches the mode off.
 *
 * KNOWN LIMIT, stated rather than papered over: a third-party payment extension that calls
 * `$this->model_checkout_order->addOrder()` itself instead of going through
 * checkout/confirm would not be intercepted by these rows. Core has no such path — the only
 * other addOrder() callers in 4.1.0.4 are catalog/controller/api/order.php (the admin API,
 * exempt by design) and catalog/controller/cron/subscription.php (recurring billing for
 * subscriptions taken out before the switch). This is UNVERIFIED for the whole third-party
 * ecosystem, which cannot be enumerated. The invariant that does hold unconditionally is
 * the one the feature is sold on: nothing new can enter the cart.
 */

namespace Opencart\Catalog\Controller\Extension\Tack;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\QuoteOnly;

class Quotemode extends Controller
{
    /** Where a refused route is sent instead. */
    private const RESPONDER_JSON = 'extension/tack/quotemode.blocked';
    private const RESPONDER_PAGE = 'extension/tack/quotemode.notice';

    /**
     * `controller/checkout/cart.add/before`, `controller/checkout/cart.edit/before`.
     *
     * Both are answered by the storefront with `dataType: 'json'`
     * (catalog/view/template/product/product.twig:303 and the `data-oc-toggle="ajax"`
     * handler in catalog/view/javascript/common.js:93), so the refusal must be JSON.
     *
     * @param string       $route The route the framework is about to dispatch, BY REFERENCE.
     * @param array<mixed> $args
     */
    public function guardCart(string &$route, array &$args = []): void
    {
        if (!$this->applies()) {
            return;
        }

        $route = self::RESPONDER_JSON;
    }

    /**
     * `controller/checkout/checkout/before`, `controller/checkout/confirm/before`,
     * `controller/checkout/confirm.confirm/before`.
     *
     * These are page requests, so they get a page.
     *
     * @param string       $route BY REFERENCE — see the class comment.
     * @param array<mixed> $args
     */
    public function guardCheckout(string &$route, array &$args = []): void
    {
        if (!$this->applies()) {
            return;
        }

        $route = self::RESPONDER_PAGE;
    }

    /**
     * The refusal, as JSON.
     *
     * `error.warning` is the key OpenCart's own ajax handler renders as a red banner in
     * `#alert` (catalog/view/javascript/common.js:134-135), which is the path a category
     * tile's cart button takes.
     *
     * No `redirect` key is set, unlike core's cart.add failure path. Core redirects to the
     * product page to re-render option errors; here there is nothing to re-render and a
     * redirect would replace a stated reason with an unexplained page reload.
     *
     * HONEST LIMIT: the stock product page has its own inline success handler
     * (product.twig:318-326) which maps error KEYS to `#error-<key>` elements and has no
     * `#error-warning` element, so on that page the banner is not drawn. That path is only
     * reachable by a crafted POST or a theme that kept the cart button, because in
     * quote-only mode the product page's Add to Cart button has been REPLACED by the quote
     * CTA before the shopper ever sees it (catalog/controller/event/quote.php::productPage).
     * The refusal itself is server-side and unconditional either way.
     */
    public function blocked(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode([
            'error' => ['warning' => $this->language->get('error_quote_only')],
        ]));
    }

    /**
     * The refusal, as a page — and the page carries a quote CTA.
     *
     * A bare redirect back to the cart was the first design and it was wrong: the shopper
     * clicks Checkout, lands back on the cart with no explanation, clicks Checkout again.
     * This renders a real page that says what the store is and offers the two things that
     * still work — request a quote, or keep browsing. The site-wide drawer is injected into
     * every page by the footer event, so the "Request a quote" button here opens the same
     * form as everywhere else.
     */
    public function notice(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $this->document->setTitle($this->language->get('text_quote_only_heading'));

        $language = (string) $this->config->get('config_language');

        $data = [
            'heading_title'    => $this->language->get('text_quote_only_heading'),
            'text_quote_only'  => $this->language->get('text_quote_only_body'),
            'request_label'    => (string) ($this->config->get('module_tackquotes_button_label')
                ?: $this->language->get('button_default_label')),
            'continue_label'   => $this->language->get('button_continue_shopping'),
            'continue_href'    => $this->url->link('common/home', 'language=' . $language),
            'cart_href'        => $this->url->link('checkout/cart', 'language=' . $language),
            'text_cart_link'   => $this->language->get('text_quote_only_cart_link'),
            'breadcrumbs'      => [
                [
                    'text' => $this->language->get('text_home'),
                    'href' => $this->url->link('common/home', 'language=' . $language),
                ],
                [
                    'text' => $this->language->get('text_quote_only_heading'),
                    'href' => $this->url->link('extension/tack/quotemode.notice', 'language=' . $language),
                ],
            ],
        ];

        $data['column_left'] = $this->load->controller('common/column_left');
        $data['column_right'] = $this->load->controller('common/column_right');
        $data['content_top'] = $this->load->controller('common/content_top');
        $data['content_bottom'] = $this->load->controller('common/content_bottom');
        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('extension/tack/quote/blocked', $data));
    }

    /**
     * Does quote-only mode apply to THIS request?
     *
     * Public because catalog/controller/event/quote.php asks the same question when it
     * decides whether to swap the Add to Cart button for the quote CTA, and the two answers
     * must never differ — see QuoteOnly's class comment.
     */
    public function applies(): bool
    {
        return QuoteOnly::appliesToStorefront(
            $this->config,
            $this->customer->isLogged(),
            $this->effectiveCustomerGroupId(),
            $this->isAdminPreview()
        );
    }

    /**
     * A guest has customer_group_id 0 (system/library/cart/customer.php:36), which is a
     * sentinel and not a real group, so a guest is evaluated against the group OpenCart
     * already prices them in: the store's `config_customer_group_id`. Without this
     * substitution "specific groups" could never match a guest, and a merchant who selected
     * their default group would see the mode apply to logged-in customers only.
     */
    private function effectiveCustomerGroupId(): int
    {
        if ($this->customer->isLogged()) {
            return (int) $this->customer->getGroupId();
        }

        return (int) $this->config->get('config_customer_group_id');
    }

    /**
     * ADMIN PREVIEW EXEMPTION.
     *
     * A merchant logged into the admin panel in the same browser keeps the full cart and
     * checkout on the storefront, so quote-only mode can be verified — and reverted — from
     * a real session rather than from a screenshot.
     *
     * Copied in shape from core's maintenance mode, which is the only other feature in
     * OpenCart that switches the storefront off for the public and not for staff:
     *
     *   $user = new \Opencart\System\Library\Cart\User($this->registry);
     *   … && !$user->isLogged()
     *   — catalog/controller/startup/maintenance.php:29-31 (4.1.0.4)
     *
     * Constructed lazily, and only once the cheap config checks have already decided the
     * mode is otherwise on, because the constructor issues a DB query and this runs on
     * every add-to-cart. `protected` so the test harness can exercise both sides of the
     * exemption without a database.
     */
    protected function isAdminPreview(): bool
    {
        if (!$this->config->get('module_tackquotes_quote_only')) {
            return false;
        }

        if (!class_exists('\Opencart\System\Library\Cart\User')) {
            return false;
        }

        $user = new \Opencart\System\Library\Cart\User($this->registry);

        return $user->isLogged();
    }
}
