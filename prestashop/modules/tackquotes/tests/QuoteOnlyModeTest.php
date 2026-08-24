<?php

/**
 * Behavioural tests for quote-only / B2B catalog mode.
 *
 * Run: docker run --rm -v "$PWD":/p -w /p php:8.3-cli \
 *          php prestashop/modules/tackquotes/tests/QuoteOnlyModeTest.php
 *
 * These load the REAL TackQuotes class against the stubs in stubs.php and call the
 * real guard, rather than asserting on the text of the source. A grep-shaped test
 * would keep passing with the enforcement deleted as long as the words survived in
 * a comment; these do not.
 *
 * @license GPL-2.0-or-later
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../tackquotes.php';

/** Exposes the two protected flags the hooks coordinate through. */
class TestableTackQuotes extends TackQuotes
{
    public function isQuoteOnlyRequest()
    {
        return $this->quoteOnlyRequest;
    }

    public function isWidgetRendered()
    {
        return $this->quoteWidgetRendered;
    }
}

// ---------------------------------------------------------------------------
// tiny assertion harness
// ---------------------------------------------------------------------------
$passed = 0;
$failures = [];

function check($label, $condition)
{
    global $passed, $failures;
    if ($condition) {
        ++$passed;
    } else {
        $failures[] = $label;
    }
}

/**
 * Build a module with a given configuration and visitor.
 *
 * @return TestableTackQuotes
 */
function makeModule(array $config = [], array $visitor = [])
{
    Configuration::reset();
    Tools::reset();
    Customer::$groupsByCustomer = [];

    Configuration::updateValue('TACKQUOTES_API_URL', 'https://api.tackquote.com/v1');
    Configuration::updateValue('TACKQUOTES_API_KEY', 'live_key');
    Configuration::updateValue('TACKQUOTES_BUTTON_LABEL', 'Request a Quote');
    Configuration::updateValue('TACKQUOTES_ENABLE_WIDGET', 1);
    Configuration::updateValue('TACKQUOTES_QUOTE_ONLY', 0);
    Configuration::updateValue('TACKQUOTES_QUOTE_ONLY_SCOPE', 'everyone');
    Configuration::updateValue('TACKQUOTES_QUOTE_ONLY_GROUPS', '');
    Configuration::updateValue('TACKQUOTES_QUOTE_ONLY_PRICES', 1);

    foreach ($config as $key => $value) {
        Configuration::updateValue($key, $value);
    }

    $module = new TestableTackQuotes();
    $module->context->customer->id = isset($visitor['id']) ? (int) $visitor['id'] : 0;
    $module->context->customer->stubLogged = !empty($visitor['logged']);
    if (isset($visitor['groups'])) {
        Customer::$groupsByCustomer[(int) $module->context->customer->id] = $visitor['groups'];
    }

    return $module;
}

/**
 * Run the guard. Returns 'refused' if the cart mutation was rejected.
 *
 * @return string 'refused'|'allowed'
 */
function runGuard(TestableTackQuotes $module, $phpSelf, array $get = [], array $post = [])
{
    $_GET = $get;
    $_POST = $post;

    try {
        $module->hookActionFrontControllerInitBefore([
            'controller' => new StubFrontController($phpSelf),
        ]);
    } catch (StubRedirect $e) {
        return 'refused';
    }

    return 'allowed';
}

$ON = ['TACKQUOTES_QUOTE_ONLY' => 1];

// ---------------------------------------------------------------------------
// 1. The guard refuses a crafted add-to-cart request.
// ---------------------------------------------------------------------------
check(
    'quote-only OFF: add-to-cart is allowed through',
    runGuard(makeModule(), 'cart', [], ['add' => 1, 'id_product' => 7]) === 'allowed'
);

check(
    'quote-only ON: POSTed add-to-cart is refused',
    runGuard(makeModule($ON), 'cart', [], ['add' => 1, 'id_product' => 7]) === 'refused'
);

check(
    'quote-only ON: GET add-to-cart is refused',
    runGuard(makeModule($ON), 'cart', ['add' => 1, 'id_product' => 7]) === 'refused'
);

check(
    'quote-only ON: cart quantity update is refused',
    runGuard(makeModule($ON), 'cart', [], ['update' => 1, 'op' => 'up', 'id_product' => 7]) === 'refused'
);

check(
    'quote-only ON: removing a product from an existing cart is still allowed',
    runGuard(makeModule($ON), 'cart', [], ['delete' => 1, 'id_product' => 7]) === 'allowed'
);

$module = makeModule($ON);
runGuard($module, 'cart', [], ['add' => 1, 'id_product' => 7]);
check(
    'refusal redirects back to the product it refused',
    Tools::$redirectedTo === 'https://shop.test/product/7'
);

