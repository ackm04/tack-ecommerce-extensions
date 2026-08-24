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
require_once dirname(__FILE__) . '/classes/TackQuoteOnlyMode.php';

class TackQuotes extends Module
{
    public const CONFIG_API_URL = 'TACKQUOTES_API_URL';
    public const CONFIG_API_KEY = 'TACKQUOTES_API_KEY';
    public const CONFIG_BUTTON_LABEL = 'TACKQUOTES_BUTTON_LABEL';
    public const CONFIG_ENABLE_WIDGET = 'TACKQUOTES_ENABLE_WIDGET';
    public const CONFIG_QUOTE_ONLY = 'TACKQUOTES_QUOTE_ONLY';
    public const CONFIG_QUOTE_ONLY_SCOPE = 'TACKQUOTES_QUOTE_ONLY_SCOPE';
    public const CONFIG_QUOTE_ONLY_GROUPS = 'TACKQUOTES_QUOTE_ONLY_GROUPS';
    public const CONFIG_QUOTE_ONLY_PRICES = 'TACKQUOTES_QUOTE_ONLY_PRICES';

    public const DEFAULT_API_URL = 'https://api.tackquote.com/v1';
    public const DEFAULT_BUTTON_LABEL = 'Request a Quote';

    /**
     * Front-office pages quote-only deliberately does NOT touch.
     *
     * Reusing core catalog mode (see hookActionFrontControllerInitBefore) buys us
     * core's own enforcement, but core also uses the same flag to bar the
     * post-purchase account pages: HistoryController.php:48,
     * OrderDetailController.php:157, OrderFollowController.php:105,
     * OrderSlipController.php:44 and OrderReturnController.php:87 all redirect
     * away under Configuration::isCatalogMode() (PrestaShop 8.2 line numbers).
     * Turning quote-only on must not retroactively hide the invoices and order
     * history of customers who bought while the shop was still selling, so the
     * request-scoped flag is simply not set on those pages. `php_self` is a class
     * property default on each controller, so it is readable before init() runs.
     */
    public const POST_PURCHASE_PAGES = [
        'history',
        'order-detail',
        'order-follow',
        'order-slip',
        'order-return',
    ];

    /**
     * True once hookActionFrontControllerInitBefore has decided this request is
     * quote-only. Hook::exec resolves a module through Module::getInstanceByName(),
     * which caches one instance per request, so every later hook on this page sees
     * the same value.
     *
     * @var bool
     */
    protected $quoteOnlyRequest = false;

    /**
     * True once the quote CTA has been emitted on this page. Guards against the
     * widget rendering twice (duplicate DOM ids would break the modal) and drives
     * the displayFooter safety net.
     *
     * @var bool
     */
    protected $quoteWidgetRendered = false;

