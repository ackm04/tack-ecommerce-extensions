<?php
/**
 * TackQuote for OpenCart — catalog feed.
 *
 * Route: `index.php?route=extension/tack/api/product.list`
 *
 * This is the store side of the contract that
 * `apps/api/src/modules/integrations/opencart/opencart.service.ts` has always
 * called (`OpenCartService.syncProducts` / `.testConnection`). Until this file
 * existed the route 404'd, because OpenCart core has no catalog-listing JSON
 * route at all — core's `route=api/*` is a session cart/checkout API entered
 * through `api/account/login`, which cannot serve a product feed.
 *   https://github.com/opencart/opencart/blob/master/docs/api/source-catalog.controller.api.account.login.html
 *
 * Route resolution (verified against OpenCart 4.0.2.3, not assumed):
 *   - system/engine/action.php splits `…/product.list` at the LAST dot into
 *     route `extension/tack/api/product` + method `list`.
 *   - system/engine/factory.php maps that route to
 *     `Opencart\Catalog\Controller\Extension\Tack\Api\Product`.
 *   - catalog/controller/startup/extension.php registers
 *     `Opencart\Catalog\Controller\Extension\Tack` =>
 *     `extension/tack/catalog/controller/`, so this file must live at
 *     `catalog/controller/api/product.php` inside an extension installed as
 *     `tack` (i.e. from `tack.ocmod.zip` — the installer takes the extension
 *     code from the ZIP FILENAME).
 *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/system/engine/action.php
 *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/controller/startup/extension.php
 *   https://github.com/opencart/opencart/blob/4.0.2.3/upload/admin/controller/marketplace/installer.php
 *
 * Confirmed against OpenCart's OWN documentation, not only its source:
 * <https://docs.opencart.com/developer-guide/extensions> states the zip is
 * named `<code>.ocmod.zip`, that "a folder will be created into the extension/
 * directory based on the name of your file", that the package root holds
 * `install.json` + `admin/` + `catalog/` ("you must not zip the folder … but
 * the inside files directly"), and that classes are namespaced
 * `Opencart\{Admin,Catalog}\Controller\Extension\<Code>\…`. Its own worked
 * example puts a catalog controller at `catalog/controller/events.php`
 * (namespace `…\Extension\TestModule`, invoked as
 * `extension/test_module/events.onCartAddBefore`) — i.e. an extension's catalog
 * controllers are NOT confined to `module/`, which is what makes the `api/`
 * directory used here a documented arrangement rather than an invention.
 *
 * The same page also returns JSON exactly as below —
 * `$this->response->addHeader('Content-Type: application/json');
 *  $this->response->setOutput(json_encode($json));`
 *
 * UNVERIFIED (vendor docs are silent, checked page-by-page uncapped): OpenCart
 * publishes no guidance on authenticating a CUSTOM extension route. Its only
 * API documentation, <https://docs.opencart.com/admin-interface/system/users/api>,
 * covers the admin-managed API user for core's session cart/checkout API and
 * offers nothing a custom route can reuse. The Bearer scheme in ApiGuard is
 * therefore TackQuote's own, chosen to match what the connector already sends.
 * That page does recommend IP-restricting API credentials — worth doing at the
 * web-server level for these routes too.
 *
 * `list` is a PHP reserved word but has been legal as a METHOD name since
 * PHP 7.0 (context-sensitive lexer); OpenCart 4 core uses `public function
 * list()` on its own admin controllers.
 */

namespace Opencart\Catalog\Controller\Extension\Tack\Api;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\ApiGuard;

