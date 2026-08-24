<?php
/**
 * TackQuote for OpenCart — save-path regression suite.
 *
 *   php integrations/opencart/tests/run.php
 *
 * No composer, no phpunit, no database, no store. The extension ships no
 * dependency manifest and OpenCart itself is not a composer package, so a
 * self-contained runner is the only harness a merchant or a CI job can actually
 * execute against this tree.
 *
 * WHY THIS EXISTS. Three of the defects fixed in 1.2.1 were invisible to manual
 * clicking because the setting still persisted correctly every time:
 *
 *   - `test()` had no permission check at all.
 *   - The error banner read a different array key than validate() wrote, so a
 *     permission denial and an invalid URL both rendered as silence.
 *   - The save path answered a `data-oc-toggle="ajax"` form with a redirect and
 *     full HTML, which OpenCart's common.js fed to a JSON parser and dropped in
 *     a console.log.
 *
 * Every one of them is a statement about the RESPONSE, not about the stored
 * value — which is exactly why clicking Save and then reloading looked fine. So
 * these tests assert on what came back, and on whether anything was written at
 * all. That is the single test that would have caught all three.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

// buildFormData() interpolates this into the connector URL it shows the merchant.
if (!defined('HTTP_CATALOG')) {
    define('HTTP_CATALOG', 'https://store.example/');
}

if (!defined('DB_PREFIX')) {
    define('DB_PREFIX', 'oc_');
}

require __DIR__ . '/opencart-stubs.php';
require $root . '/system/library/api_client.php';
require $root . '/system/library/api_guard.php';
require $root . '/admin/controller/module/tackquotes.php';
require $root . '/catalog/controller/module/tackquotes.php';
require $root . '/catalog/controller/api/product.php';
require $root . '/system/library/quote_only.php';
require $root . '/catalog/controller/quotemode.php';
require $root . '/catalog/controller/event/quote.php';

use Opencart\Admin\Controller\Extension\Tack\Module\Tackquotes;
use Opencart\Catalog\Controller\Extension\Tack\Module\Tackquotes as CatalogTackquotes;
use Opencart\System\Engine\Registry;
use Opencart\Catalog\Controller\Extension\Tack\Api\Product;
use Tack\Test\Config;
use Tack\Test\Db;
use Tack\Test\Document;
use Tack\Test\Language;
use Tack\Test\Loader;
use Tack\Test\Request;
use Tack\Test\Response;
use Tack\Test\Session;
use Tack\Test\SettingModel;
use Tack\Test\Url;
use Tack\Test\User;
use Tack\Test\Customer;
use Tack\Test\EventModel;
use Opencart\Catalog\Controller\Extension\Tack\Quotemode;
use Opencart\Catalog\Controller\Extension\Tack\Event\Quote as QuoteEvent;
use Opencart\System\Library\Extension\Tack\QuoteOnly;

const ROUTE = 'extension/tack/module/tackquotes';

$passed = 0;
$failures = [];

function check(string $name, callable $fn): void
{
    global $passed, $failures;

    try {
        $fn();
        $passed++;
        echo "  ok   $name\n";
    } catch (Throwable $e) {
        $failures[] = $name . ' — ' . $e->getMessage();
        echo "  FAIL $name\n       " . $e->getMessage() . "\n";
    }
}

function assertTrue(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}

function assertSame($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true) . ')');
    }
}

/**
 * Build a controller wired to stubs.
 *
 * @param array<int, string>    $granted  Permissions, as "<type>:<route>".
 * @param array<string, mixed>  $post
 * @param array<string, mixed>  $config
 * @return array{0: Tackquotes, 1: Response, 2: SettingModel}
 */
function makeController(array $granted, array $post = [], array $config = []): array
{
    $registry = new Registry();

    $request = new Request();
    $request->post = $post;
    $request->server['REQUEST_METHOD'] = $post ? 'POST' : 'GET';

    $response = new Response();
    $setting = new SettingModel();
    $setting->stored['module_tackquotes'] = [];

    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('config', new Config($config));
    $registry->set('user', new User($granted));
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('session', new Session());
    $registry->set('document', new Document());
    $registry->set('model_setting_setting', $setting);
    $registry->set('load', new Loader($registry, dirname(__DIR__) . '/admin/language/en-gb/module/tackquotes.php'));

    return [new Tackquotes($registry), $response, $setting];
}

/** Decode a controller response, insisting it really is JSON. */
function decodeJson(Response $response): array
{
    assertSame(null, $response->redirect,
        'the save path issued a redirect; a data-oc-toggle="ajax" form gets that fed to a JSON parser');

    assertTrue(
        in_array('Content-Type: application/json', $response->headers, true),
        'no application/json Content-Type header was sent (headers: ' . implode(' | ', $response->headers) . ')'
    );

    $json = json_decode($response->output, true);

    assertTrue(
        is_array($json),
        'response body is not JSON — common.js parses this with dataType:"json" and silently '
            . 'console.logs a parse failure. Body began: ' . substr($response->output, 0, 120)
    );

    return $json;
}

echo "\nTackQuote for OpenCart — admin save path\n";

// ---------------------------------------------------------------- save(): denial

check('save() refuses a user without modify permission', function () {
    [$controller, $response, $setting] = makeController([], [
        'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
        'module_tackquotes_status'   => '1',
    ]);

    $controller->save();
    $json = decodeJson($response);

    assertTrue(isset($json['error']['warning']),
        'no error.warning key — this is the key OpenCart common.js renders into #alert, '
            . 'and its absence is what made permission denials completely silent');
    assertTrue(!isset($json['success']), 'a denied save must not report success');
    assertSame([], $setting->writes, 'a denied save must not write any setting');
});

check('save() denial message is a real language string, not a key echo', function () {
    [$controller, $response] = makeController([]);
    $controller->save();
    $json = decodeJson($response);

    assertTrue(
        $json['error']['warning'] !== 'error_permission' && $json['error']['warning'] !== '',
        'error.warning contains the raw language KEY, so the language file is missing error_permission'
    );
});

// ------------------------------------------------------------ save(): validation

check('save() rejects an invalid API URL against the field, not into the void', function () {
    [$controller, $response, $setting] = makeController(['modify:' . ROUTE], [
        'module_tackquotes_api_url' => 'not a url',
    ]);

    $controller->save();
    $json = decodeJson($response);

    assertTrue(isset($json['error']['api_url']),
        'no error.api_url key; common.js maps it to #error-api-url, so without it a bad URL is silent');
    assertTrue(!isset($json['success']), 'an invalid URL must not report success');
    assertSame([], $setting->writes, 'an invalid URL must not be persisted');
});

check('save() rejects an empty API URL', function () {
    [$controller, $response, $setting] = makeController(['modify:' . ROUTE], [
        'module_tackquotes_status' => '1',
    ]);

    $controller->save();
    $json = decodeJson($response);

    assertTrue(isset($json['error']['api_url']), 'an empty API URL must be reported');
    assertSame([], $setting->writes, 'an empty API URL must not be persisted');
});

// --------------------------------------------------------------- save(): success

check('save() persists and confirms in JSON', function () {
    [$controller, $response, $setting] = makeController(['modify:' . ROUTE], [
        'module_tackquotes_api_url'        => 'https://api.tackquote.com/v1/',
        'module_tackquotes_api_key'        => 'sk_live_abcdef123456',
        'module_tackquotes_status'         => '1',
        'module_tackquotes_listing_button' => '0',
    ]);

    $controller->save();
    $json = decodeJson($response);

    assertTrue(!isset($json['error']), 'a valid save must not report an error');
    assertTrue(isset($json['success']) && $json['success'] !== 'text_success',
        'no success message; the merchant would see nothing at all on a good save');

    assertSame(1, count($setting->writes), 'expected exactly one editSetting() call');

    $write = $setting->writes[0];
    assertSame('module_tackquotes', $write['group'],
        'the setting group must stay module_tackquotes — Design > Layouts finds the module by '
            . 'module_<code>_status, so renaming it hides the module from layout assignment');
    assertSame('https://api.tackquote.com/v1', $write['values']['module_tackquotes_api_url'],
        'the trailing slash should be trimmed');
    assertSame('sk_live_abcdef123456', $write['values']['module_tackquotes_api_key'], 'the new key should be stored');
    assertSame(1, $write['values']['module_tackquotes_status'], 'status should be cast to int 1');
    assertSame(0, $write['values']['module_tackquotes_listing_button'], 'listing_button should be cast to int 0');
});

