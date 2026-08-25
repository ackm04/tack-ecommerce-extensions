<?php
/**
 * Model/Api/Client.php had no test of its own — it was only ever mocked by its callers —
 * which is how both of the defects pinned below survived review.
 *
 * Both are about what the client does with things nobody looks at: a log line, and a
 * timeout constant. Neither shows up in a response, so neither could fail a caller's test.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Api;

use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Api\Client
 */
class ClientTest extends TestCase
{
    private const API_BASE = 'https://api.tackquote.test/v1';

    /** @var Config&MockObject */
    private $config;

    /** @var Curl&MockObject */
    private $curl;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var int[] Every timeout the client set, in order. */
    private $timeouts = [];

    /** @var array<string, string> Headers the client added. */
    private $headers = [];

    protected function setUp(): void
    {
        $this->config = $this->createMock(Config::class);
        $this->config->method('getApiKey')->willReturn('tq_live_key');
        $this->config->method('getApiBaseUrl')->willReturn(self::API_BASE);

        $this->curl = $this->createMock(Curl::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->timeouts = [];
        $this->headers = [];
        $this->curl->method('setTimeout')->willReturnCallback(
            function ($seconds): void {
                $this->timeouts[] = (int) $seconds;
            }
        );
        $this->curl->method('addHeader')->willReturnCallback(
            function ($name, $value): void {
                $this->headers[(string) $name] = (string) $value;
            }
        );
    }

    private function client(): Client
    {
        // The real Json serializer: the point of several of these tests is what happens to
        // a body as it is parsed, and a mock would simply return whatever it was told.
        return new Client($this->config, $this->curl, new Json(), $this->logger);
    }

    private function respondWith(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    // ---------------------------------------------------------------- PII in var/log

    public function testAValidationErrorDoesNotWriteTheBuyerDocumentIntoTheLog(): void
    {
        // THE TEST THIS FIX EXISTS AROUND. TackQuote echoes the offending payload back in a
        // 400, so logging the raw body wrote a real person's contact details into
        // var/log/system.log on every mistyped field.
        $echoed = json_encode([
            'message' => 'companyName is required',
            'received' => [
                'buyerEmail' => 'jane.doe@acme-industrial.example',
                'firstName' => 'Jane',
                'lastName' => 'Doe',
                'phone' => '+44 7700 900123',
                'company' => ['name' => 'Acme Industrial Ltd', 'addressLine1' => '17 Mill Lane'],
            ],
        ], JSON_THROW_ON_ERROR);

        $this->respondWith(400, $echoed);

        $logged = [];
        $this->logger->method('warning')->willReturnCallback(
            static function ($message) use (&$logged): void {
                $logged[] = (string) $message;
            }
        );

        $result = $this->client()->createQuoteRequest(['buyerEmail' => 'jane.doe@acme-industrial.example']);

        self::assertFalse($result['ok']);
        self::assertCount(1, $logged);

        foreach ([
            'jane.doe@acme-industrial.example',
            'Acme Industrial Ltd',
            '17 Mill Lane',
            '+44 7700 900123',
            'Doe',
        ] as $pii) {
            self::assertStringNotContainsString(
                $pii,
                $logged[0],
                sprintf('%s reached var/log — the raw response body is being logged again', $pii)
            );
        }
    }

    public function testTheLogStillCarriesEnoughToDiagnoseTheFailure(): void
    {
        // Redaction must not go so far that an operator cannot tell a rejected key from a
        // missing field from TackQuote being down. Status + the API's own message is the
        // agreed minimum.
        $this->respondWith(401, json_encode(['message' => 'API key rejected'], JSON_THROW_ON_ERROR));

        $this->logger->expects(self::once())
            ->method('warning')
            ->with(self::logicalAnd(
                self::stringContains('401'),
                self::stringContains('API key rejected')
            ));

        $this->client()->testConnection();
    }

    public function testAnUnparseableErrorBodyIsNotLoggedEitherEvenThoughNothingWasExtracted(): void
    {
        // The tempting "fall back to the raw body when we could not parse it" is exactly
        // the leak again: an unparseable body is no less likely to contain the document.
        $this->respondWith(500, '<html><body>jane.doe@acme-industrial.example</body></html>');

        $logged = [];
        $this->logger->method('warning')->willReturnCallback(
            static function ($message) use (&$logged): void {
                $logged[] = (string) $message;
            }
        );

        $this->client()->testConnection();

        self::assertCount(1, $logged);
        self::assertStringNotContainsString('jane.doe@acme-industrial.example', $logged[0]);
        self::assertStringContainsString('500', $logged[0]);
    }

    // ---------------------------------------------------------------- timeout by verb

    public function testAReadGetsTheShortBudgetSoItCannotHoldAPageOpen(): void
    {
        // Block\QuoteList renders on every storefront page; a read sharing the write budget
        // is how a hung TackQuote held a shopper's page for twenty seconds.
        $this->respondWith(200, '{}');

        $this->client()->getRegistrationConfig();

        self::assertSame([3], $this->timeouts);
    }

    public function testTheConnectivityPingAlsoUsesTheShortBudget(): void
    {
        $this->respondWith(200, '{}');

        $this->client()->testConnection();

        self::assertSame([3], $this->timeouts);
    }

    public function testAWriteKeepsTheGenerousBudgetBecauseAnAbortedPostMayHaveLanded(): void
    {
        // Deliberately NOT shortened with the reads: cutting a POST short can leave a quote
        // created server-side that the shopper is told failed.
        $this->respondWith(201, '{}');

        $this->client()->createQuoteRequest(['buyerEmail' => 'buyer@example.test']);

        self::assertSame([20], $this->timeouts);
    }

    public function testTheReadBudgetIsStrictlyShorterThanTheWriteBudget(): void
    {
        $this->respondWith(200, '{}');
        $this->client()->getRegistrationConfig();
        $read = $this->timeouts;

        $this->setUp();
        $this->respondWith(201, '{}');
        $this->client()->createQuoteRequest([]);
        $write = $this->timeouts;

        self::assertLessThan($write[0], $read[0]);
    }

    // ---------------------------------------------------------------- idempotency header

    public function testTheIdempotencyKeyIsSentWhenOneIsSupplied(): void
    {
        $this->respondWith(201, '{}');

        $this->client()->createQuoteRequest([], null, 'tq-magento-abc123');

        self::assertArrayHasKey('Idempotency-Key', $this->headers);
        self::assertSame('tq-magento-abc123', $this->headers['Idempotency-Key']);
    }

    public function testNoIdempotencyHeaderIsSentWhenThereIsNoKey(): void
    {
        $this->respondWith(200, '{}');

        $this->client()->getRegistrationConfig();

        self::assertArrayNotHasKey('Idempotency-Key', $this->headers);
    }

    public function testAnUnconfiguredStoreFailsWithoutTouchingTheNetwork(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiKey')->willReturn('');
        $this->curl->expects(self::never())->method('get');
        $this->curl->expects(self::never())->method('post');

        $result = (new Client($config, $this->curl, new Json(), $this->logger))->testConnection();

        self::assertFalse($result['ok']);
    }
}
