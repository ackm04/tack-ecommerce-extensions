<?php
/**
 * TackQuote for OpenCart — admin settings controller.
 *
 * Route: extension/tackquote/module/tackquotes (Extensions > Modules >
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

namespace Opencart\Admin\Controller\Extension\Tackquote\Module;

use Opencart\System\Engine\Controller;
use Opencart\System\Library\Tackquote\ApiClient;

class Tackquotes extends Controller
{
    /** @var array */
    private array $error = [];

    public function index(): void
    {
        $this->load->language('extension/tackquote/module/tackquotes');

        $this->document->setTitle($this->language->get('heading_title'));

        if (($this->request->server['REQUEST_METHOD'] ?? '') === 'POST' && $this->validate()) {
            $this->model_setting_setting_save();

            $this->session->data['success'] = $this->language->get('text_success');

            $this->response->redirect($this->url->link('extension/tackquote/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true));
        }

        $data = $this->buildFormData();

        $this->response->setOutput($this->load->view('extension/tackquote/module/tackquotes', $data));
    }

    /**
     * AJAX action: "Test connection" button. Route:
     * extension/tackquote/module/tackquotes.test
     */
    public function test(): void
    {
        $this->load->language('extension/tackquote/module/tackquotes');

        $json = [];

        $apiUrl = (string) $this->request->post['module_tackquote_api_url'];
        $apiKey = (string) $this->request->post['module_tackquote_api_key'];

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
        $this->model_setting_setting->deleteSetting('module_tackquote');
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
                'href' => $this->url->link('extension/tackquote/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true),
            ],
        ];

        $data['action'] = $this->url->link('extension/tackquote/module/tackquotes', 'user_token=' . $this->session->data['user_token'], true);
        $data['test_action'] = $this->url->link('extension/tackquote/module/tackquotes.test', 'user_token=' . $this->session->data['user_token'], true);
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
            'module_tackquote_status' => 0,
            'module_tackquote_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquote_api_key' => '',
            'module_tackquote_button_label' => 'Request a Quote',
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
        $storedKey = (string) $this->config->get('module_tackquote_api_key');
        $data['module_tackquote_api_key_masked'] = $storedKey !== ''
            ? str_repeat('•', 8) . substr($storedKey, -4)
            : '';
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $data['module_tackquote_api_key'] = '';
        }

        $data['header'] = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer'] = $this->load->controller('common/footer');

        return $data;
    }

    private function validate(): bool
    {
        if (!$this->user->hasPermission('modify', 'extension/tackquote/module/tackquotes')) {
            $this->error['warning'] = $this->language->get('error_permission');
        }

        $apiUrl = trim((string) ($this->request->post['module_tackquote_api_url'] ?? ''));
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
    private function model_setting_setting_save(): void
    {
        $this->load->model('setting/setting');

        $previousKey = (string) $this->config->get('module_tackquote_api_key');
        $submittedKey = trim((string) ($this->request->post['module_tackquote_api_key'] ?? ''));
        $maskedKey = $previousKey !== '' ? str_repeat('•', 8) . substr($previousKey, -4) : '';

        $apiKey = ($submittedKey === '' || $submittedKey === $maskedKey) ? $previousKey : $submittedKey;

        $this->model_setting_setting->editSetting('module_tackquote', [
            'module_tackquote_status' => (int) ($this->request->post['module_tackquote_status'] ?? 0),
            'module_tackquote_api_url' => rtrim((string) ($this->request->post['module_tackquote_api_url'] ?? ''), '/'),
            'module_tackquote_api_key' => $apiKey,
            'module_tackquote_button_label' => (string) ($this->request->post['module_tackquote_button_label'] ?? 'Request a Quote'),
        ]);
    }

    // NOTE: `$this->model_setting_setting` above is not a declared property —
    // it is populated dynamically by OpenCart's Loader/magic __get() on the
    // base Controller after `$this->load->model('setting/setting')`, the same
    // convention every stock OpenCart module controller relies on.
}