check('save() keeps the stored secret when the field is submitted blank', function () {
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url'         => 'https://api.tackquote.com/v1',
            'module_tackquotes_api_key'         => '',
            'module_tackquotes_connector_token' => '',
        ],
        [
            'module_tackquotes_api_key'         => 'sk_live_existing_key',
            'module_tackquotes_connector_token' => 'feed_token_existing',
        ]
    );

    $controller->save();
    decodeJson($response);

    $values = $setting->writes[0]['values'];
    assertSame('sk_live_existing_key', $values['module_tackquotes_api_key'],
        'a blank secret field must keep the stored value, not blank it — the input renders empty by design');
    assertSame('feed_token_existing', $values['module_tackquotes_connector_token'],
        'a blank feed token must keep the stored value');
});

check('save() clears a secret only on an explicit single dash', function () {
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url'         => 'https://api.tackquote.com/v1',
            'module_tackquotes_connector_token' => '-',
        ],
        ['module_tackquotes_connector_token' => 'feed_token_existing']
    );

    $controller->save();
    decodeJson($response);

    assertSame('', $setting->writes[0]['values']['module_tackquotes_connector_token'],
        'a single dash is the documented way to switch the inbound feed back off');
});

// ---------------------------------------------------------------------- test()

echo "\nTackQuote for OpenCart — admin test-connection action\n";

check('test() refuses a user without modify permission, and makes no outbound call', function () {
    // A real API URL/key are configured, so if the guard were missing this would
    // attempt a network call — which is precisely the abuse being prevented.
    [$controller, $response] = makeController([], ['module_tackquotes_api_url' => 'https://api.tackquote.com/v1'], [
        'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
        'module_tackquotes_api_key' => 'sk_live_should_never_be_spent',
    ]);

    $controller->test();
    $json = decodeJson($response);

    assertTrue(isset($json['error']) && is_string($json['error']),
        'test() must answer a flat string error — that is the shape the button handler renders');
    assertTrue(!isset($json['success']), 'an unauthorised test must not report a successful connection');
    assertTrue(
        strpos($json['error'], 'permission') !== false,
        'the error should say it is a permission problem, got: ' . $json['error']
    );
});

check('test() still reports a missing API key for an authorised user', function () {
    [$controller, $response] = makeController(['modify:' . ROUTE], ['module_tackquotes_api_url' => 'https://api.tackquote.com/v1']);

    $controller->test();
    $json = decodeJson($response);

    assertTrue(isset($json['error']), 'with no key configured, test() should report the missing key');
    assertTrue(strpos($json['error'], 'permission') === false,
        'an authorised user must not be told this is a permission problem');
});

// ---------------------------------------------------------------------- index()

echo "\nTackQuote for OpenCart — settings page render\n";

check('index() renders without a redirect and never leaks a stored secret', function () {
    $registry = new Registry();
    $request = new Request();
    $response = new Response();
    $config = new Config([
        'module_tackquotes_api_key'         => 'sk_live_TOPSECRET_abcd',
        'module_tackquotes_connector_token' => 'feed_TOPSECRET_wxyz',
        'module_tackquotes_api_url'         => 'https://api.tackquote.com/v1',
    ]);

    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('config', $config);
    $registry->set('user', new User(['modify:' . ROUTE]));
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('session', new Session());
    $registry->set('document', new Document());
    $registry->set('model_setting_setting', new SettingModel());
    $loader = new Loader($registry, dirname(__DIR__) . '/admin/language/en-gb/module/tackquotes.php');
    $registry->set('load', $loader);

    (new Tackquotes($registry))->index();

    assertSame(null, $response->redirect, 'index() must render, not redirect');

    $data = $loader->viewData;

    assertTrue(isset($data['save']), 'the template needs a `save` URL to post to');
    assertTrue(
        strpos($data['save'], ROUTE . '.save') !== false,
        'the form must post to the .save method, not back to the page: ' . ($data['save'] ?? '')
    );

    foreach ($data as $key => $value) {
        if (!is_string($value)) {
            continue;
        }

        assertTrue(
            strpos($value, 'sk_live_TOPSECRET_abcd') === false,
            "the stored API key reached the template in \$data['$key']"
        );
        assertTrue(
            strpos($value, 'feed_TOPSECRET_wxyz') === false,
            "the stored feed token reached the template in \$data['$key']"
        );
    }

    assertTrue(
        strpos((string) $data['module_tackquotes_api_key_masked'], 'abcd') !== false,
        'the masked hint should still show the last four characters'
    );
});


// ------------------------------------------------------------- catalog feed

echo "\nTackQuote for OpenCart — catalog feed paging\n";

/**
 * @return array{0: Product, 1: Response, 2: Db}
 */
function makeFeed(int $productCount, array $get = [], string $token = 'feed-token'): array
{
    $rows = [];

    for ($i = 1; $i <= $productCount; $i++) {
        $rows[] = [
            'product_id' => $i, 'model' => 'MDL-' . $i, 'sku' => 'SKU-' . $i,
            'image' => 'catalog/p' . $i . '.jpg', 'price' => '10.0000', 'quantity' => 5,
            'status' => 1, 'name' => 'Product ' . $i, 'description' => 'd', 'special' => null,
        ];
    }

    $registry = new Registry();
    $request = new Request();
    $request->get = $get;
    $request->server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;

    $response = new Response();
    $db = new Db($rows);

    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('db', $db);
    $registry->set('config', new Config([
        'module_tackquotes_connector_token' => 'feed-token',
        'config_store_id' => 0, 'config_language_id' => 1, 'config_customer_group_id' => 1,
    ]));

    return [new Product($registry), $response, $db];
}

/** How many product SELECTs (not the COUNT) the controller issued. */
function dataQueries(Db $db): array
{
    return array_values(array_filter($db->queries, fn ($q) => stripos($q, 'SELECT COUNT(*)') !== 0));
}

check('the feed still fails closed with no token configured', function () {
    $registry = new Registry();
    $request = new Request();
    $response = new Response();
    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('db', new Db([]));
    $registry->set('config', new Config(['module_tackquotes_connector_token' => '']));

    (new Product($registry))->list();

    assertTrue(strpos(implode(' ', $response->headers), '503') !== false,
        'an unconfigured feed must answer 503, never an open feed');
});

check('the feed rejects a wrong bearer token', function () {
    [$controller, $response] = makeFeed(3, [], 'wrong-token');
    $controller->list();

    assertTrue(strpos(implode(' ', $response->headers), '401') !== false, 'a wrong token must be 401');
});

check('an unpaged request still returns the COMPLETE catalog', function () {
    // Deliberately spans several chunks: 601 = 2 full 250-row chunks plus 101.
    [$controller, $response, $db] = makeFeed(601);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(601, count($json['products']), 'the unpaged default must not drop products');
    assertSame(601, $json['total'], 'total should report the whole catalog');
    assertSame(false, $json['truncated'], 'a 601-product catalog is nowhere near the ceiling');
    assertSame(null, $json['next_page'], 'nothing remains, so there is no next page');
    assertSame(1, $json['products'][0]['product_id'], 'first product');
    assertSame(601, $json['products'][600]['product_id'], 'last product — an off-by-one here loses a row');
});

