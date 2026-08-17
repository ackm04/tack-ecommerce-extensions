<?php
/**
 * Input-validation coverage for the storefront submit controller.
 *
 * Scope note: only the pure input-validation branches are exercised here. The happy path
 * runs through Client -> Curl and the ResultFactory, which is integration territory; what
 * is asserted below is everything the controller decides on its own before any network or
 * catalog access.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Controller\Quote;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Controller\Quote\Submit;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\IdempotencyGuard;
use TackQuote\Quotes\Model\ProductQuoteResolver;
use TackQuote\Quotes\Model\SubmissionThrottle;

/**
 * @covers \TackQuote\Quotes\Controller\Quote\Submit
 */
class SubmitTest extends TestCase
{
    /**
     * @var Config&MockObject
     */
    private $config;

    /**
     * @var Client&MockObject
     */
    private $client;

    /**
     * @var ProductQuoteResolver&MockObject
     */
    private $productResolver;

    /**
     * @var SubmissionThrottle&MockObject
     */
    private $throttle;

    /**
     * @var IdempotencyGuard&MockObject
     */
    private $idempotency;

    /**
     * @var CustomerSession&MockObject
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface&MockObject
     */
    private $storeManager;

    /**
     * @var FormKey&MockObject
     */
    private $formKey;

    /**
     * @var RequestInterface&MockObject
     */
    private $request;

    /**
     * Params the request mock will answer with.
     *
     * @var array<string, mixed>
     */
    private $params = [];

    /**
     * Data handed to the JSON result, and the HTTP code set on it.
     *
     * @var array<string, mixed>|null
     */
    private $responseData;

    /**
     * @var int|null
     */
    private $responseCode;