class Product extends Controller
{
    /**
     * GET index.php?route=extension/tack/api/product.list[&page=&limit=]
     *
     * Response (exactly the shape OpenCartService.syncProducts reads):
     *   { "products": [ { product_id, model, sku, name, description, price,
     *                     special, image, status, quantity } ], "total": n,
     *     "page": n, "limit": n }
     *
     * `limit` is optional and defaults to "everything". TackQuote's
     * `syncProducts()` issues ONE unpaginated call, so a default page size here
     * would silently drop the rest of the catalog — a loud out-of-memory error
     * on a very large catalog is preferable to a sync that quietly imports the
     * first 50 products and reports success. Merchants with very large catalogs
     * should raise PHP's `memory_limit`; the `page`/`limit` parameters are
     * honoured if a future client sends them.
     */
    public function list(): void
    {
        $check = ApiGuard::check(
            $this->request->server,
            (string) $this->config->get('module_tackquotes_connector_token')
        );

        if (!$check['ok']) {
            ApiGuard::json($this->response, $this->request->server, $check['status'], $check['reason'], [
                'error' => $check['error'],
                'code'  => $check['code'],
            ]);

            return;
        }

        $paging = ApiGuard::paging($this->request->get);

        // Every value interpolated below is either an (int) cast of a store
        // setting or an (int) cast of a request parameter — OpenCart's own
        // convention for integers (its models do exactly this; $this->db has no
        // prepared-statement API). No string from the request reaches SQL on
        // this route at all, so there is no injection surface.
        //
        // UNVERIFIED: OpenCart's documentation contains no SQL-escaping
        // guidance at all — its Coding Standard page
        // (https://docs.opencart.com/developer-guide/coding-standard, read in
        // full) covers naming and formatting only, and the Extensions page says
        // no more than "define methods like addData() with SQL queries". The
        // (int)-cast / $this->db->escape() idiom used here is taken from core's
        // own models, which is the only authority that exists.
        $storeId = (int) $this->config->get('config_store_id');
        $languageId = (int) $this->config->get('config_language_id');
        $customerGroupId = (int) $this->config->get('config_customer_group_id');

        // Special-price subquery copied from OpenCart's own catalog product
        // model so the feed reports the same "special" the storefront shows.
        // https://github.com/opencart/opencart/blob/4.0.2.3/upload/catalog/model/catalog/product.php
        $special = "(SELECT `ps`.`price` FROM `" . DB_PREFIX . "product_special` `ps`"
            . " WHERE `ps`.`product_id` = `p`.`product_id`"
            . " AND `ps`.`customer_group_id` = '" . $customerGroupId . "'"
            . " AND ((`ps`.`date_start` = '0000-00-00' OR `ps`.`date_start` < NOW())"
            . " AND (`ps`.`date_end` = '0000-00-00' OR `ps`.`date_end` > NOW()))"
            . " ORDER BY `ps`.`priority` ASC, `ps`.`price` ASC LIMIT 1) AS `special`";

        // product_to_store scopes the feed to the store TackQuote is connected
        // to, which matters on a multi-store install. Unlike the storefront
        // model this deliberately does NOT filter `p`.`status` = '1' or
        // `date_available` — a disabled product must still be reported, with
        // status 0, so TackQuote can deactivate its own copy instead of leaving
        // a stale product live.
        $from = " FROM `" . DB_PREFIX . "product_to_store` `p2s`"
            . " LEFT JOIN `" . DB_PREFIX . "product` `p` ON (`p`.`product_id` = `p2s`.`product_id`)"
            . " LEFT JOIN `" . DB_PREFIX . "product_description` `pd`"
            . " ON (`pd`.`product_id` = `p`.`product_id` AND `pd`.`language_id` = '" . $languageId . "')"
            . " WHERE `p2s`.`store_id` = '" . $storeId . "'";

        $totalQuery = $this->db->query("SELECT COUNT(*) AS `total`" . $from);
        $total = (int) ($totalQuery->row['total'] ?? 0);

        $sql = "SELECT `p`.`product_id`, `p`.`model`, `p`.`sku`, `p`.`image`, `p`.`price`,"
            . " `p`.`quantity`, `p`.`status`, `pd`.`name`, `pd`.`description`, " . $special
            . $from
            . " ORDER BY `p`.`product_id` ASC";

        if ($paging['limit'] > 0) {
            $sql .= " LIMIT " . (int) $paging['start'] . "," . (int) $paging['limit'];
        }

        $query = $this->db->query($sql);

        $products = [];

        foreach ($query->rows as $row) {
            $products[] = [
                'product_id'  => (int) $row['product_id'],
                'model'       => (string) $row['model'],
                'sku'         => (string) $row['sku'],
                'name'        => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                // Decimal strings, not floats: OpenCart stores prices as
                // DECIMAL(15,4) and json_encode of a float would round-trip
                // through binary. TackQuote parses these with parseFloat.
                'price'       => number_format((float) $row['price'], 4, '.', ''),
                // `false` (not null, not 0) when there is no live special —
                // OpenCartService treats a non-string `special` as "no special"
                // and falls back to `price`. A 0 would read as a free product.
                'special'     => $row['special'] !== null
                    ? number_format((float) $row['special'], 4, '.', '')
                    : false,
                // Raw column value: TackQuote builds {store}/image/{image}.
                'image'       => (string) $row['image'],
                'status'      => (string) (int) $row['status'],
                'quantity'    => (int) $row['quantity'],
            ];
        }

        ApiGuard::json($this->response, $this->request->server, 200, 'OK', [
            'products' => $products,
            'total'    => $total,
            'page'     => $paging['page'],
            'limit'    => $paging['limit'],
        ]);
    }
}