check('an unpaged request no longer issues one unbounded statement', function () {
    [$controller, , $db] = makeFeed(601);
    $controller->list();

    $queries = dataQueries($db);

    assertSame(3, count($queries), 'expected ceil(601/250) = 3 bounded reads');

    foreach ($queries as $sql) {
        assertTrue(preg_match('/ LIMIT \d+,\d+$/', $sql) === 1,
            'every product read must carry a LIMIT; an unbounded one is the original defect: ' . substr($sql, -60));
    }
});

check('an exact multiple of the chunk size terminates without a spurious read', function () {
    [$controller, $response, $db] = makeFeed(500);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(500, count($json['products']), 'all 500 products');
    assertSame(false, $json['truncated'], 'exhausting the table exactly is not truncation');
    // 2 full chunks, then a third read that comes back empty and breaks the loop.
    assertTrue(count(dataQueries($db)) <= 3, 'should not keep querying past the end of the table');
});

check('an empty catalog returns cleanly rather than looping', function () {
    [$controller, $response, $db] = makeFeed(0);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame([], $json['products'], 'no products');
    assertSame(0, $json['total'], 'total 0');
    assertSame(false, $json['truncated'], 'an empty catalog is not truncated');
    assertSame(1, count(dataQueries($db)), 'one read, which comes back empty');
});

check('past the ceiling it truncates LOUDLY and says how to resume', function () {
    [$controller, $response] = makeFeed(5400);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(5000, count($json['products']), 'the ceiling should hold at UNPAGED_MAX_PRODUCTS');
    assertSame(5400, $json['total'], 'total must still report the real catalog size, so the caller can see the gap');
    assertSame(true, $json['truncated'], 'a partial catalog MUST be flagged — silent truncation is the defect');
    assertSame(21, $json['next_page'], '5000 rows of 250 = 20 pages consumed, so resume at 21');
    assertSame(250, $json['next_limit'], 'next_page is meaningless without the size it is counted in');

    // The resume instruction must actually be correct, and following it to the end
    // must recover every product — that is the whole claim "not silent truncation"
    // rests on. Walked here rather than asserted once, because a next_page that is
    // right for one hop and wrong for the next is still a lost catalog.
    $seen = count($json['products']);
    $page = $json['next_page'];
    $limit = $json['next_limit'];
    $firstResumed = null;
    $hops = 0;

    while ($page !== null) {
        assertTrue(++$hops < 10, 'resume loop did not terminate');

        [$next, $nextResponse] = makeFeed(5400, ['page' => (string) $page, 'limit' => (string) $limit]);
        $next->list();
        $nextJson = json_decode($nextResponse->output, true);

        $firstResumed ??= $nextJson['products'][0]['product_id'];

        $seen += count($nextJson['products']);
        $page = $nextJson['next_page'];
        $limit = $nextJson['next_limit'];
    }

    assertSame(5001, $firstResumed,
        'following next_page/next_limit must land on the first product NOT already returned — '
            . 'an off-by-one here silently skips or duplicates a page');
    assertSame(5400, $seen, 'walking next_page to exhaustion must recover the ENTIRE catalog');
});

check('an explicitly paged request is served exactly and is not called truncated', function () {
    [$controller, $response, $db] = makeFeed(601, ['page' => '2', 'limit' => '100']);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(100, count($json['products']), 'the caller asked for 100');
    assertSame(101, $json['products'][0]['product_id'], 'page 2 of 100 starts at product 101');
    assertSame(false, $json['truncated'],
        'serving exactly the page size the caller asked for is not truncation');
    assertSame(3, $json['next_page'], 'another page exists');
    assertSame(100, $json['next_limit'], 'in the size the caller is using');
    assertSame(1, count(dataQueries($db)), 'an explicit page is one read');
});

check('the last explicit page reports no next page', function () {
    [$controller, $response] = makeFeed(250, ['page' => '3', 'limit' => '100']);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(50, count($json['products']), 'the tail page');
    assertSame(null, $json['next_page'], 'nothing remains after product 250');
});

check('limit is still capped at MAX_LIMIT', function () {
    [$controller, $response] = makeFeed(2000, ['page' => '1', 'limit' => '9999']);
    $controller->list();

    $json = json_decode($response->output, true);

    assertSame(250, count($json['products']), 'a caller must not be able to ask for the whole table');
    assertSame(250, $json['limit'], 'the response should echo the capped limit');
});

// ------------------------------------------------------- template-level guards

echo "\nTackQuote for OpenCart — admin template\n";

check('the admin template contains no innerHTML sink', function () use ($root) {
    $twig = (string) file_get_contents($root . '/admin/view/template/module/tackquotes.twig');

    // Assignment only. The word may legitimately appear in a comment explaining why.
    assertTrue(
        preg_match('/\binnerHTML\s*=/', $twig) !== 1,
        'an `innerHTML =` assignment is back in the admin template. The test-connection '
            . 'failure path embeds text from whatever host the merchant-editable API URL '
            . 'points at, so that is a stored-XSS sink in the admin session. Use textContent.'
    );
    assertTrue(
        preg_match('/\b(outerHTML|insertAdjacentHTML|document\.write)\s*[=(]/', $twig) !== 1,
        'another markup-parsing sink appeared in the admin template'
    );
});

check('the admin form posts to the save route', function () use ($root) {
    $twig = (string) file_get_contents($root . '/admin/view/template/module/tackquotes.twig');

    assertTrue(strpos($twig, 'action="{{ save }}"') !== false,
        'the form action must be the `save` URL; posting back to the page route is what made '
            . 'the ajax form receive HTML and show nothing');
    assertTrue(strpos($twig, 'id="error-api-url"') !== false,
        'the #error-api-url element is what common.js fills from error.api_url; without it an '
            . 'invalid URL is reported to nobody');
});

check('no English string is hard-coded in the template JS', function () use ($root) {
    $twig = (string) file_get_contents($root . '/admin/view/template/module/tackquotes.twig');

    assertTrue(strpos($twig, 'Could not reach the admin ajax endpoint.') === false,
        'that string belongs in the language file (error_ajax), per OpenCart\'s '
            . '"use language files for all text" guidance');
});

check('every language key the admin controller and template ask for exists', function () use ($root) {
    $lang = new Language();
    $lang->load($root . '/admin/language/en-gb/module/tackquotes.php');

    foreach (['error_permission', 'error_api_url', 'error_api_key', 'error_test_connection',
              'error_ajax', 'text_success', 'text_test_success', 'heading_title'] as $key) {
        assertTrue($lang->has($key), "language key '$key' is missing, so the UI would show the key itself");
    }
});

// ------------------------------------------------ storefront: the outbound payload

echo "\nTackQuote for OpenCart — storefront quote submission\n";

/**
 * Minimal catalog/product model. Returns a product for any positive id, so a test can
 * assert on the payload rather than on catalog plumbing.
 */
class ProductModel
{
    public function getProduct(int $productId): array
    {
        if ($productId < 1) {
            return [];
        }

        return [
            'product_id' => $productId,
            'model' => 'MDL-' . $productId,
            'name' => 'Product ' . $productId,
            'price' => '12.5000',
        ];
    }
}

/** Records the outbound request instead of performing it. */
class RecordingApiClient extends \Opencart\System\Library\Extension\Tack\ApiClient
{
    /** @var array<int, array<string, mixed>> */
    public static array $calls = [];

    public function createQuoteRequest(array $payload)
    {
        self::$calls[] = $payload;

        return ['id' => 'q-1', 'quoteNumber' => 'TK-2026-000001', 'portalUrl' => 'https://portal.example/q-1'];
    }
}

/**
 * The controller with its ONE outbound seam replaced.
 *
 * Deliberately not a fake `send()`: the payload construction inside `send()` is the whole
 * subject here — which fields go out, and which are omitted — so overriding it would test
 * nothing. CLAUDE.md's connector rule is to assert on the request that goes out.
 */
class ProbeStorefront extends CatalogTackquotes
{
    protected function apiClient(string $apiUrl, string $apiKey): \Opencart\System\Library\Extension\Tack\ApiClient
    {
        return new RecordingApiClient($apiUrl, $apiKey);
    }
}

