<?php
/**
 * TackQuote for OpenCart — quote-only (B2B catalog) mode decision.
 *
 * WHY A SEPARATE, DEPENDENCY-FREE CLASS.
 *
 * "Is this visitor quote-only right now?" is asked from four places that have nothing else
 * in common: the storefront guard that refuses an add-to-cart POST
 * (catalog/controller/quotemode.php), the product-page view event that swaps the Add to
 * Cart button for the quote CTA (catalog/controller/event/quote.php), the drawer config
 * the storefront JS reads, and the admin settings validator. If each re-derived the rule
 * from `$this->config` the four would drift, and the failure that drift produces is the
 * worst one available here: enforcement ON while the CTA is OFF, i.e. a store nobody can
 * buy from and nobody can request a quote from.
 *
 * So the rule lives once, as pure functions over primitives — no Registry, no Config, no
 * DB. That is also what makes it directly unit-testable and mutation-testable
 * (tests/run.php § quote-only mode).
 *
 * The class is loaded by OpenCart's own autoloader: catalog/controller/startup/extension.php
 * registers `Opencart\System\Library\Extension\<Code>` against
 * `extension/<code>/system/library/`, and system/engine/autoloader.php:76 lower-cases the
 * remainder of the class name inserting `_` before each capital — so `QuoteOnly` must live
 * in `quote_only.php`. Renaming either half silently breaks the load.
 *
 * NAMING TRAP, found the hard way. This class was first called `QuoteMode`, which made
 * `php -l` on catalog/controller/quotemode.php fail with "Cannot declare class …\\Quotemode
 * because the name is already in use": PHP class names are CASE-INSENSITIVE, so the `use`
 * alias `QuoteMode` and the guard controller's own class `Quotemode` are the same name in
 * the same file. The controller's name is fixed by its route (extension/tack/quotemode), so
 * the library was renamed instead.
 */

namespace Opencart\System\Library\Extension\Tack;

final class QuoteOnly
{
    /** Every storefront visitor is quote-only. The default. */
    public const SCOPE_ALL = 'all';

    /**
     * Only visitors who are NOT logged in. This is the "approved B2B customers keep the
     * cart" setting: the shop is a catalog to the public and a shop to account holders.
     */
    public const SCOPE_GUESTS = 'guests';

    /** Only visitors whose customer group is in the selected set. */
    public const SCOPE_GROUPS = 'groups';

    /** @return array<int, string> */
    public static function scopes(): array
    {
        return [self::SCOPE_ALL, self::SCOPE_GUESTS, self::SCOPE_GROUPS];
    }

    /**
     * Anything unrecognised becomes SCOPE_ALL rather than throwing.
     *
     * Deliberately the STRICTEST scope, not the most permissive. A corrupted or
     * hand-edited `oc_setting` row must not quietly re-open the cart on a store whose
     * owner switched quote-only on; it is the merchant's explicit `module_tackquotes_quote_only`
     * flag that decides whether the feature runs at all, and that flag is read separately.
     *
     * @param mixed $scope
     */
    public static function normaliseScope($scope): string
    {
        $scope = is_string($scope) ? strtolower(trim($scope)) : '';

        return in_array($scope, self::scopes(), true) ? $scope : self::SCOPE_ALL;
    }