// ---------------------------------------------------------------------------
// 2. Core catalog mode is engaged for the request, never persisted.
// ---------------------------------------------------------------------------
$module = makeModule($ON);
runGuard($module, 'product', ['id_product' => 7]);
check('quote-only ON: request flagged quote-only', $module->isQuoteOnlyRequest() === true);
check('quote-only ON: PS_CATALOG_MODE engaged for the request', Configuration::$temporary['PS_CATALOG_MODE'] == 1);
check(
    'quote-only ON: the merchant PS_CATALOG_MODE row is NOT written',
    !array_key_exists('PS_CATALOG_MODE', Configuration::$persisted)
);
check(
    'keep-prices ON: PS_CATALOG_MODE_WITH_PRICES engaged',
    Configuration::$temporary['PS_CATALOG_MODE_WITH_PRICES'] == 1
);

$module = makeModule($ON + ['TACKQUOTES_QUOTE_ONLY_PRICES' => 0]);
runGuard($module, 'product', ['id_product' => 7]);
check(
    'keep-prices OFF: PS_CATALOG_MODE_WITH_PRICES stays off',
    Configuration::$temporary['PS_CATALOG_MODE_WITH_PRICES'] == 0
);

$module = makeModule();
runGuard($module, 'product', ['id_product' => 7]);
check(
    'quote-only OFF: catalog mode is never touched',
    !array_key_exists('PS_CATALOG_MODE', Configuration::$temporary)
);

// ---------------------------------------------------------------------------
// 3. Post-purchase pages keep working.
// ---------------------------------------------------------------------------
foreach (['history', 'order-detail', 'order-follow', 'order-slip', 'order-return'] as $page) {
    $module = makeModule($ON);
    runGuard($module, $page);
    check(
        'quote-only ON: ' . $page . ' is left alone so past orders stay reachable',
        !array_key_exists('PS_CATALOG_MODE', Configuration::$temporary)
    );
}

// ---------------------------------------------------------------------------
// 4. Employee preview stays exempt.
// ---------------------------------------------------------------------------
$goodToken = Tools::getAdminToken('AdminProducts' . 42 . 3);

$module = makeModule($ON);
Tools::$request = ['preview' => '1', 'adtoken' => $goodToken, 'id_employee' => 3];
check(
    'employee preview: add-to-cart is NOT refused',
    runGuard($module, 'cart', [], ['add' => 1, 'id_product' => 7]) === 'allowed'
);
check('employee preview: catalog mode not engaged', !array_key_exists('PS_CATALOG_MODE', Configuration::$temporary));

$module = makeModule($ON);
Tools::$request = ['preview' => '1', 'adtoken' => 'forged', 'id_employee' => 3];
check(
    'forged preview token: add-to-cart is still refused',
    runGuard($module, 'cart', [], ['add' => 1, 'id_product' => 7]) === 'refused'
);

// ---------------------------------------------------------------------------
// 5. Customer-group scoping.
// ---------------------------------------------------------------------------
$guestsOnly = $ON + ['TACKQUOTES_QUOTE_ONLY_SCOPE' => 'guests'];

check(
    'scope=guests: an anonymous visitor is refused the cart',
    runGuard(makeModule($guestsOnly), 'cart', [], ['add' => 1]) === 'refused'
);
check(
    'scope=guests: a signed-in B2B customer keeps the cart',
    runGuard(
        makeModule($guestsOnly, ['id' => 11, 'logged' => true, 'groups' => [3]]),
        'cart',
        [],
        ['add' => 1]
    ) === 'allowed'
);

$wholesale = $ON + ['TACKQUOTES_QUOTE_ONLY_SCOPE' => 'groups', 'TACKQUOTES_QUOTE_ONLY_GROUPS' => '4'];

check(
    'scope=groups: a member of a selected group is refused the cart',
    runGuard(
        makeModule($wholesale, ['id' => 12, 'logged' => true, 'groups' => [3, 4]]),
        'cart',
        [],
        ['add' => 1]
    ) === 'refused'
);
check(
    'scope=groups: a customer outside the selected groups keeps the cart',
    runGuard(
        makeModule($wholesale, ['id' => 13, 'logged' => true, 'groups' => [3]]),
        'cart',
        [],
        ['add' => 1]
    ) === 'allowed'
);
check(
    'scope=groups with nothing selected: mode does not engage',
    runGuard(
        makeModule($ON + ['TACKQUOTES_QUOTE_ONLY_SCOPE' => 'groups', 'TACKQUOTES_QUOTE_ONLY_GROUPS' => '']),
        'cart',
        [],
        ['add' => 1]
    ) === 'allowed'
);