/**
 * @param array<string, mixed> $post
 * @return array{0: ProbeStorefront, 1: Response}
 */
function makeStorefront(array $post): array
{
    RecordingApiClient::$calls = [];

    $registry = new Registry();

    $request = new Request();
    $request->post = $post;
    $request->server['REQUEST_METHOD'] = 'POST';

    $response = new Response();

    $registry->set('request', $request);
    $registry->set('response', $response);
    $registry->set('config', new Config([
        'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
        'module_tackquotes_api_key' => 'sk_live_storefront',
        'config_currency' => 'EUR',
    ]));
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('session', new Session());
    $registry->set('model_catalog_product', new ProductModel());
    $registry->set(
        'load',
        new Loader($registry, dirname(__DIR__) . '/catalog/language/en-gb/module/tackquotes.php')
    );

    return [new ProbeStorefront($registry), $response];
}

/** The single payload the controller sent, insisting it sent exactly one. */
function sentPayload(): array
{
    assertSame(1, count(RecordingApiClient::$calls),
        'expected exactly one outbound quote-request; got ' . count(RecordingApiClient::$calls)
            . '. Zero means the controller errored before submitting — check $response->output');

    return RecordingApiClient::$calls[0];
}

check('quoteList() sends the buyer identity AS FIELDS, not smuggled into the note', function () {
    [$controller] = makeStorefront([
        'email' => 'grace@acme-example.com',
        'firstName' => 'Grace',
        'lastName' => 'Hopper',
        'company' => 'Acme Industrial',
        'telephone' => '+1 555 0100',
        'note' => 'Need this by Friday',
        'items' => [['product_id' => '7', 'quantity' => '3']],
    ]);

    $controller->quoteList();
    $payload = sentPayload();

    assertSame('Grace', $payload['firstName'] ?? null,
        'firstName must be a field on the payload. It used to be concatenated into the note, '
            . 'which left the API with no name to store — so it invented one from the email '
            . 'local part and the seller saw a buyer called "grace"');
    assertSame('Hopper', $payload['lastName'] ?? null, 'lastName must be a field');
    assertSame('Acme Industrial', $payload['companyName'] ?? null,
        'the company must be a field, so it can resolve to a real company record rather than free text');
    assertSame('+1 555 0100', $payload['phone'] ?? null, 'the phone must be a field');

    assertSame('Need this by Friday', $payload['note'] ?? null,
        'the note must carry ONLY the shopper note now');

    // The strongest statement available here: the identity must not appear in the note at
    // all. A refactor that sends the fields AND keeps concatenating them would satisfy
    // every assertion above and still leave the seller reading names out of free text.
    foreach (['Grace', 'Hopper', 'Acme Industrial', '+1 555 0100'] as $identity) {
        assertTrue(
            strpos((string) $payload['note'], $identity) === false,
            "'$identity' is still being written into the note"
        );
    }
});

check('quoteList() OMITS an identity field the shopper left blank', function () {
    [$controller] = makeStorefront([
        'email' => 'anon@acme-example.com',
        'firstName' => '',
        'lastName' => '   ',
        'company' => '',
        'telephone' => '',
        'note' => '',
        'items' => [['product_id' => '7', 'quantity' => '1']],
    ]);

    $controller->quoteList();
    $payload = sentPayload();

    // Omitted rather than sent as ''. TackQuote treats a blank as "not supplied" and
    // leaves the column NULL; sending '' would be an assertion that the shopper's name
    // IS the empty string, and identity-merge would happily write it over a real name
    // supplied on an earlier visit.
    foreach (['firstName', 'lastName', 'companyName', 'phone'] as $field) {
        assertTrue(!array_key_exists($field, $payload),
            "$field was sent as an empty value; it should be omitted entirely");
    }

    assertSame('anon@acme-example.com', $payload['buyerEmail'] ?? null, 'the email still goes out');
});

check('quoteList() sends what the shopper typed, without silently reshaping it', function () {
    // Trimmed, never truncated and never normalised. `buyers.first_name` is varchar(255)
    // in TackQuote and the endpoint refuses anything longer with a message naming the
    // field — which is the right place for that line to be drawn. Cutting the value to
    // fit here would store half a surname and report success, which is the same class of
    // quietly-wrong data as inventing a name from the email address.
    $long = str_repeat('a', 400);

    [$controller] = makeStorefront([
        'email' => 'long@acme-example.com',
        'firstName' => '  Grace  ',
        'lastName' => $long,
        'note' => '',
        'items' => [['product_id' => '7', 'quantity' => '1']],
    ]);

    $controller->quoteList();
    $payload = sentPayload();

    assertSame('Grace', $payload['firstName'] ?? null, 'surrounding whitespace is trimmed');
    assertSame($long, $payload['lastName'] ?? null,
        'the value must go out intact; the API decides whether to accept it');
});

check('the single-product modal still submits with no identity fields at all', function () {
    // This path has no name inputs (catalog/view/template/module/tackquotes.twig renders
    // email, quantity and note), and merchants are using it. It must keep working — which
    // is exactly why firstName is OPTIONAL on the endpoint rather than required.
    [$controller] = makeStorefront([
        'email' => 'nameless@acme-example.com',
        'note' => 'just this one please',
        'product_id' => '7',
        'quantity' => '2',
    ]);

    $controller->quote();
    $payload = sentPayload();

    assertSame('nameless@acme-example.com', $payload['buyerEmail'] ?? null, 'the email goes out');
    assertSame('just this one please', $payload['note'] ?? null, 'the note goes out unchanged');

    foreach (['firstName', 'lastName', 'companyName', 'phone'] as $field) {
        assertTrue(!array_key_exists($field, $payload),
            "the single-product modal collects no $field, so it must not send one");
    }
});


// ═════════════════════════════════════════════════════════════════════════════════════════
// QUOTE-ONLY / B2B CATALOG MODE
// ═════════════════════════════════════════════════════════════════════════════════════════
//
// The claim under test is not "a button is hidden". It is:
//
//   1. a crafted POST to checkout/cart.add is REFUSED IN PHP;
//   2. and the storefront is never left with no way to transact at all — whenever the cart
//      is taken away, a quote CTA is rendered in its place.
//
// (2) is here because it is the failure the WooCommerce build nearly shipped: the quote
// button hung off a hook that only fires inside the add-to-cart form, so removing the cart
// button would have silently removed the quote button too. OpenCart's version of the same
// coupling is `id="button-cart"`, which is the ONLY anchor the injection has. Several tests
// below exist purely to keep those two from being decoupled again.

echo "\nTackQuote for OpenCart — quote-only mode: the rule\n";

check('scope "all" covers guests and logged-in customers alike', function () {
    assertTrue(QuoteOnly::applies(true, 'all', [], false, 1), 'a guest is covered');
    assertTrue(QuoteOnly::applies(true, 'all', [], true, 5), 'a logged-in customer is covered');
});

check('scope "guests" lets approved B2B customers keep the cart', function () {
    assertTrue(QuoteOnly::applies(true, 'guests', [], false, 1), 'the public gets the catalog');
    assertTrue(!QuoteOnly::applies(true, 'guests', [], true, 5),
        'a logged-in customer must keep a working cart — this scope IS the "approved buyers '
            . 'still buy" setting, and inverting it turns every B2B account into a catalog');
});

check('scope "groups" matches only the selected groups', function () {
    assertTrue(QuoteOnly::applies(true, 'groups', [2, 3], true, 2), 'group 2 is selected');
    assertTrue(!QuoteOnly::applies(true, 'groups', [2, 3], true, 5), 'group 5 is not');
    assertTrue(!QuoteOnly::applies(true, 'groups', [], true, 2),
        'an empty selection matches nobody, so the cart stays open — the admin refuses to '
            . 'save this state, but a hand-edited row must fail towards a working store');
});

check('the mode is off unless the merchant switched it on', function () {
    assertTrue(!QuoteOnly::applies(false, 'all', [], false, 1), 'disabled beats every scope');
});