    public function __construct()
    {
        $this->name = 'tackquotes';
        $this->tab = 'front_office_features';
        $this->version = '1.3.0';
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
            'Add a "Request a Quote" button to your product pages and connect this store to your TackQuote B2B quoting account.',
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

        // Quote-only mode disables the cart. If the thing that replaces the cart
        // cannot render, TackQuoteOnlyMode::applies() refuses to engage the mode at
        // all (ctaReady) — the store keeps selling rather than becoming a store that
        // can do neither. Say so here, or the merchant sees a switch that is on and
        // a storefront that ignores it.
        if (Configuration::get(self::CONFIG_QUOTE_ONLY) && !$this->isQuoteCtaReady()) {
            $this->warning = $this->trans(
                'Quote-only mode is switched on but inactive: it needs both an API key and the "Request a Quote" button enabled, otherwise disabling the cart would leave shoppers with no way to transact.',
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
            // Quote-only ships OFF. Installing a module must never change what a
            // storefront is allowed to do.
            && Configuration::updateValue(self::CONFIG_QUOTE_ONLY, 0)
            && Configuration::updateValue(self::CONFIG_QUOTE_ONLY_SCOPE, TackQuoteOnlyMode::SCOPE_EVERYONE)
            && Configuration::updateValue(self::CONFIG_QUOTE_ONLY_GROUPS, '')
            && Configuration::updateValue(self::CONFIG_QUOTE_ONLY_PRICES, 1)
            && $this->registerHook('displayProductActions')
            // The quote CTA's fallback homes. See hookDisplayProductAdditionalInfo()
            // for why displayProductActions alone is not survivable.
            && $this->registerHook('displayProductAdditionalInfo')
            && $this->registerHook('displayFooter')
            && $this->registerHook('actionFrontControllerInitBefore')
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

        // Only our own keys. PS_CATALOG_MODE is deliberately absent: this module
        // never writes it (see hookActionFrontControllerInitBefore), so deleting or
        // resetting it here would destroy a setting that belongs to the merchant.
        return Configuration::deleteByName(self::CONFIG_API_URL)
            && Configuration::deleteByName(self::CONFIG_API_KEY)
            && Configuration::deleteByName(self::CONFIG_BUTTON_LABEL)
            && Configuration::deleteByName(self::CONFIG_ENABLE_WIDGET)
            && Configuration::deleteByName(self::CONFIG_QUOTE_ONLY)
            && Configuration::deleteByName(self::CONFIG_QUOTE_ONLY_SCOPE)
            && Configuration::deleteByName(self::CONFIG_QUOTE_ONLY_GROUPS)
            && Configuration::deleteByName(self::CONFIG_QUOTE_ONLY_PRICES);
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
        $quoteOnly = (int) Tools::getValue(self::CONFIG_QUOTE_ONLY);
        $quoteOnlyScope = TackQuoteOnlyMode::normalizeScope(Tools::getValue(self::CONFIG_QUOTE_ONLY_SCOPE));
        $quoteOnlyGroups = $this->submittedQuoteOnlyGroupIds();
        $quoteOnlyPrices = (int) Tools::getValue(self::CONFIG_QUOTE_ONLY_PRICES);

        $errors = [];
        if ($apiUrl === '' || !Validate::isAbsoluteUrl($apiUrl)) {
            $errors[] = $this->trans('Please enter a valid TackQuote API URL (e.g. https://api.tackquote.com/v1).', [], 'Modules.Tackquotes.Admin');
        }
        if ($buttonLabel === '') {
            $errors[] = $this->trans('Button label cannot be empty.', [], 'Modules.Tackquotes.Admin');
        }

        // Refuse the combination rather than saving a switch that silently does
        // nothing. Quote-only removes "Add to cart"; if the quote button is off, or
        // no API key exists for it to post to, saving this would describe a store
        // where a shopper can neither buy nor ask.
        $keyAfterSave = ($apiKey !== '' && $apiKey !== $this->maskedApiKey())
            ? $apiKey
            : (string) Configuration::get(self::CONFIG_API_KEY);

        if ($quoteOnly && ($keyAfterSave === '' || !$enableWidget)) {
            $errors[] = $this->trans(
                'Quote-only mode needs a saved TackQuote API key and the "Request a Quote" button switched on — otherwise disabling "Add to cart" would leave shoppers with no way to transact.',
                [],
                'Modules.Tackquotes.Admin'
            );
        }

        // An empty group list under the "specific groups" scope is a half-finished
        // configuration, not "everyone". TackQuoteOnlyMode::applies() already refuses
        // to engage on it; say so at save time instead of letting it look applied.
        if ($quoteOnly && $quoteOnlyScope === TackQuoteOnlyMode::SCOPE_GROUPS && empty($quoteOnlyGroups)) {
            $errors[] = $this->trans(
                'Select at least one customer group, or change the scope to "Everyone".',
                [],
                'Modules.Tackquotes.Admin'
            );
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
        Configuration::updateValue(self::CONFIG_QUOTE_ONLY, $quoteOnly);
        Configuration::updateValue(self::CONFIG_QUOTE_ONLY_SCOPE, $quoteOnlyScope);
        Configuration::updateValue(self::CONFIG_QUOTE_ONLY_GROUPS, implode(',', $quoteOnlyGroups));
        Configuration::updateValue(self::CONFIG_QUOTE_ONLY_PRICES, $quoteOnlyPrices);

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
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Quote-only (B2B catalog) mode', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_QUOTE_ONLY,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'quote_only_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Tackquotes.Admin')],
                            ['id' => 'quote_only_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Tackquotes.Admin')],
                        ],
                        'desc' => $this->trans('Turns the storefront into a B2B catalog: "Add to cart" and checkout are refused by the server and shoppers request a quote instead. Your own Shop Parameters > Product Settings > Catalog mode setting is never written to — this applies per request, only to the shoppers selected below.', [], 'Modules.Tackquotes.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Apply quote-only to', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_QUOTE_ONLY_SCOPE,
                        'options' => [
                            'query' => [
                                ['id' => TackQuoteOnlyMode::SCOPE_EVERYONE, 'name' => $this->trans('Everyone', [], 'Modules.Tackquotes.Admin')],
                                ['id' => TackQuoteOnlyMode::SCOPE_GUESTS, 'name' => $this->trans('Guests only (signed-in customers keep the cart)', [], 'Modules.Tackquotes.Admin')],
                                ['id' => TackQuoteOnlyMode::SCOPE_GROUPS, 'name' => $this->trans('Specific customer groups', [], 'Modules.Tackquotes.Admin')],
                            ],
                            'id' => 'id',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('"Guests only" is the usual B2B setup: approved buyers you have put in a group can still order, everyone else has to ask for a quote.', [], 'Modules.Tackquotes.Admin'),
                    ],
                    [
                        'type' => 'checkbox',
                        'label' => $this->trans('Customer groups', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_QUOTE_ONLY_GROUPS,
                        'values' => [
                            'query' => $this->quoteOnlyGroupChoices(),
                            'id' => 'id_group',
                            'name' => 'name',
                        ],
                        'desc' => $this->trans('Only read when the scope above is "Specific customer groups". A visitor in any ticked group gets the quote-only storefront.', [], 'Modules.Tackquotes.Admin'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Keep prices visible in quote-only mode', [], 'Modules.Tackquotes.Admin'),
                        'name' => self::CONFIG_QUOTE_ONLY_PRICES,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'quote_only_prices_on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.Tackquotes.Admin')],
                            ['id' => 'quote_only_prices_off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.Tackquotes.Admin')],
                        ],
                        'desc' => $this->trans('PrestaShop hides prices whenever catalog mode is on unless "with prices" is also on. Leave this enabled to keep list prices on show; disable it for a price-on-request catalog.', [], 'Modules.Tackquotes.Admin'),
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
            self::CONFIG_QUOTE_ONLY => (int) Configuration::get(self::CONFIG_QUOTE_ONLY),
            self::CONFIG_QUOTE_ONLY_SCOPE => TackQuoteOnlyMode::normalizeScope(Configuration::get(self::CONFIG_QUOTE_ONLY_SCOPE)),
            self::CONFIG_QUOTE_ONLY_PRICES => (int) Configuration::get(self::CONFIG_QUOTE_ONLY_PRICES),
        ];

        // HelperForm renders a 'checkbox' input as one field per row named
        // "<input name>_<row id>" and reads its checked state from the matching
        // fields_value key (admin/themes/default/template/helpers/form/form.tpl:510-514
        // in PrestaShop 8.2). Nothing populates those keys automatically.
        $selectedGroups = TackQuoteOnlyMode::parseGroupIds(Configuration::get(self::CONFIG_QUOTE_ONLY_GROUPS));
        foreach ($this->quoteOnlyGroupChoices() as $group) {
            $helper->fields_value[self::CONFIG_QUOTE_ONLY_GROUPS . '_' . (int) $group['id_group']] =
                in_array((int) $group['id_group'], $selectedGroups, true) ? 1 : 0;
        }

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
        // THE COUPLING THAT MUST NOT BITE US.
        //
        // In the classic theme this hook is called from
        // themes/classic/templates/catalog/_partials/product-add-to-cart.tpl:64,
        // and line 26 of that same file wraps everything from there down in
        // `{if !$configuration.is_catalog}`. So the moment the cart is disabled the
        // theme stops calling this hook at all — removing "Add to cart" would take
        // the quote button with it, exactly the way the WooCommerce plugin's button
        // hung off the add-to-cart form.
        //
        // The fix is not to render here anyway (a custom theme may well still call
        // this hook in catalog mode, and we would then emit the widget twice with
        // duplicate DOM ids). It is to publish the widget on a hook that survives:
        // hookDisplayProductAdditionalInfo() below, with hookDisplayFooter() as a
        // last-resort net. Exactly one of the three ever renders.
        if ($this->quoteOnlyRequest) {
            return '';
        }

        return $this->renderQuoteWidget($params);
    }

