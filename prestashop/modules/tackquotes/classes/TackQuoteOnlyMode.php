<?php

/**
 * Quote-only / B2B catalog mode — the decision layer.
 *
 * Deliberately free of every PrestaShop symbol. Everything here is a pure
 * function of values the caller has already read out of PrestaShop, which is
 * what lets `tests/QuoteOnlyModeTest.php` run the real guard under `php -f`
 * with no shop, no database and no autoloader. The enforcement that acts on
 * these answers lives in TackQuotes::hookActionFrontControllerInitBefore().
 *
 * @author    TackQuote
 * @copyright TackQuote
 * @license   GPL-2.0-or-later
 */
if (!defined('_PS_VERSION_') && !defined('TACKQUOTES_TEST_MODE')) {
    exit;
}

class TackQuoteOnlyMode
{
    /** Everyone browsing the storefront. The documented default. */
    public const SCOPE_EVERYONE = 'everyone';

    /**
     * Only visitors who are not signed in. An approved B2B customer who logs in
     * keeps a working cart — that is the whole point of this scope, and it is
     * why `applies()` takes $isLogged rather than inferring it from the groups.
     */
    public const SCOPE_GUESTS = 'guests';

    /** Only visitors in one of the selected customer groups. */
    public const SCOPE_GROUPS = 'groups';

    /**
     * Query/POST keys that make CartController mutate the cart upwards.
     *
     * From PrestaShop 8.2 controllers/front/CartController.php:241:
     * `Tools::getIsset('add') || Tools::getIsset('update')` routes to
     * processChangeProductInCart(); `Tools::getIsset('delete')` routes to the
     * delete path. `delete` is intentionally ABSENT from this list — see
     * isCartMutationRequest().
     */
    private const CART_ADD_KEYS = ['add', 'update'];

    /**
     * Coerce a stored scope value to one of the three supported scopes.
     *
     * An unrecognised value (hand-edited config row, a value written by an older
     * version) resolves to SCOPE_EVERYONE rather than to "no restriction": the
     * merchant switched quote-only ON, so the safe reading of a damaged scope is
     * the broadest one, not silently selling again.
     *
     * @param mixed $scope
     *
     * @return string
     */
    public static function normalizeScope($scope)
    {
        $scope = is_string($scope) ? strtolower(trim($scope)) : '';

        if ($scope === self::SCOPE_GUESTS || $scope === self::SCOPE_GROUPS) {
            return $scope;
        }

        return self::SCOPE_EVERYONE;
    }

    /**
     * Parse the stored comma-separated group id list into unique positive ints.
     *
     * @param mixed $csv
     *
     * @return int[]
     */
    public static function parseGroupIds($csv)
    {
        if (is_array($csv)) {
            $parts = $csv;
        } elseif (is_string($csv)) {
            $parts = explode(',', $csv);
        } else {
            return [];
        }

        $ids = [];
        foreach ($parts as $part) {
            $id = (int) trim((string) $part);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /**
     * Should this request be treated as quote-only?
     *
     * @param array $settings {
     *   enabled:     bool  merchant toggled quote-only on
     *   ctaReady:    bool  the quote CTA can actually render right now
     *   scope:       string one of the SCOPE_* constants
     *   groups:      int[] selected group ids, only read for SCOPE_GROUPS
     * }
     * @param bool $isLogged whether a real (non-guest-checkout) customer is signed in
     * @param int[] $customerGroupIds every group the visitor belongs to
     * @param bool $isEmployeePreview back-office preview with a valid admin token
     *
     * @return bool
     */
    public static function applies(array $settings, $isLogged, array $customerGroupIds, $isEmployeePreview)
    {
        if (empty($settings['enabled'])) {
            return false;
        }

        // NEVER leave the store with no way to transact. Quote-only removes the
        // cart, so it may only engage when the thing that replaces the cart is
        // actually renderable — storefront button switched on AND an API key
        // saved, because hookDisplayProductActions() returns '' without either.
        // Without this the two existing early-returns in that hook would turn a
        // quote-only shop into a shop that can neither add to cart nor quote.
        if (empty($settings['ctaReady'])) {
            return false;
        }

        // Employee preview stays exempt: a merchant checking the storefront with
        // ?preview=1&adtoken=... sees the ordinary shop, cart included.
        if ($isEmployeePreview) {
            return false;
        }

        $scope = self::normalizeScope(isset($settings['scope']) ? $settings['scope'] : null);

        if ($scope === self::SCOPE_GUESTS) {
            return !$isLogged;
        }

        if ($scope === self::SCOPE_GROUPS) {
            $selected = self::parseGroupIds(isset($settings['groups']) ? $settings['groups'] : []);

            // No group selected is not "everyone" — it is a half-finished
            // configuration. Refusing to engage keeps the store selling.
            if (empty($selected)) {
                return false;
            }

            foreach ($customerGroupIds as $groupId) {
                if (in_array((int) $groupId, $selected, true)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Does this request ask CartController to put something in the cart?
     *
     * `delete` is excluded on purpose. A shopper who already had a cart when the
     * merchant flipped the switch must still be able to empty it; blocking the
     * removal path would strand items they can no longer check out with. Adding
     * and increasing are the operations quote-only exists to refuse.
     *
     * @param array $query $_GET
     * @param array $post $_POST
     *
     * @return bool
     */
    public static function isCartMutationRequest(array $query, array $post)
    {
        foreach (self::CART_ADD_KEYS as $key) {
            if (array_key_exists($key, $query) || array_key_exists($key, $post)) {
                return true;
            }
        }

        return false;
    }
}