check('an unrecognised scope falls back to "all", not to "nobody"', function () {
    assertTrue(QuoteOnly::applies(true, 'ALL', [], false, 1), 'case is normalised');
    assertTrue(QuoteOnly::applies(true, 'nonsense', [], false, 1),
        'a corrupted oc_setting row must not quietly re-open the cart on a store whose owner '
            . 'switched quote-only on');
});

check('group ids are normalised from every shape oc_setting can return', function () {
    assertSame([2, 3], QuoteOnly::normaliseGroups([2, 3]), 'a plain array');
    assertSame([2, 3], QuoteOnly::normaliseGroups('[2,3]'), 'the JSON model_setting_setting writes');
    assertSame([2, 3], QuoteOnly::normaliseGroups('2, 3'), 'a hand-written CSV row');
    assertSame([2, 3], QuoteOnly::normaliseGroups(['2', 3, '2']), 'strings, ints and duplicates');
    assertSame([], QuoteOnly::normaliseGroups(null), 'never saved');
    assertSame([], QuoteOnly::normaliseGroups(['', 'abc', 0, -1, false]),
        'group 0 is the GUEST sentinel, not a group. Casting junk to 0 would widen a '
            . '"selected groups" rule to every guest on the store');
});

// ───────────────────────────────────────────────────────── enforcement: the request guard

echo "\nTackQuote for OpenCart — quote-only mode: server-side enforcement\n";

/**
 * A guard controller wired to stubs.
 *
 * @param array<string, mixed> $config
 * @return array{0: Quotemode, 1: Response, 2: Registry}
 */
function makeGuard(array $config = [], bool $logged = false, int $groupId = 0, bool $adminLogged = false): array
{
    $registry = new Registry();

    $response = new Response();

    $registry->set('request', new Request());
    $registry->set('response', $response);
    $registry->set('config', new Config(array_merge([
        'module_tackquotes_status' => 1,
        'module_tackquotes_api_key' => 'sk_live_configured',
        'module_tackquotes_quote_only' => 1,
        'module_tackquotes_quote_only_scope' => 'all',
        'module_tackquotes_quote_only_groups' => [],
        'config_customer_group_id' => 1,
        'config_language' => 'en-gb',
    ], $config)));
    $registry->set('customer', new Customer($logged, $groupId));
    $registry->set('admin_user_logged', $adminLogged);
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('document', new Document());
    $registry->set('load', new Loader($registry, dirname(__DIR__) . '/catalog/language/en-gb/module/tackquotes.php'));

    return [new Quotemode($registry), $response, $registry];
}

/**
 * Reproduces OpenCart 4.1.0.4's dispatch contract, which is what makes the rewrite a refusal
 * rather than a suggestion:
 *
 *   system/framework.php:262   $event->trigger('controller/' . $trigger . '/before', [&$route, &$args]);
 *   system/framework.php:268   if (!$action) {
 *   system/framework.php:269       $action = new \Opencart\System\Engine\Action($route);
 *
 * The value this returns is literally the route OpenCart will construct and execute. If it
 * still says checkout/cart.add, core's cart controller runs and the product is added.
 */
function dispatchedRoute(Quotemode $guard, string $handler, string $route): string
{
    $args = [];

    $guard->$handler($route, $args);

    return $route;
}

check('a crafted add-to-cart POST never reaches core cart controller', function () {
    [$guard] = makeGuard();

    $route = dispatchedRoute($guard, 'guardCart', 'checkout/cart.add');

    assertTrue($route !== 'checkout/cart.add',
        'the route was left pointing at core checkout/cart.add, so '
            . '$this->cart->add() (catalog/controller/checkout/cart.php:286) still runs and '
            . 'the product IS added. Hiding the button changes nothing about this request.');
    assertSame('extension/tack/quotemode.blocked', $route, 'it must go to the JSON refusal');
});

check('the quantity-edit route is guarded too, not just add', function () {
    [$guard] = makeGuard();

    assertSame('extension/tack/quotemode.blocked',
        dispatchedRoute($guard, 'guardCart', 'checkout/cart.edit'),
        'without this, a cart holding one line from before the switch could be edited to '
            . 'quantity 10000 and checked out — a policy with a hole in it');
});

check('checkout and order confirmation are refused with a page, not JSON', function () {
    [$guard] = makeGuard();

    assertSame('extension/tack/quotemode.notice',
        dispatchedRoute($guard, 'guardCheckout', 'checkout/checkout'), 'the checkout page');
    assertSame('extension/tack/quotemode.notice',
        dispatchedRoute($guard, 'guardCheckout', 'checkout/confirm'),
        'checkout/confirm is where addOrder() happens (confirm.php:280)');
    assertSame('extension/tack/quotemode.notice',
        dispatchedRoute($guard, 'guardCheckout', 'checkout/confirm.confirm'),
        'the same order-creating code under its own route (confirm.php:359)');
});

check('every route the guard rewrites to is a real callable method', function () {
    // A typo here ships an extension that answers every add-to-cart with OpenCart's
    // not-found page. `tsc` has no opinion about a string, and neither did the last four
    // regressions in this tree.
    [$guard] = makeGuard();

    foreach ([['guardCart', 'checkout/cart.add'], ['guardCheckout', 'checkout/checkout']] as [$handler, $route]) {
        $rewritten = dispatchedRoute($guard, $handler, $route);
        $method = substr($rewritten, (int) strrpos($rewritten, '.') + 1);

        assertSame('extension/tack/quotemode', substr($rewritten, 0, (int) strrpos($rewritten, '.')),
            'the responder must live on this controller, whose route is fixed by its filename');
        assertTrue(is_callable([$guard, $method]), "responder method $method() does not exist");
    }
});

check('with the mode switched off nothing is touched', function () {
    [$guard] = makeGuard(['module_tackquotes_quote_only' => 0]);

    assertSame('checkout/cart.add', dispatchedRoute($guard, 'guardCart', 'checkout/cart.add'),
        'a normal store must dispatch normally');
    assertSame('checkout/checkout', dispatchedRoute($guard, 'guardCheckout', 'checkout/checkout'),
        'a normal store must reach checkout');
});

check('a disabled module never enforces', function () {
    [$guard] = makeGuard(['module_tackquotes_status' => 0]);

    assertSame('checkout/cart.add', dispatchedRoute($guard, 'guardCart', 'checkout/cart.add'),
        'quote-only left switched on in a disabled module must not refuse anything');
});

check('WITHOUT AN API KEY the cart is NOT blocked — enforcement and CTA share one condition', function () {
    // The dead-storefront test. catalog/controller/event/quote.php::isActive() renders no
    // quote button until an API key is stored, because a button that cannot reach TackQuote
    // drops the shopper's request on the floor. If enforcement did not honour the SAME
    // condition, a merchant who enabled quote-only before pasting their key would have a
    // storefront with no cart and no quote button: nothing works, in either direction, and
    // nothing in the admin says why.
    [$guard] = makeGuard(['module_tackquotes_api_key' => '']);

    assertSame('checkout/cart.add', dispatchedRoute($guard, 'guardCart', 'checkout/cart.add'),
        'enforcement ran while the quote CTA could not render — that is a store nobody can '
            . 'buy from AND nobody can request a quote from');
});

check('an admin logged into this browser keeps cart and checkout', function () {
    // Mirrors core maintenance mode, catalog/controller/startup/maintenance.php:29-31 — the
    // one other feature that switches the storefront off for the public but not for staff.
    [$guard] = makeGuard([], false, 0, true);

    assertSame('checkout/cart.add', dispatchedRoute($guard, 'guardCart', 'checkout/cart.add'),
        'a merchant must be able to verify the real cart before and after flipping the switch');
    assertSame('checkout/checkout', dispatchedRoute($guard, 'guardCheckout', 'checkout/checkout'),
        'and the real checkout');
});

