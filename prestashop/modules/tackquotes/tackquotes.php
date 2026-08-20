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
    public const CONFIG_API_URL = 'TACKQUOTES_API_URL';
    public const CONFIG_API_KEY = 'TACKQUOTES_API_KEY';
    public const CONFIG_BUTTON_LABEL = 'TACKQUOTES_BUTTON_LABEL';
    public const CONFIG_ENABLE_WIDGET = 'TACKQUOTES_ENABLE_WIDGET';

    public const DEFAULT_API_URL = 'https://api.tackquote.com/v1';
    public const DEFAULT_BUTTON_LABEL = 'Request a Quote';

    public function __construct()
    {
        $this->name = 'tackquotes';
        $this->tab = 'front_office_features';
        $this->version = '1.0.0';
        $this->author = 'TackQuote';
        // 1, not 0: PrestaShop skips constructing the module when rendering the Modules
        // page if this is 0, so `$this->warning` set in this constructor would never be
        // seen. The documented rule is "if your module needs to display a warning message
        // in the Modules page, then you must set this attribute to 1".
        $this->need_instance = 1;
        $this->bootstrap = true;
        // An explicit maximum. `'max' => _PS_VERSION_` is the version currently running,
        // so the compatibility test compares a version against itself and can never fail —
        // it declares compatibility with everything, including majors that do not exist
        // yet. 8.99.99 says what has actually been exercised.
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => '8.99.99'];

        parent::__construct();

        $this->displayName = $this->trans('TackQuote for PrestaShop', [], 'Modules.Tackquotes.Admin');
        $this->description = $this->trans(
            'Add a "Request a Quote" button to your product pages and sync orders with your TackQuote B2B quoting account.',
            [],
            'Modules.Tackquotes.Admin'
        );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to uninstall TackQuote? Your saved API URL and API key will be removed.',
            [],
            'Modules.Tackquotes.Admin'
        );

        // Tell the merchant WHY nothing is showing. The product-page button renders only
        // once an API key is saved, and previously it just returned an empty string — the
        // module installed, reported itself active, and produced no button and no
        // explanation. `$this->warning` is PrestaShop's own mechanism for this and puts
        // the message on the Modules page where the merchant already is.
        //
        // Guarded by need_instance below: PrestaShop only constructs the module for the
        // modules list when that is set, which is what makes this warning reachable.
        if (!Configuration::get(self::CONFIG_API_KEY)) {
            $this->warning = $this->trans(
                'No TackQuote API key is set. The "Request a Quote" button stays hidden until you add one in this module\'s configuration.',
                [],
                'Modules.Tackquotes.Admin'
            );
        }
    }

    /**
     * Opt in to the 1.7.6+ translation system.
     *
     * Without this the module stays on the classic system and the `trans()` wordings
     * below are never discovered by International > Translations, so no catalogue can be
     * generated for them — converting the calls without declaring this would look correct
     * and translate nothing.
     *
     * @return bool
     */
    public function isUsingNewTranslationSystem()
    {
        return true;
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
     * @return string HTML notice
     */
    protected function processSettingsForm()
    {
        $apiUrl = rtrim((string) Tools::getValue(self::CONFIG_API_URL), '/');
        $apiKey = trim((string) Tools::getValue(self::CONFIG_API_KEY));
        $buttonLabel = (string) Tools::getValue(self::CONFIG_BUTTON_LABEL);
        $enableWidget = (int) Tools::getValue(self::CONFIG_ENABLE_WIDGET);

        $errors = [];
        if ($apiUrl === '' || !Validate::isAbsoluteUrl($apiUrl)) {
            $errors[] = $this->trans('Please enter a valid TackQuote API URL (e.g. https://api.tackquote.com/v1).', [], 'Modules.Tackquotes.Admin');
        }
        if ($buttonLabel === '') {
            $errors[] = $this->trans('Button label cannot be empty.', [], 'Modules.Tackquotes.Admin');
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

        return $this->displayConfirmation($this->trans('TackQuote settings saved.', [], 'Modules.Tackquotes.Admin'));
    }

    /**
     * "Test connection" button handler — pings the Tack API with the saved key.
     *
     * @return string HTML notice
     */
    protected function processTestConnection()
    {
        $client = new TackApiClient(
            Configuration::get(self::CONFIG_API_URL),
            Configuration::get(self::CONFIG_API_KEY)
        );

        $result = $client->testConnection();

        if ($result === true) {
            return $this->displayConfirmation($this->trans('Connected to TackQuote successfully.', [], 'Modules.Tackquotes.Admin'));
        }

        return $this->displayError(
            sprintf($this->trans('Could not connect to TackQuote: %s', [], 'Modules.Tackquotes.Admin'), (string) $result)
        );
    }

    /**
     * @return string masked representation of the stored API key (for display only)
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
        $fieldsForm = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Connection', [], 'Modules.Tackquotes.Admin'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('TackQuote API URL', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_API_URL,
                        'desc' => $this->trans('Default is https://api.tackquote.com/v1. Change only if TackQuote support gives you a custom or staging API base URL (include the /v1 path, no trailing slash).', [], 'Modules.Tackquotes.Admin'),
                        'required' => true,
                    ],
                    [
                        'type' => 'password',
                        'label' => $this->trans('TackQuote API Key', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_API_KEY,
                        'desc' => $this->maskedApiKey()
                            ? sprintf($this->trans('Saved key: %s. Leave unchanged to keep it, or paste a new key to replace it.', [], 'Modules.Tackquotes.Admin'), $this->maskedApiKey())
                            : $this->trans('Create an API key in TackQuote under Settings > Developer > API Keys.', [], 'Modules.Tackquotes.Admin'),
                        'required' => false,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Show "Request a Quote" button on product pages', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_ENABLE_WIDGET,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Tackquotes.Admin')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Tackquotes.Admin')],
                        ],
                        'desc' => $this->trans('When enabled, shoppers see a "Request a Quote" button on product pages. Submitting it creates a quote request in TackQuote — it does not add to cart or affect checkout.', [], 'Modules.Tackquotes.Admin'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Button label', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_BUTTON_LABEL,
                        'desc' => $this->trans('Text shown on the storefront button, e.g. "Request a Quote" or "Get a B2B quote".', [], 'Modules.Tackquotes.Admin'),
                        'required' => true,
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Save', [], 'Modules.Tackquotes.Admin'),
                    'name' => 'submitTackQuotesSettings',
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = (int) Context::getContext()->language->id;
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitTackQuotesSettings';
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->fields_value = [
            self::CONFIG_API_URL => Configuration::get(self::CONFIG_API_URL) ?: self::DEFAULT_API_URL,
            self::CONFIG_API_KEY => '',
            self::CONFIG_BUTTON_LABEL => Configuration::get(self::CONFIG_BUTTON_LABEL) ?: self::DEFAULT_BUTTON_LABEL,
            self::CONFIG_ENABLE_WIDGET => (int) Configuration::get(self::CONFIG_ENABLE_WIDGET),
        ];

        $formHtml = $helper->generateForm([$fieldsForm]);

        // "Test connection" block, appended below the HelperForm — mirrors the
        // WooCommerce plugin's separate "Test connection" form.
        $this->context->smarty->assign([
            'test_connection_url' => AdminController::$currentIndex . '&configure=' . $this->name
                . '&token=' . Tools::getAdminTokenLite('AdminModules'),
        ]);
        $testHtml = $this->fetch('module:tackquotes/views/templates/admin/configure.tpl');

        return $formHtml . $testHtml;
    }

    /**
     * Storefront "Request a Quote" button on the product page.
     * Hook: displayProductActions — the current PS 1.7/8 hook for action
     * buttons near "Add to cart" on the product detail page.
     *
     * @param array $params hook params, includes 'product'
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

        // Prefill the name fields for a signed-in customer from the shop's own record.
        // `$this->context->customer` is always a Customer object in a front-office
        // context, but it is only a REAL person once isLogged() is true — a guest gets an
        // empty object whose firstname/lastname would render as ''. Guests therefore get
        // empty inputs, which is the point: nothing about the buyer is guessed.
        $customerFirstName = '';
        $customerLastName = '';

        if (isset($this->context->customer) && $this->context->customer->isLogged()) {
            $customerFirstName = (string) $this->context->customer->firstname;
            $customerLastName = (string) $this->context->customer->lastname;
        }

        $this->context->smarty->assign([
            'tackquotes_product_id' => $productId,
            'tackquotes_button_label' => Configuration::get(self::CONFIG_BUTTON_LABEL) ?: self::DEFAULT_BUTTON_LABEL,
            'tackquotes_ajax_url' => $this->context->link->getModuleLink('tackquotes', 'quoterequest', [], true),
            'tackquotes_customer_firstname' => $customerFirstName,
            'tackquotes_customer_lastname' => $customerLastName,
        ]);

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
