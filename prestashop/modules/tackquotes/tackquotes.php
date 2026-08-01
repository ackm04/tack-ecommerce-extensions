<?php
/**
 * TackQuote for PrestaShop
 *
 * Adds a "Request a Quote" button to product pages and connects this store to a
 * TackQuote B2B quoting account (API base URL + API key), mirroring the pattern
 * used by the TackQuote WooCommerce plugin (integrations/wordpress/tack-quotes/).
 *
 * @author    TackQuote
 * @copyright TackQuote
 * @license   GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/TackApiClient.php';

class TackQuotes extends Module
{
    const CONFIG_API_URL = 'TACKQUOTES_API_URL';
    const CONFIG_API_KEY = 'TACKQUOTES_API_KEY';
    const CONFIG_BUTTON_LABEL = 'TACKQUOTES_BUTTON_LABEL';
    const CONFIG_ENABLE_WIDGET = 'TACKQUOTES_ENABLE_WIDGET';

    const DEFAULT_API_URL = 'https://api.tackquote.com/v1';
    const DEFAULT_BUTTON_LABEL = 'Request a Quote';

    public function __construct()
    {
        $this->name = 'tackquotes';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'TackQuote';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = array('min' => '1.7.0.0', 'max' => _PS_VERSION_);

        parent::__construct();

        $this->displayName = $this->l('TackQuote for PrestaShop');
        $this->description = $this->l('Add a "Request a Quote" button to your product pages and sync orders with your TackQuote B2B quoting account.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall TackQuote? Your saved API URL and API key will be removed.');
    }

    /**
     * Module install: default config values + hook registration.
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        return Configuration::updateValue(self::CONFIG_API_URL, self::DEFAULT_API_URL)
            && Configuration::updateValue(self::CONFIG_API_KEY, '')
            && Configuration::updateValue(self::CONFIG_BUTTON_LABEL, self::DEFAULT_BUTTON_LABEL)
            && Configuration::updateValue(self::CONFIG_ENABLE_WIDGET, 1)
            && $this->registerHook('displayProductActions')
            && $this->registerHook('displayHeader');
    }

    /**
     * Module uninstall: remove stored configuration.
     *
     * @return bool
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        return Configuration::deleteByName(self::CONFIG_API_URL)
            && Configuration::deleteByName(self::CONFIG_API_KEY)
            && Configuration::deleteByName(self::CONFIG_BUTTON_LABEL)
            && Configuration::deleteByName(self::CONFIG_ENABLE_WIDGET);
    }

    /**
     * Admin "Configure" screen: connection settings + storefront button toggle,
     * plus a "Test connection" action. Mirrors Tack_Settings in the WooCommerce
     * plugin (includes/class-tack-settings.php).
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitTackQuotesSettings')) {
            $output .= $this->processSettingsForm();
        } elseif (Tools::isSubmit('submitTackQuotesTestConnection')) {
            $output .= $this->processTestConnection();
        }

        return $output . $this->renderForm();
    }

    /**
     * Validate + persist the settings form.
     *
     * @return string HTML notice.
     */
    protected function processSettingsForm()
    {
        $apiUrl = rtrim((string) Tools::getValue(self::CONFIG_API_URL), '/');
        $apiKey = trim((string) Tools::getValue(self::CONFIG_API_KEY));
        $buttonLabel = (string) Tools::getValue(self::CONFIG_BUTTON_LABEL);
        $enableWidget = (int) Tools::getValue(self::CONFIG_ENABLE_WIDGET);

        $errors = array();
        if ($apiUrl === '' || !Validate::isAbsoluteUrl($apiUrl)) {
            $errors[] = $this->l('Please enter a valid TackQuote API URL (e.g. https://api.tackquote.com/v1).');
        }
        if ($buttonLabel === '') {
            $errors[] = $this->l('Button label cannot be empty.');
        }

        if (!empty($errors)) {
            return $this->displayError(implode('<br />', $errors));
        }

        Configuration::updateValue(self::CONFIG_API_URL, $apiUrl);
        // Only overwrite the stored key if the field was actually changed
        // (the form re-renders a masked placeholder, never the real key).
        if ($apiKey !== '' && $apiKey !== $this->maskedApiKey()) {
            Configuration::updateValue(self::CONFIG_API_KEY, $apiKey);
        }
        Configuration::updateValue(self::CONFIG_BUTTON_LABEL, $buttonLabel);
        Configuration::updateValue(self::CONFIG_ENABLE_WIDGET, $enableWidget);

        return $this->displayConfirmation($this->l('TackQuote settings saved.'));
    }

    /**
     * "Test connection" button handler — pings the Tack API with the saved key.
     *
     * @return string HTML notice.
     */
    protected function processTestConnection()
    {
        $client = new TackApiClient(
            Configuration::get(self::CONFIG_API_URL),
            Configuration::get(self::CONFIG_API_KEY)
        );

        $result = $client->testConnection();

        if ($result === true) {
            return $this->displayConfirmation($this->l('Connected to TackQuote successfully.'));
        }

        return $this->displayError(
            sprintf($this->l('Could not connect to TackQuote: %s'), (string) $result)
        );
    }

    /**
     * @return string Masked representation of the stored API key (for display only).
     */
    protected function maskedApiKey()
    {
        $key = (string) Configuration::get(self::CONFIG_API_KEY);
        if ($key === '') {
            return '';
        }

        return str_repeat('•', 8) . substr($key, -4);
    }

    /**
     * Build + render the settings form (HelperForm) and the "test connection" block.
     *
     * @return string
     */
    protected function renderForm()
    {
        $fieldsForm = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Connection'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('TackQuote API URL'),
                        'name' => self::CONFIG_API_URL,
                        'desc' => $this->l('Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash).'),
                        'required' => true,
                    ),
                    array(
                        'type' => 'password',
                        'label' => $this->l('TackQuote API Key'),
                        'name' => self::CONFIG_API_KEY,
                        'desc' => $this->maskedApiKey()
                            ? sprintf($this->l('Saved key: %s. Leave unchanged to keep it, or paste a new key to replace it.'), $this->maskedApiKey())
                            : $this->l('Create an API key in TackQuote under Settings > Developer > API Keys.'),
                        'required' => false,
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Show "Request a Quote" button on product pages'),
                        'name' => self::CONFIG_ENABLE_WIDGET,
                        'is_bool' => true,
                        'values' => array(
                            array('id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')),
                            array('id' => 'active_off', 'value' => 0, 'label' => $this->l('No')),
                        ),
                        'desc' => $this->l('When enabled, shoppers see a "Request a Quote" button on product pages. Submitting it creates a quote request in TackQuote — it does not add to cart or affect checkout.'),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Button label'),
                        'name' => self::CONFIG_BUTTON_LABEL,
                        'desc' => $this->l('Text shown on the storefront button, e.g. "Request a Quote" or "Get a B2B quote".'),
                        'required' => true,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                    'name' => 'submitTackQuotesSettings',
                ),
            ),
        );

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Context::getContext()->language->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTackQuotesSettings';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value = array(
            self::CONFIG_API_URL => Configuration::get(self::CONFIG_API_URL) ?: self::DEFAULT_API_URL,
            self::CONFIG_API_KEY => '',
            self::CONFIG_BUTTON_LABEL => Configuration::get(self::CONFIG_BUTTON_LABEL) ?: self::DEFAULT_BUTTON_LABEL,
            self::CONFIG_ENABLE_WIDGET => (int) Configuration::get(self::CONFIG_ENABLE_WIDGET),
        );

        $formHtml = $helper->generateForm(array($fieldsForm));

        // "Test connection" block, appended below the HelperForm — mirrors the
        // WooCommerce plugin's separate "Test connection" form.
        $this->context->smarty->assign(array(
            'test_connection_url' => AdminController::$currentIndex . '&configure=' . $this->name
                . '&token=' . Tools::getAdminTokenLite('AdminModules'),
        ));
        $testHtml = $this->fetch('module:tackquotes/views/templates/admin/configure.tpl');

        return $formHtml . $testHtml;
    }

    /**
     * Storefront "Request a Quote" button on the product page.
     * Hook: displayProductActions — the current PS 1.7/8 hook for action
     * buttons near "Add to cart" on the product detail page.
     *
     * @param array $params Hook params, includes 'product'.
     *
     * @return string
     */
    public function hookDisplayProductActions($params)
    {
        if (!(int) Configuration::get(self::CONFIG_ENABLE_WIDGET)) {
            return '';
        }
        if (!Configuration::get(self::CONFIG_API_KEY)) {
            // Not configured yet — don't show a button that can't work.
            return '';
        }

        $product = isset($params['product']) ? $params['product'] : null;
        $productId = 0;
        if (is_array($product) && isset($product['id_product'])) {
            $productId = (int) $product['id_product'];
        } elseif (is_object($product) && isset($product->id)) {
            $productId = (int) $product->id;
        }

        if (!$productId) {
            return '';
        }

        $this->context->smarty->assign(array(
            'tackquotes_product_id' => $productId,
            'tackquotes_button_label' => Configuration::get(self::CONFIG_BUTTON_LABEL) ?: self::DEFAULT_BUTTON_LABEL,
            'tackquotes_ajax_url' => $this->context->link->getModuleLink('tackquotes', 'quoterequest', array(), true),
        ));

        return $this->fetch('module:tackquotes/views/templates/hook/quote_button.tpl');
    }

    /**
     * Enqueue the button's CSS/JS on product pages only.
     *
     * @param array $params
     */
    public function hookDisplayHeader($params)
    {
        if (!(int) Configuration::get(self::CONFIG_ENABLE_WIDGET)) {
            return;
        }
        if (!$this->context->controller instanceof ProductController) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'tackquotes-style',
            'modules/' . $this->name . '/views/css/tackquotes.css'
        );
        $this->context->controller->registerJavascript(
            'tackquotes-script',
            'modules/' . $this->name . '/views/js/tackquotes.js'
        );
    }
}