    /**
     * Storefront "Request a Quote" button in quote-only mode.
     *
     * Hook: displayProductAdditionalInfo — called from
     * themes/classic/templates/catalog/_partials/product-additional-info.tpl:26,
     * which product.tpl:126-128 includes INSIDE the add-to-cart <form> but OUTSIDE
     * the `{if !$configuration.is_catalog}` guard. That is what makes it the right
     * home for the CTA once the cart is gone: nothing in core suppresses it in
     * catalog mode. (Verified against PrestaShop 8.2 source, not against docs.)
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayProductAdditionalInfo($params)
    {
        if (!$this->quoteOnlyRequest) {
            return '';
        }

        return $this->renderQuoteWidget($params);
    }

    /**
     * Last-resort safety net for themes that render neither hook above.
     *
     * Both product-page hooks are emitted by TEMPLATES, so a theme is free not to
     * call them. If that theme also honours `$configuration.is_catalog` — which it
     * must, or the cart button stays visible — the shopper would be left with no
     * cart and no quote CTA. A footer button is poor placement, and that is the
     * point: it is the visible symptom of a theme that needs a template tweak, not
     * a silent dead end. Renders only if nothing else on this page already did.
     *
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayFooter($params)
    {
        if (!$this->quoteOnlyRequest || $this->quoteWidgetRendered) {
            return '';
        }
        if (!($this->context->controller instanceof ProductController)) {
            return '';
        }

        // ProductController::$product is protected, so the id comes from the request
        // the same way ProductController::init() reads it
        // (controllers/front/ProductController.php:127).
        return $this->renderQuoteWidget(
            ['product' => ['id_product' => (int) Tools::getValue('id_product')]],
            true
        );
    }

    /**
     * Render the quote widget once per page.
     *
     * @param array $params hook params, includes 'product'
     * @param bool $isFallback rendered by the displayFooter net rather than in place
     *
     * @return string
     */
    protected function renderQuoteWidget($params, $isFallback = false)
    {
        if ($this->quoteWidgetRendered) {
            return '';
        }
        if (!$this->isQuoteCtaReady()) {
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
            'tackquotes_quote_only' => (bool) $this->quoteOnlyRequest,
            'tackquotes_fallback_placement' => (bool) $isFallback,
        ]);