check('scope "guests": the public is refused and a logged-in customer is not', function () {
    [$guest] = makeGuard(['module_tackquotes_quote_only_scope' => 'guests'], false, 0);
    [$member] = makeGuard(['module_tackquotes_quote_only_scope' => 'guests'], true, 5);

    assertSame('extension/tack/quotemode.blocked', dispatchedRoute($guest, 'guardCart', 'checkout/cart.add'),
        'the public gets a catalog');
    assertSame('checkout/cart.add', dispatchedRoute($member, 'guardCart', 'checkout/cart.add'),
        'an approved, logged-in B2B customer keeps a working cart');
});

check('scope "groups" is evaluated against the STORE DEFAULT group for a guest', function () {
    // A guest's customer_group_id is 0 (system/library/cart/customer.php:36), which is a
    // sentinel. Passing the raw 0 through would mean "selected groups" could never match a
    // guest, and a merchant selecting their default group would silently target logged-in
    // customers only.
    [$guest] = makeGuard([
        'module_tackquotes_quote_only_scope' => 'groups',
        'module_tackquotes_quote_only_groups' => [1],
        'config_customer_group_id' => 1,
    ], false, 0);

    assertSame('extension/tack/quotemode.blocked', dispatchedRoute($guest, 'guardCart', 'checkout/cart.add'),
        'the guest belongs to the store default group, which is selected');

    [$other] = makeGuard([
        'module_tackquotes_quote_only_scope' => 'groups',
        'module_tackquotes_quote_only_groups' => [2],
        'config_customer_group_id' => 1,
    ], false, 0);

    assertSame('checkout/cart.add', dispatchedRoute($other, 'guardCart', 'checkout/cart.add'),
        'the guest group is not selected, so the cart stays open');

    [$member] = makeGuard([
        'module_tackquotes_quote_only_scope' => 'groups',
        'module_tackquotes_quote_only_groups' => [2],
    ], true, 2);

    assertSame('extension/tack/quotemode.blocked', dispatchedRoute($member, 'guardCart', 'checkout/cart.add'),
        'a logged-in customer is matched on their own group');
});

check('the refusal is JSON, in the key OpenCart actually renders', function () {
    [$guard, $response] = makeGuard();

    $guard->blocked();

    assertTrue(in_array('Content-Type: application/json', $response->headers, true),
        'the storefront asks for JSON (product.twig:303, common.js:93)');

    $json = json_decode($response->output, true);

    assertTrue(is_array($json) && isset($json['error']['warning']),
        'error.warning is the key common.js:134-135 renders as a banner in #alert');
    assertTrue($json['error']['warning'] !== 'error_quote_only' && $json['error']['warning'] !== '',
        'the refusal shows the raw language KEY, so error_quote_only is missing from the '
            . 'storefront language file and the shopper is told nothing');
    assertTrue(!isset($json['success']), 'a refusal must never report success');
    assertTrue(!isset($json['redirect']),
        'no redirect: core redirects on a cart failure to re-render option errors, but here '
            . 'that would replace a stated reason with an unexplained page reload');
});

check('the blocked-checkout page offers a quote CTA and a way back', function () {
    [$guard, $response, $registry] = makeGuard();

    $guard->notice();

    assertSame(null, $response->redirect,
        'a bare redirect to the cart leaves the shopper clicking Checkout forever with no '
            . 'explanation — this must be a real page');

    $loader = $registry->get('load');
    assertSame('extension/tack/quote/blocked', $loader->viewRoute, 'the blocked page template');

    $data = $loader->viewData;
    assertTrue(($data['request_label'] ?? '') !== '', 'the page must carry a request-a-quote label');
    assertTrue(($data['continue_href'] ?? '') !== '', 'and a way to keep shopping');
    assertTrue(($data['cart_href'] ?? '') !== '',
        'and a link to the existing basket — turning the mode on does not delete it');

    $twig = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/template/quote/blocked.twig');
    assertTrue(strpos($twig, 'data-tackquote-request') !== false,
        'the blocked page must actually render a quote trigger, not just be handed a label');
});

check('the storefront JS binds a quote trigger that is NOT inside a product block', function () {
    // The blocked page has no product context, so bindProductControls() — which only looks
    // inside [data-tackquote-product] — would never reach its button. Without the delegated
    // handler the last button a quote-only shopper has does nothing at all.
    $js = (string) file_get_contents(dirname(__DIR__) . '/catalog/view/javascript/tack/quote-app.js');

    assertTrue(strpos($js, "closest('[data-tackquote-request]')") !== false,
        'no delegated handler for a standalone [data-tackquote-request] trigger');
});


// ──────────────────────────────────── contract: the store is never left unable to transact

echo "\nTackQuote for OpenCart — quote-only mode: the product page always keeps a CTA\n";

/**
 * OpenCart 4.1.0.4's own add-to-cart markup, copied from
 * catalog/view/template/product/product.twig:223-230. The exact shape matters: the injection
 * anchors on `id="button-cart"` and steps past the `input-group`'s closing `</div>`, and a
 * paraphrase would test a template nobody ships.
 */
const PRODUCT_HTML = '<div class="mb-3">'
    . '<div class="input-group">'
    . '<div class="input-group-text">Qty</div>'
    . '<input type="text" name="quantity" value="1" size="2" id="input-quantity" class="form-control"/>'
    . '<button type="submit" id="button-cart" class="btn btn-primary btn-lg btn-block">Add to Cart</button>'
    . '</div>'
    . '<input type="hidden" name="product_id" value="42" id="input-product-id"/>'
    . '<div id="error-quantity" class="form-text"></div>'
    . '</div>';

/**
 * @param array<string, mixed> $config
 * @return array{0: QuoteEvent, 1: Loader}
 */
function makeProductEvent(array $config = [], bool $logged = false, int $groupId = 0, bool $adminLogged = false): array
{
    $registry = new Registry();

    $registry->set('request', new Request());
    $registry->set('response', new Response());
    $registry->set('config', new Config(array_merge([
        'module_tackquotes_status' => 1,
        'module_tackquotes_api_key' => 'sk_live_configured',
        'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
        'module_tackquotes_inline_button' => 1,
        'module_tackquotes_quote_list' => 1,
        'module_tackquotes_quote_only' => 0,
        'module_tackquotes_quote_only_scope' => 'all',
        'module_tackquotes_quote_only_groups' => [],
        'config_customer_group_id' => 1,
        'config_language' => 'en-gb',
    ], $config)));
    $registry->set('customer', new Customer($logged, $groupId));
    $registry->set('admin_user_logged', $adminLogged);
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('document', new Document());
    $registry->set('model_catalog_product', new ProductModel());

    $loader = new Loader($registry, dirname(__DIR__) . '/catalog/language/en-gb/module/tackquotes.php');
    $registry->set('load', $loader);

    return [new QuoteEvent($registry), $loader];
}

/** Runs the product-page view event over the stock markup and returns the resulting HTML. */
function renderProductPage(QuoteEvent $event, string $html = PRODUCT_HTML): string
{
    $route = 'product/product';
    $data = ['product_id' => 42];
    $output = $html;

    $event->productPage($route, $data, $output);

    return $output;
}

/** The marker the stub Loader renders for the quote controls partial. */
const CONTROLS_MARKER = '<!-- rendered extension/tack/quote/controls -->';

check('quote-only mode removes Add to Cart AND leaves the quote CTA in its place', function () {
    [$event] = makeProductEvent(['module_tackquotes_quote_only' => 1]);

    $html = renderProductPage($event);

    assertTrue(strpos($html, 'id="button-cart"') === false,
        'the Add to Cart button is still rendered on a quote-only storefront');
    assertTrue(strpos($html, CONTROLS_MARKER) !== false,
        'THE FAILURE THIS TEST EXISTS FOR: the cart button was removed and no quote CTA was '
            . 'rendered, leaving a product page with no way to transact at all. `id="button-cart"` '
            . 'is the ONLY anchor the injection has, so removing it before injecting removes '
            . 'the landmark the injection needs — exactly the coupling that nearly shipped in '
            . 'the WooCommerce build.');
    assertTrue(strpos($html, 'TackQuote: quote-only mode') !== false,
        'a comment should mark why the button is gone, so a merchant reading page source '
            . 'does not think their theme broke');
    assertTrue(strpos($html, 'id="input-quantity"') !== false,
        'the quantity input must survive — the quote controls read it for the requested qty');
});

