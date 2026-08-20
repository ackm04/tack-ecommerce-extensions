<?php
/**
 * TackQuote for OpenCart — admin settings controller.
 *
 * Route: extension/tack/module/tackquotes (Extensions > Modules >
 * TackQuote, once installed and enabled from Extensions > Installer /
 * Extensions > Extensions > Modules).
 *
 * Persists a single, store-wide TackQuote API URL + API key (there is only
 * one TackQuote account per store, unlike per-instance content modules such
 * as Banner), plus the storefront button label and an on/off switch. Mirrors
 * TackQuotes::getContent()/renderForm() in the PrestaShop module
 * (integrations/prestashop/modules/tackquotes/tackquotes.php) and
 * Tack_Settings in the WooCommerce plugin.
 *
 * REQUIRED PACKAGE FILENAME: `tack.ocmod.zip`. Every namespace below hard-codes
 * `…\Extension\Tack\…` and the event actions use `extension/tack/event/…`, but
 * the installer derives the folder under `extension/` from the ZIP FILENAME, not
 * from install.json — "a folder will be created into the extension/ directory
 * based on the name of your file"
 * (<https://docs.opencart.com/developer-guide/extensions>). A zip named anything
 * else installs cleanly and then 404s on every route with no error at all. See
 * README.md § Packaging.
 *
 * ADMIN FORM CONTRACT (verified against OpenCart 4.1.0.4's own
 * admin/view/javascript/common.js, not assumed — see save() below).
 */

namespace Opencart\Admin\Controller\Extension\Tack\Module;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\ApiClient;

class Tackquotes extends Controller
{
    /** Permission required to read or change this extension's settings. */
    private const PERMISSION = 'extension/tack/module/tackquotes';

    /**
     * Renders the settings page. GET only.
     *
     * This method deliberately does NOT handle POST any more. Until 1.2.1 it
     * did: it validated, saved, and then answered with
     * `$this->response->redirect(...)` or a full HTML re-render. Meanwhile the
     * form carries `data-oc-toggle="ajax"`, and OpenCart 4's common.js submits
     * such a form with `$.ajax({… dataType: 'json' …})` — so BOTH answers were
     * fed to a JSON parser, failed, and landed in the `error:` callback, whose
     * entire body is `console.log`. The setting persisted, so clicking around by
     * hand looked fine, and the merchant saw absolutely nothing: no success
     * confirmation, no permission error, no invalid-URL error. Saving now lives
     * in save(), which speaks JSON.
     */
    public function index(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $this->document->setTitle($this->language->get('heading_title'));

        $this->response->setOutput($this->load->view('extension/tack/module/tackquotes', $this->buildFormData()));
    }

    /**
     * Persists the settings and answers JSON. Route:
     * extension/tack/module/tackquotes.save
     *
     * This is OpenCart 4's documented save pattern — a separate `.save` method
     * returning `json_encode($json)`, with the permission check first:
     * <https://docs.opencart.com/developer-guide/extensions> § "Create the Admin
     * Controller". The response KEYS below are not a local convention either;
     * they are what 4.1.0.4's common.js actually reads (verified by reading the
     * file, which outranks the prose):
     *
     *   - `$json['error']['warning']`  → prepended to `#alert` as a red banner.
     *   - `$json['error']['<field>']`  → marks `#input-<field>` invalid and fills
     *                                    `#error-<field>` (underscores become
     *                                    dashes), hence the key `api_url` for
     *                                    `#input-api-url` / `#error-api-url`.
     *   - `$json['success']`           → prepended to `#alert` as a green banner.
     *
     * `#alert` is supplied by the stock admin header this page already loads
     * (admin/view/template/common/header.twig:33 in 4.1.0.4), so the template
     * needs no alert container of its own.
     *
     * WHY THE KEYS MATTER. The previous code wrote `$this->error['warning']`,
     * the controller read `$this->error['error_warning']`, and the template
     * rendered `{% if error_warning %}` — three different keys, so the banner
     * was structurally unreachable. A staff user without `modify` permission
     * clicked Save, nothing was written, and they were told NOTHING. Same for an
     * invalid API URL. There is now exactly one key path, and it is the vendor's.
     */
    public function save(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $json = [];

        if (!$this->user->hasPermission('modify', self::PERMISSION)) {
            $json['error']['warning'] = $this->language->get('error_permission');
        }

        $apiUrl = trim((string) ($this->request->post['module_tackquotes_api_url'] ?? ''));

        if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            $json['error']['api_url'] = $this->language->get('error_api_url');
        }