        $this->quoteWidgetRendered = true;

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

    /**
     * QUOTE-ONLY ENFORCEMENT. This is the guard.
     *
     * Hook: actionFrontControllerInitBefore — the first statement of
     * FrontController::init() (classes/controller/FrontController.php:264-269 in
     * PrestaShop 8.2), so it runs before any controller does its own work, and long
     * after config/config.inc.php:272 has put the visitor's Customer on the context.
     *
     * Two things happen here, in this order:
     *
     * 1. A cart-mutating request is REFUSED outright. This is the answer to a
     *    crafted POST/GET: the theme is irrelevant, hiding a button is irrelevant,
     *    the request dies here without CartController ever running updateCart().
     *
     * 2. PrestaShop's own catalog mode is switched on FOR THIS REQUEST ONLY, which
     *    hands us core's whole enforcement surface for free — Cart::updateQty()
     *    refusing at classes/Cart.php:1563-1569, Cart::checkQuantities() at
     *    classes/Cart.php:4120, CartController::initContent() redirecting the cart
     *    page at controllers/front/CartController.php:99, the ajax cart endpoints at
     *    :126 and :166, and checkout itself at
     *    controllers/front/OrderController.php:246.
     *
     * `Configuration::set()` is the right tool and not a hack: its own docblock
     * reads "Set TEMPORARY a single configuration value"
     * (classes/Configuration.php:369-376) and its body writes only the in-memory
     * caches — no Db call anywhere in classes/Configuration.php:377-406. The
     * persistent setting a merchant edits in Shop Parameters > Product Settings is
     * written by Configuration::updateValue(), which this module never calls for
     * PS_CATALOG_MODE. See the README for why driving the real setting was rejected.
     *
     * @param array $params hook params, includes 'controller'
     */
    public function hookActionFrontControllerInitBefore($params)
    {
        // Belt and braces: this hook is front-office only, but if it were ever
        // reached with the back office bootstrapped, flipping catalog mode in the
        // request that renders Shop Parameters would show the merchant a switch
        // position they never chose.
        if (defined('_PS_ADMIN_DIR_')) {
            return;
        }

        $controller = isset($params['controller']) ? $params['controller'] : null;
        if (!is_object($controller)) {
            return;
        }

        if (!$this->isQuoteOnlyActive()) {
            return;
        }

        $page = isset($controller->php_self) ? (string) $controller->php_self : '';
        if (in_array($page, self::POST_PURCHASE_PAGES, true)) {
            return;
        }

        if ($page === 'cart' && TackQuoteOnlyMode::isCartMutationRequest($_GET, $_POST)) {
            $this->refuseCartMutation();
            // refuseCartMutation() never returns.
        }

        $this->quoteOnlyRequest = true;

        // Written at both scopes because Configuration::get() resolves shop-scoped
        // before global (classes/Configuration.php:246-252) and a shop may hold a
        // shop-level PS_CATALOG_MODE row that a global-only override would lose to.
        Configuration::set('PS_CATALOG_MODE', 1);
        Configuration::set('PS_CATALOG_MODE', 1, 0, 0);

        // PrestaShop hides every price in catalog mode unless this second flag is on
        // (src/Core/Product/ProductPresentationSettings.php:46). A B2B catalog that
        // still shows list prices is the common case, so the merchant chooses.
        $withPrices = (int) Configuration::get(self::CONFIG_QUOTE_ONLY_PRICES) ? 1 : 0;
        Configuration::set('PS_CATALOG_MODE_WITH_PRICES', $withPrices);
        Configuration::set('PS_CATALOG_MODE_WITH_PRICES', $withPrices, 0, 0);
    }