    /**
     * Customer group ids, however OpenCart handed them over.
     *
     * `model_setting_setting` JSON-encodes array values on write and decodes them on read,
     * but a store that has never saved the field returns null, and a store upgraded from a
     * hand-written row can return the raw JSON string. All three are accepted; anything
     * that is not a positive integer is dropped rather than cast to 0, because group 0 is
     * the guest sentinel and would silently widen the rule to every guest.
     *
     * @param mixed $groups
     *
     * @return array<int, int>
     */
    public static function normaliseGroups($groups): array
    {
        if (is_string($groups)) {
            $decoded = json_decode($groups, true);
            $groups = is_array($decoded) ? $decoded : preg_split('/\s*,\s*/', trim($groups), -1, PREG_SPLIT_NO_EMPTY);
        }

        if (!is_array($groups)) {
            return [];
        }

        $out = [];

        foreach ($groups as $group) {
            if (is_array($group) || is_object($group) || is_bool($group) || $group === null) {
                continue;
            }

            $id = (int) $group;

            if ($id > 0 && !in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * The whole rule, in one place.
     *
     * @param bool            $enabled         `module_tackquotes_quote_only`, AND the module
     *                                         being usable at all — see Quotemode::applies(),
     *                                         which refuses to enforce when TackQuote is not
     *                                         configured, because enforcement without a
     *                                         working quote button is a dead store.
     * @param string          $scope           One of the SCOPE_* constants.
     * @param array<int, int> $groupIds        Selected customer groups, for SCOPE_GROUPS.
     * @param bool            $isLogged        `$this->customer->isLogged()`.
     * @param int             $customerGroupId The visitor's effective customer group — a
     *                                         logged-in customer's own group, or the store's
     *                                         `config_customer_group_id` for a guest, which
     *                                         is the group OpenCart already prices guests in
     *                                         (system/library/cart/customer.php:36 leaves the
     *                                         property at 0 for a guest, so the caller must
     *                                         substitute; passing the raw 0 would make
     *                                         SCOPE_GROUPS never match a guest).
     */
    public static function applies(
        bool $enabled,
        string $scope,
        array $groupIds,
        bool $isLogged,
        int $customerGroupId
    ): bool {
        if (!$enabled) {
            return false;
        }

        switch (self::normaliseScope($scope)) {
            case self::SCOPE_GUESTS:
                return !$isLogged;

            case self::SCOPE_GROUPS:
                // An empty selection matches nobody, so the cart stays open. The admin
                // save() refuses to persist SCOPE_GROUPS with no groups precisely so a
                // merchant never reaches this state by accident and wonders why nothing
                // changed — but if a row is hand-edited, failing open on the CART is the
                // safe direction: the store keeps working.
                return in_array($customerGroupId, self::normaliseGroups($groupIds), true);

            case self::SCOPE_ALL:
            default:
                return true;
        }
    }

    /**
     * The rule as the STOREFRONT asks it — one definition shared by the request guard
     * (catalog/controller/quotemode.php), the product-page view event
     * (catalog/controller/event/quote.php) and the drawer config the JS reads.
     *
     * Three refusals happen here rather than in applies(), because they are about whether
     * enforcement is safe at all rather than about who it targets:
     *
     *  1. `$isAdminPreview` — a logged-in ADMIN user browsing the storefront is exempt, so
     *     a merchant can verify that cart and checkout still work before and after flipping
     *     the switch. This mirrors core's own maintenance-mode exemption, which builds a
     *     `\Opencart\System\Library\Cart\User` and checks isLogged()
     *     (catalog/controller/startup/maintenance.php:29-31 in 4.1.0.4).
     *
     *  2. Module status off — nothing this extension does should run.
     *
     *  3. **No API key configured.** This one is load-bearing and is not an optimisation.
     *     catalog/controller/event/quote.php::isActive() renders NO quote button until an
     *     API key is stored, because a button that cannot reach TackQuote drops the
     *     shopper's request on the floor. If enforcement did not honour the same condition,
     *     a merchant who enabled quote-only before pasting their key would get a storefront
     *     with the cart refused AND no quote button: a catalog nobody can transact with in
     *     either direction. Enforcement and CTA are switched on by the same condition on
     *     purpose. tests/run.php asserts it.
     *
     * @param object $config          Anything exposing get(string) — OpenCart's Config.
     * @param bool   $isLogged        $this->customer->isLogged()
     * @param int    $customerGroupId Effective group; see applies().
     * @param bool   $isAdminPreview  An admin user is logged in to this browser.
     */
    public static function appliesToStorefront($config, bool $isLogged, int $customerGroupId, bool $isAdminPreview): bool
    {
        if ($isAdminPreview) {
            return false;
        }

        if (!$config->get('module_tackquotes_status')) {
            return false;
        }

        if (trim((string) $config->get('module_tackquotes_api_key')) === '') {
            return false;
        }

        return self::applies(
            (bool) $config->get('module_tackquotes_quote_only'),
            (string) $config->get('module_tackquotes_quote_only_scope'),
            self::normaliseGroups($config->get('module_tackquotes_quote_only_groups')),
            $isLogged,
            $customerGroupId
        );
    }
}