// ---------------------------------------------------------------------------
// 6. The store is never left unable to transact.
// ---------------------------------------------------------------------------
check(
    'no API key: quote-only refuses to engage, cart keeps working',
    runGuard(makeModule($ON + ['TACKQUOTES_API_KEY' => '']), 'cart', [], ['add' => 1]) === 'allowed'
);
check(
    'quote button switched off: quote-only refuses to engage, cart keeps working',
    runGuard(makeModule($ON + ['TACKQUOTES_ENABLE_WIDGET' => 0]), 'cart', [], ['add' => 1]) === 'allowed'
);

// ---------------------------------------------------------------------------
// 7. The quote CTA survives the cart being disabled.
// ---------------------------------------------------------------------------
$product = ['product' => ['id_product' => 7]];

$module = makeModule();
runGuard($module, 'product', ['id_product' => 7]);
check(
    'normal mode: CTA renders on displayProductActions',
    $module->hookDisplayProductActions($product) !== ''
);
$module2 = makeModule();
runGuard($module2, 'product', ['id_product' => 7]);
check(
    'normal mode: CTA does NOT render on displayProductAdditionalInfo',
    $module2->hookDisplayProductAdditionalInfo($product) === ''
);

$module = makeModule($ON);
runGuard($module, 'product', ['id_product' => 7]);
check(
    'quote-only: displayProductActions stays silent (theme no longer calls it anyway)',
    $module->hookDisplayProductActions($product) === ''
);
check(
    'quote-only: CTA renders on displayProductAdditionalInfo',
    $module->hookDisplayProductAdditionalInfo($product) !== ''
);
check('quote-only: CTA marked as quote-only in the template vars', !empty($module->context->smarty->assigned['tackquotes_quote_only']));
check(
    'quote-only: CTA is not emitted twice',
    $module->hookDisplayProductAdditionalInfo($product) === ''
);

// footer safety net
$module = makeModule($ON);
$module->context->controller = new ProductController('product');
runGuard($module, 'product', ['id_product' => 7]);
$_GET = ['id_product' => 7];
check(
    'quote-only: footer net renders the CTA when no product hook did',
    $module->hookDisplayFooter([]) !== ''
);

$module = makeModule($ON);
$module->context->controller = new ProductController('product');
runGuard($module, 'product', ['id_product' => 7]);
$module->hookDisplayProductAdditionalInfo($product);
check(
    'quote-only: footer net stays silent once the CTA rendered in place',
    $module->hookDisplayFooter([]) === ''
);

$module = makeModule();
$module->context->controller = new ProductController('product');
runGuard($module, 'product', ['id_product' => 7]);
check(
    'normal mode: footer net stays silent',
    $module->hookDisplayFooter([]) === ''
);

// ---------------------------------------------------------------------------
// 8. install() registers everything the above depends on.
// ---------------------------------------------------------------------------
$module = makeModule();
$module->install();
foreach ([
    'actionFrontControllerInitBefore',
    'displayProductActions',
    'displayProductAdditionalInfo',
    'displayFooter',
    'displayHeader',
] as $hook) {
    check('install() registers ' . $hook, in_array($hook, $module->registeredHooks, true));
}

// ---------------------------------------------------------------------------
// 9. TackQuoteOnlyMode pure logic.
// ---------------------------------------------------------------------------
check('normalizeScope: unknown value falls back to everyone', TackQuoteOnlyMode::normalizeScope('nonsense') === 'everyone');
check('normalizeScope: guests survives', TackQuoteOnlyMode::normalizeScope('GUESTS') === 'guests');
check('parseGroupIds: csv', TackQuoteOnlyMode::parseGroupIds('3, 4,4, 0,x') === [3, 4]);
check('parseGroupIds: empty', TackQuoteOnlyMode::parseGroupIds('') === []);
check('isCartMutationRequest: add in GET', TackQuoteOnlyMode::isCartMutationRequest(['add' => 1], []));
check('isCartMutationRequest: update in POST', TackQuoteOnlyMode::isCartMutationRequest([], ['update' => 1]));
check('isCartMutationRequest: delete is not a mutation we block', !TackQuoteOnlyMode::isCartMutationRequest([], ['delete' => 1]));
check('isCartMutationRequest: plain cart view', !TackQuoteOnlyMode::isCartMutationRequest(['action' => 'show'], []));

// ---------------------------------------------------------------------------
echo 'passed: ' . $passed . ', failed: ' . count($failures) . PHP_EOL;
foreach ($failures as $failure) {
    echo '  FAIL: ' . $failure . PHP_EOL;
}
exit(empty($failures) ? 0 : 1);