    /**
     * Refuse a cart mutation. Does not return.
     */
    protected function refuseCartMutation()
    {
        $message = $this->trans(
            'This store is quote-only, so products cannot be added to a cart. Use "Request a Quote" on the product page instead.',
            [],
            'Modules.Tackquotes.Shop'
        );

        if (Tools::getValue('ajax')) {
            // CartController's own ajax error envelope
            // (controllers/front/CartController.php:154-158), so the theme's existing
            // cart JS renders the message instead of failing silently.
            header('Content-Type: application/json', true, 403);
            echo json_encode(['hasError' => true, 'errors' => [$message], 'quantity' => 0]);
            exit;
        }

        // Non-ajax: send them back to the product without performing the mutation.
        // This mirrors what core does for the same situation
        // (controllers/front/CartController.php:99-101) rather than serving a 403
        // page to a shopper whose only mistake was a page cached from before the
        // merchant flipped the switch.
        $idProduct = (int) Tools::getValue('id_product');
        $url = $idProduct
            ? $this->context->link->getProductLink($idProduct)
            : $this->context->link->getPageLink('index');

        Tools::redirect($url);
        exit;
    }

    /**
     * Is quote-only mode active for the visitor making this request?
     *
     * @return bool
     */
    protected function isQuoteOnlyActive()
    {
        $customer = isset($this->context->customer) ? $this->context->customer : null;
        $isLogged = is_object($customer) && $customer->isLogged();

        return TackQuoteOnlyMode::applies(
            [
                'enabled' => (bool) (int) Configuration::get(self::CONFIG_QUOTE_ONLY),
                'ctaReady' => $this->isQuoteCtaReady(),
                'scope' => Configuration::get(self::CONFIG_QUOTE_ONLY_SCOPE),
                'groups' => Configuration::get(self::CONFIG_QUOTE_ONLY_GROUPS),
            ],
            $isLogged,
            $this->currentCustomerGroupIds(),
            $this->isEmployeePreview()
        );
    }