    protected function setUp(): void
    {
        $this->params = [];
        $this->responseData = null;
        $this->responseCode = null;

        $this->config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured'])
            ->getMock();
        $this->config->method('isConfigured')->willReturn(true);

        $this->client = $this->getMockBuilder(Client::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['createQuoteRequest'])
            ->getMock();

        $this->productResolver = $this->getMockBuilder(ProductQuoteResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();

        $this->throttle = $this->getMockBuilder(SubmissionThrottle::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isExceeded', 'getRetryAfter'])
            ->getMock();
        $this->throttle->method('isExceeded')->willReturn(false);

        $this->idempotency = $this->getMockBuilder(IdempotencyGuard::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'remember'])
            ->getMock();
        $this->idempotency->method('get')->willReturn(null);

        $this->customerSession = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomer'])
            ->getMock();
        $this->customerSession->method('isLoggedIn')->willReturn(false);

        $this->storeManager = $this->createMock(StoreManagerInterface::class);

        $this->formKey = $this->getMockBuilder(FormKey::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getFormKey'])
            ->getMock();

        $this->request = $this->createMock(RequestInterface::class);
        $this->request->method('getParam')
            ->willReturnCallback(function ($key, $default = null) {
                return array_key_exists($key, $this->params) ? $this->params[$key] : $default;
            });
    }

    /**
     * @return Submit
     */
    private function controller(): Submit
    {
        $result = $this->createMock(JsonResult::class);
        $result->method('setHttpResponseCode')
            ->willReturnCallback(function ($code) use (&$result) {
                $this->responseCode = (int) $code;

                return $result;
            });
        $result->method('setData')
            ->willReturnCallback(function ($data) use (&$result) {
                $this->responseData = $data;

                return $result;
            });

        $resultFactory = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturn($result);

        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRequest', 'getResultFactory'])
            ->getMock();
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        return new Submit(
            $context,
            $this->config,
            $this->client,
            $this->productResolver,
            $this->throttle,
            $this->idempotency,
            $this->customerSession,
            $this->storeManager,
            $this->createMock(LoggerInterface::class),
            $this->formKey
        );
    }

    /**
     * Call a private method without changing its visibility in the source.
     *
     * @param Submit $subject
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    private function invokePrivate(Submit $subject, string $method, array $args)
    {
        $reflection = new \ReflectionMethod(Submit::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($subject, $args);
    }

    public function testAnInvalidEmailIsRejectedWithAFieldLevelError(): void
    {
        $this->params = [
            'email' => 'not-an-email',
            'firstName' => 'Ada',
            'sku' => 'SKU-1',
            'qty' => 1,
        ];

        $this->client->expects($this->never())->method('createQuoteRequest');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertFalse($this->responseData['success']);
        $this->assertSame('email', $this->responseData['field']);
        $this->assertSame('A valid email address is required.', $this->responseData['message']);
    }

    public function testAMissingEmailIsRejected(): void
    {
        $this->params = ['firstName' => 'Ada', 'sku' => 'SKU-1', 'qty' => 1];

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('email', $this->responseData['field']);
    }

    public function testAMissingFirstNameIsRejectedWithAFieldLevelError(): void
    {
        $this->params = [
            'email' => 'ada@example.test',
            'firstName' => '   ',
            'sku' => 'SKU-1',
            'qty' => 1,
        ];

        $this->client->expects($this->never())->method('createQuoteRequest');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('firstName', $this->responseData['field']);
        $this->assertSame('Your first name is required.', $this->responseData['message']);
    }

    public function testAQuantityBelowOneIsRejected(): void
    {
        $this->params = [
            'email' => 'ada@example.test',
            'firstName' => 'Ada',
            'sku' => 'SKU-1',
            'qty' => 0,
        ];

        $this->productResolver->expects($this->never())->method('resolve');
        $this->client->expects($this->never())->method('createQuoteRequest');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('qty', $this->responseData['field']);
        $this->assertSame('Enter a quantity of at least 1.', $this->responseData['message']);
    }

    public function testANegativeQuantityIsRejected(): void
    {
        $this->params = [
            'email' => 'ada@example.test',
            'firstName' => 'Ada',
            'sku' => 'SKU-1',
            'qty' => -5,
        ];

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('qty', $this->responseData['field']);
    }

    public function testAnAbsurdQuantityIsRejected(): void
    {
        $this->params = [
            'email' => 'ada@example.test',
            'firstName' => 'Ada',
            'sku' => 'SKU-1',
            'qty' => 1000001,
        ];

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('qty', $this->responseData['field']);
    }

    public function testAnUnconfiguredStoreIsRejectedBeforeAnythingElseHappens(): void
    {
        $config = $this->getMockBuilder(Config::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isConfigured'])
            ->getMock();
        $config->method('isConfigured')->willReturn(false);
        $this->config = $config;

        $this->throttle->expects($this->never())->method('isExceeded');

        $this->controller()->execute();

        $this->assertSame(400, $this->responseCode);
        $this->assertSame('TackQuote is not configured for this store.', $this->responseData['message']);
    }

    /**
     * The endpoint is unauthenticated from the internet's point of view, so the rate limit
     * must be applied before any catalog or API work.
     */
    public function testAThrottledClientIsRejectedWith429BeforeAnyValidation(): void
    {
        $throttle = $this->getMockBuilder(SubmissionThrottle::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isExceeded', 'getRetryAfter'])
            ->getMock();
        $throttle->method('isExceeded')->willReturn(true);
        $this->throttle = $throttle;

        $this->productResolver->expects($this->never())->method('resolve');
        $this->client->expects($this->never())->method('createQuoteRequest');

        $this->controller()->execute();

        $this->assertSame(429, $this->responseCode);
        $this->assertFalse($this->responseData['success']);
    }

    /**
     * A crafted form must not be able to smuggle arbitrary keys into the company record.
     */
    public function testCollectCompanyDetailsKeepsOnlyTheWhitelistedFields(): void
    {
        $this->params = [
            'company' => [
                'phone' => '+1 555 0100',
                'website' => 'https://acme.test',
                'taxRegistrationNumber' => 'GB123456789',
                'addressLine1' => '1 Test Way',
                'addressLine2' => 'Unit 4',
                'city' => 'Springfield',
                'state' => 'IL',
                'postalCode' => '62704',
                'country' => 'US',
                'contactTitle' => 'Head of Procurement',
                // Everything below must be dropped.
                'id' => 'c-hijacked',
                'tenantId' => 't-hijacked',
                'creditLimit' => '999999',
                'isApproved' => '1',
                'name' => 'Not Acme',
            ],
        ];

        $company = $this->invokePrivate($this->controller(), 'collectCompanyDetails', [$this->request]);

        $this->assertSame(
            [
                'phone',
                'website',
                'taxRegistrationNumber',
                'addressLine1',
                'addressLine2',
                'city',
                'state',
                'postalCode',
                'country',
                'contactTitle',
            ],
            array_keys($company)
        );
        $this->assertArrayNotHasKey('id', $company);
        $this->assertArrayNotHasKey('tenantId', $company);
        $this->assertArrayNotHasKey('creditLimit', $company);
        $this->assertArrayNotHasKey('isApproved', $company);
    }

    public function testCollectCompanyDetailsTrimsValuesAndDropsEmptyOnes(): void
    {
        $this->params = [
            'company' => [
                'phone' => '  +1 555 0100  ',
                'city' => '   ',
                'country' => 'US',
            ],
        ];

        $company = $this->invokePrivate($this->controller(), 'collectCompanyDetails', [$this->request]);

        $this->assertSame(['phone' => '+1 555 0100', 'country' => 'US'], $company);
    }

    public function testCollectCompanyDetailsReturnsEmptyWhenCompanyIsNotAnArray(): void
    {
        $this->params = ['company' => 'Acme Ltd'];

        $this->assertSame(
            [],
            $this->invokePrivate($this->controller(), 'collectCompanyDetails', [$this->request])
        );
    }

    public function testCollectCompanyDetailsReturnsEmptyWhenNothingIsPosted(): void
    {
        $this->assertSame(
            [],
            $this->invokePrivate($this->controller(), 'collectCompanyDetails', [$this->request])
        );
    }

    /**
     * The list lives in localStorage where the shopper can edit it freely, so the payload is
     * bounded before any per-row work. 50 matches MAX_ITEMS on the Tack side, so a list that
     * submits here is never silently truncated there.
     */
    public function testParseItemsBoundsTheListToFiftyRows(): void
    {
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['sku' => 'SKU-' . $i, 'qty' => 1];
        }

        $parsed = $this->invokePrivate($this->controller(), 'parseItems', [json_encode($rows)]);

        $this->assertCount(50, $parsed);
        $this->assertSame('SKU-0', $parsed[0]['sku']);
        $this->assertSame('SKU-49', $parsed[49]['sku']);
    }

    public function testParseItemsIgnoresNonArrayRows(): void
    {
        $raw = json_encode([
            ['sku' => 'SKU-A', 'qty' => 2],
            'garbage',
            42,
            null,
            true,
            ['sku' => 'SKU-B', 'qty' => 3],
        ]);

        $parsed = $this->invokePrivate($this->controller(), 'parseItems', [$raw]);

        $this->assertCount(2, $parsed);
        $this->assertSame('SKU-A', $parsed[0]['sku']);
        $this->assertSame('SKU-B', $parsed[1]['sku'], 'surviving rows must be re-indexed contiguously');
    }

    public function testParseItemsBoundsToFiftyAfterDiscardingNonArrayRows(): void
    {
        $rows = [];
        for ($i = 0; $i < 80; $i++) {
            $rows[] = 'junk-' . $i;
            $rows[] = ['sku' => 'SKU-' . $i, 'qty' => 1];
        }

        $parsed = $this->invokePrivate($this->controller(), 'parseItems', [json_encode($rows)]);

        $this->assertCount(50, $parsed);
        $this->assertSame('SKU-0', $parsed[0]['sku']);
        $this->assertSame('SKU-49', $parsed[49]['sku']);
    }

    /**
     * @dataProvider unusableItemsPayloadProvider
     * @param mixed $raw
     * @return void
     */
    public function testParseItemsReturnsEmptyForAnythingUnusable($raw): void
    {
        $this->assertSame([], $this->invokePrivate($this->controller(), 'parseItems', [$raw]));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableItemsPayloadProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
            'whitespace' => ['   '],
            'already an array' => [[['sku' => 'SKU-A']]],
            'invalid json' => ['[{"sku": '],
            'json scalar' => ['"SKU-A"'],
            'json null' => ['null'],
            'integer' => [7],
        ];
    }

    public function testValidateForCsrfAcceptsAMatchingFormKey(): void
    {
        $this->params = ['form_key' => 'abc123'];
        $this->formKey->method('getFormKey')->willReturn('abc123');

        $this->assertTrue($this->controller()->validateForCsrf($this->request));
    }

    public function testValidateForCsrfRejectsAMissingOrMismatchedFormKey(): void
    {
        $this->formKey->method('getFormKey')->willReturn('abc123');

        $controller = $this->controller();

        $this->assertFalse($controller->validateForCsrf($this->request));

        $this->params = ['form_key' => 'wrong'];
        $this->assertFalse($controller->validateForCsrf($this->request));
    }
}