check('the quote CTA is rendered as the PRIMARY action once the cart is gone', function () {
    [$event, $loader] = makeProductEvent(['module_tackquotes_quote_only' => 1]);

    renderProductPage($event);

    $data = null;
    foreach ($loader->viewCalls as $call) {
        if ($call['route'] === 'extension/tack/quote/controls') {
            $data = $call['data'];
        }
    }

    assertTrue($data !== null, 'the controls partial was never rendered');
    assertSame(true, $data['tackquote_quote_only'] ?? null,
        'the template needs this to promote the request button from secondary to primary; '
            . 'with the cart gone it is not the alternative action, it is the only one');
});

check('quote-only overrides the "show beside Add to Cart" placement toggle', function () {
    // With the toggle off and the mode on, the old code path returned early and injected
    // NOTHING — while the server refused every add-to-cart. Dead product page.
    [$event] = makeProductEvent([
        'module_tackquotes_quote_only' => 1,
        'module_tackquotes_inline_button' => 0,
    ]);

    $html = renderProductPage($event);

    assertTrue(strpos($html, CONTROLS_MARKER) !== false,
        'the placement toggle silenced the CTA on a storefront whose cart is already refused');
    assertTrue(strpos($html, 'id="button-cart"') === false, 'and the cart button still goes');
});

check('a theme that renamed the cart button still gets a quote CTA in quote-only mode', function () {
    // Normally "no anchor" means "leave the page exactly as the theme rendered it". That
    // answer is not available once the server is refusing the cart, so the controls are
    // appended instead — adjacent rather than perfect, which beats a shopper with no way to
    // ask for a price.
    $themed = str_replace('id="button-cart"', 'id="add-to-basket"', PRODUCT_HTML);

    [$event] = makeProductEvent(['module_tackquotes_quote_only' => 1]);

    $html = renderProductPage($event, $themed);

    assertTrue(strpos($html, CONTROLS_MARKER) !== false,
        'an unrecognised theme must still get a quote CTA when the cart is refused');
    assertTrue(strpos($html, 'id="add-to-basket"') !== false,
        'and the theme\'s own button is left untouched — mangling a stranger\'s markup on a '
            . 'guess is worse than a button in the wrong place, and the POST is refused anyway');
});

check('with quote-only OFF the Add to Cart button is untouched (regression)', function () {
    [$event] = makeProductEvent(['module_tackquotes_quote_only' => 0]);

    $html = renderProductPage($event);

    assertTrue(strpos($html, 'id="button-cart"') !== false,
        'a normal store must keep its Add to Cart button');
    assertTrue(strpos($html, CONTROLS_MARKER) !== false, 'and still get the quote controls beside it');
});

check('the exemptions reach the product page too, not just the guard', function () {
    // If the guard exempts someone but the view event does not, that visitor gets a working
    // cart route with no Add to Cart button — a store that works only for people who know
    // how to craft a POST.
    [$admin] = makeProductEvent(['module_tackquotes_quote_only' => 1], false, 0, true);
    assertTrue(strpos(renderProductPage($admin), 'id="button-cart"') !== false,
        'an admin previewing the storefront must still see Add to Cart');

    [$member] = makeProductEvent([
        'module_tackquotes_quote_only' => 1,
        'module_tackquotes_quote_only_scope' => 'guests',
    ], true, 5);
    assertTrue(strpos(renderProductPage($member), 'id="button-cart"') !== false,
        'a logged-in customer under scope "guests" must keep the button their cart still accepts');

    [$guest] = makeProductEvent([
        'module_tackquotes_quote_only' => 1,
        'module_tackquotes_quote_only_scope' => 'guests',
    ], false, 0);
    assertTrue(strpos(renderProductPage($guest), 'id="button-cart"') === false,
        'and a guest must not');
});

check('an unconfigured store keeps its cart button (the dead-storefront guard, view side)', function () {
    [$event] = makeProductEvent([
        'module_tackquotes_quote_only' => 1,
        'module_tackquotes_api_key' => '',
    ]);

    $html = renderProductPage($event);

    assertTrue(strpos($html, 'id="button-cart"') !== false,
        'with no API key no quote button can render, so the cart button must stay — and the '
            . 'guard must not be enforcing either. Both halves are asserted; they share one condition.');
    assertTrue(strpos($html, CONTROLS_MARKER) === false, 'and no CTA is claimed');
});

// ─────────────────────────────────────────────────────── the event rows that carry the guard

echo "\nTackQuote for OpenCart — quote-only mode: event registration\n";

/** @return array<int, array<string, mixed>> */
function installedEvents(): array
{
    $registry = new Registry();
    $registry->set('request', new Request());
    $registry->set('response', new Response());
    $registry->set('config', new Config());
    $registry->set('user', new User(['modify:' . ROUTE]));
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('session', new Session());
    $registry->set('document', new Document());
    $setting = new SettingModel();
    $registry->set('model_setting_setting', $setting);
    $events = new EventModel();
    $registry->set('model_setting_event', $events);
    $registry->set('load', new Loader($registry, dirname(__DIR__) . '/admin/language/en-gb/module/tackquotes.php'));

    (new Tackquotes($registry))->install();

    return $events->added;
}

check('install() registers the guard rows — without them there is NO enforcement', function () {
    $codes = array_column(installedEvents(), 'code');

    foreach (['tackquotes_guard_cart_add', 'tackquotes_guard_cart_edit', 'tackquotes_guard_checkout',
              'tackquotes_guard_confirm', 'tackquotes_guard_confirm_route'] as $code) {
        assertTrue(in_array($code, $codes, true),
            "event row '$code' is missing. The guard rewrites the route the framework is "
                . 'about to dispatch, and a handler that was never registered cannot rewrite '
                . 'anything — the setting would be a switch wired to nothing.');
    }
});

check('every guard trigger keeps its catalog/ prefix and ends in /before', function () {
    foreach (installedEvents() as $event) {
        if (strpos((string) $event['code'], 'tackquotes_guard_') !== 0) {
            continue;
        }

        $trigger = (string) $event['trigger'];

        assertTrue(strpos($trigger, 'catalog/') === 0,
            "trigger '$trigger' has lost its catalog/ prefix. catalog/controller/startup/event.php "
                . 'strips that prefix to decide the row belongs to the storefront; a row without it '
                . 'is loaded for no application and the feature is silently dead.');
        assertTrue(substr($trigger, -7) === '/before',
            "trigger '$trigger' does not end in /before. Event::trigger() matches a registered "
                . 'trigger as a PREFIX (system/engine/event.php:70), so a shorter one also fires on '
                . '/after — too late to refuse anything — and on cart.remove, which must keep working.');
    }
});

check('no guard is ever registered against an api/ route', function () {
    // The admin/API exemption, asserted rather than assumed. OpenCart's admin
    // "Sales > Orders > Add Order" drives catalog/controller/api/cart.php, and TackQuote's
    // own extension/tack/api/order.php places quote-accepted orders. Blocking those would
    // stop the merchant taking phone orders and stop TackQuote converting the very quotes
    // this mode exists to collect.
    foreach (installedEvents() as $event) {
        assertTrue(strpos((string) $event['trigger'], 'catalog/controller/api/') !== 0,
            'a guard was registered against ' . $event['trigger'] . ' — quote-only mode is a '
                . 'STOREFRONT policy and must never reach the admin/API session');
    }
});