        if (!$json) {
            $this->model_setting_setting_save();

            $json['success'] = $this->language->get('text_success');
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    /**
     * AJAX action: "Test connection" button. Route:
     * extension/tack/module/tackquotes.test
     *
     * PERMISSION-GUARDED, and it must be: this action spends the store's own
     * TackQuote API key on an outbound call and reports back whether the
     * credential works. Until 1.2.1 the only permission check in this file lived
     * in validate(), which was reached from index() alone — so ANY authenticated
     * admin, including one with no rights whatsoever on this extension, could
     * exercise the store's credential and read connection state back out.
     * OpenCart's own guidance is explicit ("Security: Validate inputs, check
     * permissions ($this->user->hasPermission())",
     * <https://docs.opencart.com/developer-guide/extensions> § Best Practices),
     * and its worked save() example opens with exactly this guard.
     *
     * `modify` rather than `access` is deliberate: reading back whether a secret
     * is valid is a use of that secret, not a view of a settings page.
     */
    public function test(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $json = [];

        if (!$this->user->hasPermission('modify', self::PERMISSION)) {
            // A flat string, which is the shape the button's own handler in
            // admin/view/template/module/tackquotes.twig already renders.
            $json['error'] = $this->language->get('error_permission');

            $this->response->addHeader('Content-Type: application/json');
            $this->response->setOutput((string) json_encode($json));

            return;
        }

        // Both fields fall back to what is already stored, for the same reason the save
        // path has keepOrReplace(): the API key input is rendered EMPTY on purpose (the
        // stored secret is only ever shown masked), so a merchant who saves a key and then
        // clicks Test connection posts a blank key. Reading the POST value alone made the
        // button permanently answer "Please enter a TackQuote API key" on every configured
        // store — it could only ever succeed in the one moment before the first save.
        // Verified in OpenCart 4.1.0.4 before fixing.
        $apiUrl = trim((string) ($this->request->post['module_tackquotes_api_url'] ?? ''));
        if ($apiUrl === '') {
            $apiUrl = (string) $this->config->get('module_tackquotes_api_url');
        }

        $apiKey = trim((string) ($this->request->post['module_tackquotes_api_key'] ?? ''));
        if ($apiKey === '') {
            $apiKey = (string) $this->config->get('module_tackquotes_api_key');
        }

        if ($apiKey === '') {
            $json['error'] = $this->language->get('error_api_key');
        } else {
            $client = new ApiClient($apiUrl, $apiKey);
            $result = $client->testConnection();

            if ($result === true) {
                $json['success'] = $this->language->get('text_test_success');
            } else {
                $json['error'] = sprintf($this->language->get('error_test_connection'), (string) $result);
            }
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string) json_encode($json));
    }

    /**
     * Registers the two storefront view events this extension needs.
     *
     * WHY EVENTS. OpenCart's layout positions are page-level, so a module can only put the
     * quote button above or below the whole product page — in practice under the
     * description. WooCommerce has `woocommerce_after_add_to_cart_button` and Magento has
     * `after="product.info.addtocart"`; OpenCart's equivalent is a `view/<route>/after`
     * event, which receives the rendered HTML by reference
     * (`system/engine/loader.php:192`).
     *
     * The trigger MUST keep its `catalog/` prefix: `catalog/controller/startup/event.php`
     * strips that prefix and registers the remainder, so a row saved without it is loaded
     * for no application and the feature is silently dead.
     *
     * Idempotent: OpenCart calls install() again when a merchant re-enables the module from
     * Extensions > Extensions, and duplicate rows would inject the buttons twice.
     */
    public function install(): void
    {
        $this->seedPlacementDefaults();

        $this->load->model('setting/event');

        foreach (self::events() as $event) {
            if ($this->model_setting_event->getEventByCode($event['code'])) {
                continue;
            }

            $this->model_setting_event->addEvent($event);
        }
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_tackquotes');

        // Leaving these behind would keep calling a controller whose files the Extension
        // Installer has just deleted, which is a fatal error on every product page.
        $this->load->model('setting/event');

        foreach (self::events() as $event) {
            $this->model_setting_event->deleteEventByCode($event['code']);
        }
    }

