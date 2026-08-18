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
 */

namespace Opencart\Admin\Controller\Extension\Tack\Module;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Extension\Tack\ApiClient;

class Tackquotes extends Controller
{
    /** @var array */
    private array $error = [];

    public function index(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $this->document->setTitle($this->language->get('heading_title'));

        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' && $this->validate()) {
            $this->model_setting_setting_save();

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/tack/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true));
        }

        $data = $this->buildFormData();

        $this->response->setOutput($this->load->view('extension/tack/module/tackquotes', $data));
    }

    /**
     * AJAX action: "Test connection" button. Route:
     * extension/tack/module/tackquotes.test
     */
    public function test(): void
    {
        $this->load->language('extension/tack/module/tackquotes');

        $json = [];

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
        $this->response->setOutput(json_encode($json));
    }

    public function install(): void
    {
        // Nothing to seed beyond defaults handled by getSetting() fallbacks
        // in buildFormData(). Present for parity with OpenCart's extension
        // lifecycle (some OpenCart versions call install()/uninstall() when
        // enabling/disabling from Extensions > Extensions).
    }

    public function uninstall(): void
    {
        $this->load->model('setting/setting');
        $this->model_setting_setting->deleteSetting('module_tackquotes');
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

        $data['action'] = $this->url->link('extension/tack/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true);
        $data['test_action'] = $this->url->link('extension/tack/module/tackquotes.test', 'user_token=' . $this->session->data['user_token'], true);
        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $this->session->data['user_token'] . '&type=module', true);
        $data['user_token'] = $this->session->data['user_token'];

        foreach (['error_warning'] as $key) {
            $data[$key] = $this->error[$key] ?? '';
        }

        if (isset($this->session->data['success'])) {
            $data['success'] = $this->session->data['success'];
            unset($this->session->data['success']);
        } else {
            $data['success'] = '';
        }

        $fields = [
            'module_tackquotes_status' => 0,
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_api_key' => '',
            'module_tackquotes_button_label' => 'Request a Quote',
            // Bearer token for the INBOUND direction (TackQuote -> this store),
            // i.e. the catalog/order feed served by
            // catalog/controller/api/{product,order}.php. It is a different
            // secret from module_tackquotes_api_key, which authenticates this
            // store when it calls OUT to the TackQuote API. Empty = the feed is
            // switched off entirely (see Api\Product::list()).
            'module_tackquotes_connector_token' => '',
        ];

        foreach ($fields as $key => $default) {
            if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' && isset($this->request->post[$key])) {
                $data[$key] = $this->request->post[$key];
            } else {
                $data[$key] = $this->config->get($key) !== null && $this->config->get($key) !== ''
                    ? $this->config->get($key)
                    : $default;
            }
        }

        // Never echo the real stored key back into the HTML value attribute;
        // show a masked placeholder instead, same convention as the
        // PrestaShop module's maskedApiKey().
        $storedKey = (string) $this->config->get('module_tackquotes_api_key');
        $data['module_tackquotes_api_key_masked'] = self::mask($storedKey);

        $storedToken = (string) $this->config->get('module_tackquotes_connector_token');
        $data['module_tackquotes_connector_token_masked'] = self::mask($storedToken);
        $data['module_tackquotes_connector_url'] = HTTP_CATALOG . 'index.php?route=extension/tack/api/product.list';

        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $data['module_tackquotes_api_key'] = '';
            $data['module_tackquotes_connector_token'] = '';
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    private function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/tack/module/tackquotes')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        $apiUrl = trim((string) ($this->request->post['module_tackquotes_api_url'] ?? ''));
        if ($apiUrl === '' || !filter_var($apiUrl, FILTER_VALIDATE_URL)) {
            $this->error['warning'] = $this->language->get('error_api_url');
        }

        return !$this->error;
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