check('every registered guard action resolves to a real method', function () {
    foreach (installedEvents() as $event) {
        if (strpos((string) $event['code'], 'tackquotes_guard_') !== 0) {
            continue;
        }

        $action = (string) $event['action'];
        $dot = strrpos($action, '.');

        assertTrue($dot !== false, "action '$action' names no method");
        assertSame('extension/tack/quotemode', substr($action, 0, $dot),
            "action '$action' does not point at the guard controller, whose route is fixed by "
                . 'its filename (catalog/controller/quotemode.php)');

        [$guard] = makeGuard();
        assertTrue(is_callable([$guard, substr($action, $dot + 1)]),
            "action '$action' names a method that does not exist — this ships as a 404 on "
                . 'every add-to-cart');
    }
});

check('uninstall() removes the guard rows too', function () {
    $registry = new Registry();
    $registry->set('request', new Request());
    $registry->set('response', new Response());
    $registry->set('config', new Config());
    $registry->set('user', new User(['modify:' . ROUTE]));
    $registry->set('language', new Language());
    $registry->set('url', new Url());
    $registry->set('session', new Session());
    $registry->set('document', new Document());
    $registry->set('model_setting_setting', new SettingModel());
    $events = new EventModel();
    $registry->set('model_setting_event', $events);
    $registry->set('load', new Loader($registry, dirname(__DIR__) . '/admin/language/en-gb/module/tackquotes.php'));

    (new Tackquotes($registry))->uninstall();

    assertTrue(in_array('tackquotes_guard_cart_add', $events->deleted, true),
        'a left-behind row keeps calling a controller the Extension Installer has just '
            . 'deleted — a fatal error on every add-to-cart');
});

// ─────────────────────────────────────────────────────────────────── admin settings screen

echo "\nTackQuote for OpenCart — quote-only mode: admin settings\n";

check('save() refuses to switch quote-only on with no API key anywhere', function () {
    [$controller, $response, $setting] = makeController(['modify:' . ROUTE], [
        'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
        'module_tackquotes_quote_only' => '1',
        'module_tackquotes_quote_only_scope' => 'all',
    ]);

    $controller->save();
    $json = decodeJson($response);

    assertTrue(isset($json['error']['quote_only']),
        'this save would be a silent no-op — the storefront honours the same "no key, no '
            . 'enforcement" rule — and the merchant would just get reports that the shop does nothing');
    assertSame([], $setting->writes, 'and nothing may be persisted');
});

check('save() accepts quote-only when a key is already stored but the field posts blank', function () {
    // The key input renders EMPTY by design (the stored secret is only shown masked), so a
    // merchant with a saved key posts a blank one. Reading the raw POST here would make the
    // refusal above permanent on every configured store — the same defect keepOrReplace()
    // was written for.
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_api_key' => '',
            'module_tackquotes_quote_only' => '1',
            'module_tackquotes_quote_only_scope' => 'guests',
        ],
        ['module_tackquotes_api_key' => 'sk_live_already_saved']
    );

    $controller->save();
    $json = decodeJson($response);

    assertTrue(!isset($json['error']), 'a configured store must be allowed to switch the mode on');
    assertSame(1, $setting->writes[0]['values']['module_tackquotes_quote_only'], 'stored as int 1');
    assertSame('guests', $setting->writes[0]['values']['module_tackquotes_quote_only_scope'], 'scope stored');
});

check('save() refuses "selected groups" with nothing selected', function () {
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_quote_only' => '1',
            'module_tackquotes_quote_only_scope' => 'groups',
            'module_tackquotes_quote_only_groups' => [],
        ],
        ['module_tackquotes_api_key' => 'sk_live_already_saved']
    );

    $controller->save();
    $json = decodeJson($response);

    assertTrue(isset($json['error']['quote_only_groups']),
        'that combination matches nobody, so the merchant would flip the switch and see '
            . 'absolutely no change with no explanation');
    assertSame([], $setting->writes, 'and nothing may be persisted');
});

check('save() normalises the scope and the group list on the way in', function () {
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_quote_only' => '1',
            'module_tackquotes_quote_only_scope' => 'GROUPS',
            'module_tackquotes_quote_only_groups' => ['2', '2', '0', 'junk', '3'],
        ],
        ['module_tackquotes_api_key' => 'sk_live_already_saved']
    );

    $controller->save();
    decodeJson($response);

    $values = $setting->writes[0]['values'];

    assertSame('groups', $values['module_tackquotes_quote_only_scope'],
        'a hand-crafted POST must not be able to store a scope the storefront does not understand');
    assertSame([2, 3], $values['module_tackquotes_quote_only_groups'],
        'ints, deduped, with the guest sentinel 0 and junk dropped');
});

check('an unknown posted scope is stored as "all", never as free text', function () {
    [$controller, $response, $setting] = makeController(
        ['modify:' . ROUTE],
        [
            'module_tackquotes_api_url' => 'https://api.tackquote.com/v1',
            'module_tackquotes_quote_only' => '0',
            'module_tackquotes_quote_only_scope' => '../../etc/passwd',
        ]
    );

    $controller->save();
    decodeJson($response);

    assertSame('all', $setting->writes[0]['values']['module_tackquotes_quote_only_scope'],
        'the scope is a closed vocabulary and must be normalised on write as well as on read');
});

check('every quote-only language key the admin and storefront ask for exists', function () use ($root) {
    $admin = new Language();
    $admin->load($root . '/admin/language/en-gb/module/tackquotes.php');

    foreach (['entry_quote_only', 'entry_quote_only_help', 'entry_quote_only_scope',
              'entry_quote_only_scope_help', 'entry_quote_only_groups', 'entry_quote_only_groups_help',
              'text_scope_all', 'text_scope_guests', 'text_scope_groups',
              'error_quote_only_groups', 'error_quote_only_api_key'] as $key) {
        assertTrue($admin->has($key), "admin language key '$key' is missing, so the UI shows the key itself");
    }

    $catalog = new Language();
    $catalog->load($root . '/catalog/language/en-gb/module/tackquotes.php');

    foreach (['error_quote_only', 'text_quote_only_heading', 'text_quote_only_body',
              'text_quote_only_cart_link', 'button_continue_shopping', 'text_home'] as $key) {
        assertTrue($catalog->has($key), "storefront language key '$key' is missing");
    }
});

check('the admin template exposes the error containers common.js fills', function () use ($root) {
    $twig = (string) file_get_contents($root . '/admin/view/template/module/tackquotes.twig');

    assertTrue(strpos($twig, 'id="error-quote-only"') !== false,
        'common.js maps error.quote_only to #error-quote-only; without it the "save an API '
            . 'key first" refusal is invisible and the merchant is told nothing');
    assertTrue(strpos($twig, 'id="error-quote-only-groups"') !== false,
        'and error.quote_only_groups to #error-quote-only-groups');
    assertTrue(strpos($twig, 'name="module_tackquotes_quote_only_groups[]"') !== false,
        'the group picker must post an ARRAY, which is what normaliseGroups() expects');
});

// ------------------------------------------------------- packaging: the zip name

echo "\nTackQuote for OpenCart — packaging\n";

check('the namespace-derived extension code still matches tack.ocmod.zip', function () use ($root) {
    $controller = (string) file_get_contents($root . '/admin/controller/module/tackquotes.php');

    assertTrue(
        preg_match('/^namespace Opencart\\\\Admin\\\\Controller\\\\Extension\\\\([A-Za-z0-9_]+)\\\\Module;/m',
            $controller, $m) === 1,
        'could not read the extension code out of the admin controller namespace'
    );

    assertSame('tack', strtolower($m[1]),
        'OpenCart derives the extension code from the ZIP FILENAME, and the shipped artifact is '
            . 'tack.ocmod.zip. Renaming this namespace without renaming the artifact (and every '
            . 'event action) ships an extension that installs and then 404s on every route.');
});

// ------------------------------------------------------------------------ result

echo "\n";

if ($failures) {
    echo count($failures) . " FAILED, $passed passed\n";
    foreach ($failures as $failure) {
        echo "  - $failure\n";
    }
    exit(1);
}

echo "$passed passed\n";
exit(0);