    /**
     * Writes the placement defaults on first install, and only then.
     *
     * Without this, a fresh install has no `module_tackquotes_inline_button` row at all, so
     * `$this->config->get()` returns null, the event handlers bail out, and the merchant
     * sees no quote button anywhere until they happen to open and save the settings screen.
     * That is the same failure shape as the 1.1.0 prefix bug — everything reports success
     * and nothing appears — so the defaults are seeded rather than left implicit.
     *
     * Existing values are preserved: OpenCart calls install() again when a module is
     * re-enabled, and a merchant who deliberately turned the tile buttons off must not have
     * that decision reverted by a re-enable.
     */
    private function seedPlacementDefaults(): void
    {
        $this->load->model('setting/setting');

        $existing = $this->model_setting_setting->getSetting('module_tackquotes');

        if (array_key_exists('module_tackquotes_inline_button', $existing)) {
            return;
        }

        $this->model_setting_setting->editSetting('module_tackquotes', array_merge([
            'module_tackquotes_add_label' => 'Add to Quote',
            'module_tackquotes_inline_button' => 1,
            'module_tackquotes_quote_list' => 1,
            'module_tackquotes_listing_button' => 1,
        ], $existing));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function events(): array
    {
        return [
            [
                'code' => 'tackquotes_product_button',
                'description' => 'TackQuote: quote buttons beside Add to Cart',
                'trigger' => 'catalog/view/product/product/after',
                'action' => 'extension/tack/event/quote.productPage',
                'status' => 1,
                'sort_order' => 1,
            ],
            [
                'code' => 'tackquotes_footer',
                'description' => 'TackQuote: quote list and request form',
                'trigger' => 'catalog/view/common/footer/after',
                'action' => 'extension/tack/event/quote.footer',
                'status' => 1,
                'sort_order' => 1,
            ],
        ];
    }

    private function buildFormData(): array
    {
        $this->load->model('setting/setting');

        $data['breadcrumbs'] = [
            [
                'text' => $this->language->get('text_home'),
                'href' => $this->url->link('common/dashboard', 'user_token=' . $this->session->data['user_token'], true),
            ],
            [
                'text' => $this->language->get('text_extension'),
                'href' => $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true),
            ],
            [
                'text' => $this->language->get('heading_title'),
                'href' => $this->url->link('extension/tack/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true),
            ],
        ];

        // `save`, not `action`: the form posts to the `.save` method, and calling
        // the variable `action` is part of how the redirect-vs-ajax mismatch hid
        // for so long — it read as "this page's own URL", which is what it was.
        $data['save'] = $this->url->link('extension/tack/module/tackquotes.save', 'user_token=' . $this->session->data['user_token'], true);
        $data['test_action'] = $this->url->link('extension/tack/module/tackquotes.test', 'user_token=' . $this->session->data['user_token'], true);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);
        $data['user_token'] = $this->session->data['user_token'];

        // No `success` / `error_warning` keys are built here on purpose. Both
        // outcomes now arrive over the save() JSON response and are rendered by
        // OpenCart's own common.js into the header's `#alert` container, so a
        // template-side copy could only ever drift out of sync with the
        // controller again — which is exactly the bug that made denials silent.

        $fields = [
            'module_tackquotes_status' => 0,
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_api_key' => '',
            'module_tackquotes_button_label' => 'Request a Quote',
            'module_tackquotes_add_label' => 'Add to Quote',
            // Placement. Defaults to ON because the layout-module path puts the button under
            // the product description, which is not where a buyer looks for it.
            'module_tackquotes_inline_button' => 1,
            // The multi-product quote list (WooCommerce drawer / Magento quote list). With
            // it off the extension still works, one product per request.
            'module_tackquotes_quote_list' => 1,
            // Add-to-quote buttons on category and search tiles.
            'module_tackquotes_listing_button' => 1,
            // Bearer token for the INBOUND direction (TackQuote -> this store),
            // i.e. the catalog/order feed served by
            // catalog/controller/api/{product,order}.php. It is a different
            // secret from module_tackquotes_api_key, which authenticates this
            // store when it calls OUT to the TackQuote API. Empty = the feed is
            // switched off entirely (see Api\Product::list()).
            'module_tackquotes_connector_token' => '',
        ];

        foreach ($fields as $key => $default) {
            $data[$key] = $this->config->get($key) !== null && $this->config->get($key) !== ''
                ? $this->config->get($key)
                : $default;
        }

        // Never echo the real stored key back into the HTML value attribute;
        // show a masked placeholder instead, same convention as the
        // PrestaShop module's maskedApiKey().
        $storedKey = (string) $this->config->get('module_tackquotes_api_key');
        $data['module_tackquotes_api_key_masked'] = self::mask($storedKey);

        $storedToken = (string) $this->config->get('module_tackquotes_connector_token');
        $data['module_tackquotes_connector_token_masked'] = self::mask($storedToken);
        $data['module_tackquotes_connector_url'] = HTTP_CATALOG . 'index.php?route=extension/tack/api/product.list';

        // UNCONDITIONAL now that this method only ever serves a GET render. The
        // $fields loop above reads both secrets out of config, and neither may
        // reach the view: the template renders `value=""` literally for both
        // password inputs and shows only the masked forms above. Blanking them
        // here keeps that invariant true even if someone later binds these keys
        // in the template by mistake.
        $data['module_tackquotes_api_key'] = '';
        $data['module_tackquotes_connector_token'] = '';

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    /**
     * Persists settings via OpenCart's standard `oc_setting` table
     * (model_setting_setting::editSetting), keeping the previously stored API
     * key when the (masked) field is submitted unchanged.
     */
    /**
     * The setting group is `module_tackquotes` — matching the module CODE, with the `s`.
     *
     * This is not cosmetic. OpenCart builds the module list in Design > Layouts from
     * installed extensions, and includes a single-instance module only when a setting
     * named `module_<code>_status` exists:
     *
     *   if ($this->config->has('module_' . $extension['code'] . '_status') || $module_data)
     *   — admin/controller/design/layout.php:262 (4.1.0.4)
     *
     * The code here is `tackquotes` (from this controller's filename), so the group must be
     * `module_tackquotes`. Until 1.1.1 it was `module_tackquote`, and the consequence was
     * invisible from the admin: the extension installed, the module installed, the settings
     * screen saved and reported success, `Status = Enabled` persisted — and the module
     * never appeared in Design > Layouts, so the storefront button could not be placed at
     * all. Renaming this group without renaming the controller (or vice versa) reintroduces
     * exactly that failure.
     */
    private function model_setting_setting_save(): void
    {
        $this->load->model('setting/setting');

        $apiKey = $this->keepOrReplace('module_tackquotes_api_key');
        $connectorToken = $this->keepOrReplace('module_tackquotes_connector_token');

        $this->model_setting_setting->editSetting('module_tackquotes', [
            'module_tackquotes_status' => (int) ($this->request->post['module_tackquotes_status'] ?? 0),
            'module_tackquotes_api_url' => rtrim((string) ($this->request->post['module_tackquotes_api_url'] ?? ''), '/'),
            'module_tackquotes_api_key' => $apiKey,
            'module_tackquotes_button_label' => (string) ($this->request->post['module_tackquotes_button_label'] ?? 'Request a Quote'),
            'module_tackquotes_add_label' => (string) ($this->request->post['module_tackquotes_add_label'] ?? 'Add to Quote'),
            'module_tackquotes_inline_button' => (int) ($this->request->post['module_tackquotes_inline_button'] ?? 0),
            'module_tackquotes_quote_list' => (int) ($this->request->post['module_tackquotes_quote_list'] ?? 0),
            'module_tackquotes_listing_button' => (int) ($this->request->post['module_tackquotes_listing_button'] ?? 0),
            'module_tackquotes_connector_token' => $connectorToken,
        ]);
    }

    /**
     * Secret fields are rendered masked, so an unchanged (or empty) submission
     * must keep the stored value rather than blanking it. Submitting the
     * literal string `-` clears the secret, which is the only way to switch the
     * inbound feed back off once a token has been set.
     */
    private function keepOrReplace(string $key): string
    {
        $previous = (string) $this->config->get($key);
        $submitted = trim((string) ($this->request->post[$key] ?? ''));

        if ($submitted === '-') {
            return '';
        }

        if ($submitted === '' || $submitted === self::mask($previous)) {
            return $previous;
        }

        return $submitted;
    }

    /** Never echo a stored secret back into HTML; show only its last 4 chars. */
    private static function mask(string $secret): string
    {
        return $secret !== '' ? str_repeat('•', 8) . substr($secret, -4) : '';
    }

    // NOTE: `$this->model_setting_setting` above is not a declared property —
    // it is populated dynamically by OpenCart's Loader/magic __get() on the
    // base Controller after `$this->load->model('setting/setting')`, the same
    // convention every stock OpenCart module controller relies on.
}