    /**
     * Can the quote CTA actually render right now?
     *
     * Mirrors exactly the two conditions renderQuoteWidget() enforces. If this is
     * false, quote-only must not engage — see TackQuoteOnlyMode::applies().
     *
     * @return bool
     */
    protected function isQuoteCtaReady()
    {
        return (bool) (int) Configuration::get(self::CONFIG_ENABLE_WIDGET)
            && (string) Configuration::get(self::CONFIG_API_KEY) !== '';
    }

    /**
     * Every customer group the current visitor belongs to.
     *
     * Customer::getGroupsStatic(0) answers with PS_UNIDENTIFIED_GROUP for a visitor
     * who is not signed in (classes/Customer.php:1098-1100), so this is correct for
     * anonymous traffic too and there is no separate branch for it.
     *
     * @return int[]
     */
    protected function currentCustomerGroupIds()
    {
        $customer = isset($this->context->customer) ? $this->context->customer : null;
        $idCustomer = is_object($customer) ? (int) $customer->id : 0;

        return array_map('intval', (array) Customer::getGroupsStatic($idCustomer));
    }

    /**
     * Is this an employee previewing the storefront from the back office?
     *
     * Reproduces core's own product-preview test
     * (controllers/front/ProductController.php:136-142) rather than calling
     * ProductController::isPreview(): that flag is set inside init(), and this class
     * is consulted from actionFrontControllerInitBefore, i.e. before init() runs, so
     * isPreview() would still be false and the exemption would never fire. The
     * comparison is hash_equals rather than core's `==` because there is no reason
     * for a token check here to be timing-variable.
     *
     * @return bool
     */
    protected function isEmployeePreview()
    {
        if ('1' !== Tools::getValue('preview')) {
            return false;
        }

        $expected = Tools::getAdminToken(
            'AdminProducts'
            . (int) Tab::getIdFromClassName('AdminProducts')
            . (int) Tools::getValue('id_employee')
        );

        if (!is_string($expected) || $expected === '') {
            return false;
        }

        return hash_equals($expected, (string) Tools::getValue('adtoken'));
    }

    /**
     * Customer groups offered in the settings form.
     *
     * @return array
     */
    protected function quoteOnlyGroupChoices()
    {
        $groups = Group::getGroups(
            (int) $this->context->language->id,
            (int) $this->context->shop->id
        );

        return is_array($groups) ? $groups : [];
    }

    /**
     * Group ids ticked on the submitted settings form.
     *
     * @return int[]
     */
    protected function submittedQuoteOnlyGroupIds()
    {
        $ids = [];
        foreach ($this->quoteOnlyGroupChoices() as $group) {
            $idGroup = (int) $group['id_group'];
            if (Tools::getValue(self::CONFIG_QUOTE_ONLY_GROUPS . '_' . $idGroup)) {
                $ids[] = $idGroup;
            }
        }

        return $ids;
    }
}
