<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Test\Service;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use TackQuote\TackQuote\Service\TackQuoteApiClient;

/**
 * Asserts on the REQUEST THAT GOES OUT, not just on a mocked response.
 *
 * The repository's own integration audit found 20+ connectors that had never worked for
 * any tenant precisely because their tests only checked a canned response and so encoded
 * the same wrong assumptions as the code (see CLAUDE.md, "Integrations: verify against
 * vendor docs"). Every test below therefore captures the outgoing request and inspects
 * its method, URL and JSON body.
 *
 * The contract being pinned is `POST {apiUrl}/widget/quote-request` in
 * apps/api/src/modules/quotes/widget.controller.ts (@Public(), tenant resolved from
 * `tenantSlug`).
 */
#[CoversClass(TackQuoteApiClient::class)]
class TackQuoteApiClientTest extends TestCase
{
    private const SLUG = 'demo';

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $sent = [];

    /**
     * @param array<string, mixed> $config
     */
    private function client(
        array $config = [],
        int $status = 200,
        string $responseBody = '{"success":true,"quoteNumber":"TK-2026-000001"}',
        bool $transportError = false
    ): TackQuoteApiClient {
        $config += ['tenantSlug' => self::SLUG];

        // A stub, not a mock: the config service is only a source of return values here
        // and no call on it is ever verified. PHPUnit 12 emits a notice for an
        // expectation-less mock, and it is right to.
        $systemConfig = $this->createStub(SystemConfigService::class);
        $systemConfig->method('get')->willReturnCallback(
            static function (string $key) use ($config) {
                return $config[str_replace('TackQuote.config.', '', $key)] ?? null;
            }
        );

        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($status, $responseBody, $transportError) {
            $this->sent[] = [
                'method' => $method,
                'url' => $url,
                'body' => json_decode($options['body'] ?? '{}', true) ?: [],
            ];

            if ($transportError) {
                // MockResponse turns an ErrorException in the info into a genuine
                // TransportExceptionInterface, which is what the client catches.
                return new MockResponse('', ['error' => 'connection refused']);
            }

            return new MockResponse($responseBody, [
                'http_code' => $status,
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        return new TackQuoteApiClient($http, $systemConfig);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastBody(): array
    {
        static::assertNotEmpty($this->sent, 'No HTTP request was made.');

        return $this->sent[array_key_last($this->sent)]['body'];
    }

    /**
     * @return array<int, array{name: string, sku?: string, quantity: int, unitPrice?: float}>
     */
    private static function items(): array
    {
        return [['name' => 'Enormous Copper Car', 'sku' => 'SW10001', 'quantity' => 40, 'unitPrice' => 900.29]];
    }

    /**
     * @return array{firstName: string, email: string}
     */
    private static function buyer(): array
    {
        return ['firstName' => 'Ada', 'email' => 'ada@example.com'];
    }

    // ── the wire contract ───────────────────────────────────────────────────────

    public function testPostsToTheWidgetQuoteRequestEndpointWithTheTenantSlug(): void
    {
        $client = $this->client(['apiUrl' => 'http://api:3001/v1']);

        $client->submitQuoteRequest(self::buyer(), self::items(), 'hello', null, 'EUR');

        static::assertSame('POST', $this->sent[0]['method']);
        static::assertSame('http://api:3001/v1/widget/quote-request', $this->sent[0]['url']);
        static::assertSame(self::SLUG, $this->lastBody()['tenantSlug']);
        static::assertSame(self::items(), $this->lastBody()['items']);
        static::assertSame('hello', $this->lastBody()['message']);
    }

    public function testTrailingSlashOnTheConfiguredUrlDoesNotProduceADoubleSlash(): void
    {
        $client = $this->client(['apiUrl' => 'http://api:3001/v1/']);

        $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');

        static::assertSame('http://api:3001/v1/widget/quote-request', $this->sent[0]['url']);
    }

    public function testReturnsTheDecodedResponse(): void
    {
        $client = $this->client();

        $result = $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');

        static::assertSame('TK-2026-000001', $result['quoteNumber']);
    }

    // ── currency ───────────────────────────────────────────────────────────────

    /**
     * A EUR store used to get USD quotes because neither side sent a currency and the
     * API defaulted to USD. The code is only sent when it is a plausible ISO 4217
     * alpha-3, so a misconfigured store degrades to that default instead of writing
     * junk into the quote's currency column.
     */
    #[DataProvider('currencies')]
    public function testSendsTheCurrencyOnlyWhenItLooksLikeIso4217(?string $input, ?string $expected): void
    {
        $client = $this->client();

        $client->submitQuoteRequest(self::buyer(), self::items(), '', null, $input);

        if ($expected === null) {
            static::assertArrayNotHasKey(
                'currency',
                $this->lastBody(),
                'An unusable currency must be omitted so the API applies its own default.'
            );

            return;
        }

        static::assertSame($expected, $this->lastBody()['currency']);
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function currencies(): iterable
    {
        yield 'plain alpha-3'      => ['EUR', 'EUR'];
        yield 'lower-cased'        => ['eur', 'EUR'];
        yield 'padded'            => ['  gbp  ', 'GBP'];
        yield 'null omitted'       => [null, null];
        yield 'empty omitted'      => ['', null];
        yield 'two letters'        => ['EU', null];
        yield 'four letters'       => ['EUROS', null];
        yield 'digits'             => ['123', null];
        yield 'symbol'             => ['€', null];
    }

    // ── failure modes: no silent success ────────────────────────────────────────

    public function testThrowsWhenNoTenantSlugIsConfigured(): void
    {
        // isConfigured() is false here; submitting anyway must fail loudly rather than
        // POST a slug-less body the API would reject with a confusing message.
        $client = $this->client(['tenantSlug' => null]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/tenant slug/i');

        $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');
        static::assertSame([], $this->sent, 'Nothing should have been sent.');
    }

    public function testThrowsWhenBuyerEmailIsMissing(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);

        $client->submitQuoteRequest(['firstName' => 'Ada'], self::items(), '', null, 'EUR');
    }

    public function testThrowsWhenThereAreNoLineItems(): void
    {
        $client = $this->client();

        $this->expectException(\InvalidArgumentException::class);

        $client->submitQuoteRequest(self::buyer(), [], '', null, 'EUR');
    }

    /**
     * A 4xx/5xx must surface the API's own message. Reporting success on a rejected
     * request is the "fabricated success" defect shape this repo has been bitten by.
     */
    public function testSurfacesTheApiMessageOnAnHttpError(): void
    {
        $client = $this->client([], 400, '{"success":false,"message":"A required field is missing."}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A required field is missing.');

        $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');
    }

    /**
     * A 200 carrying `success: false` must NOT be treated as a created quote — the
     * status code alone is not the signal.
     */
    public function testTreatsSuccessFalseOnA200AsAFailure(): void
    {
        $client = $this->client([], 200, '{"success":false,"message":"Tenant not found."}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Tenant not found.');

        $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');
    }

    public function testWrapsATransportFailureInARuntimeException(): void
    {
        $client = $this->client([], 200, '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/could not reach/i');

        try {
            $client->submitQuoteRequest(self::buyer(), self::items(), '', null, 'EUR');
        } catch (\RuntimeException $e) {
            static::assertInstanceOf(TransportExceptionInterface::class, $e->getPrevious());

            throw $e;
        }
    }

    /**
     * REGRESSION GUARD for the two-repo drift that produced issue #340.
     *
     * `ping()` was added in the monorepo (GitHub #330) to give `getApiKey()` a
     * consumer: before it, the plugin config asked a merchant to paste a TackQuote
     * API key and then never read it — a field that looks like a working
     * credential and is decoration.
     *
     * That fix never reached the PUBLISHED copy of this plugin, so every release
     * up to and including v1.5.0 shipped the decorative field. It has now been
     * carried across. This test exists so a future re-sync cannot silently drop it
     * again: if `ping()` disappears, or stops consulting the stored key, this
     * fails rather than the defect shipping unnoticed.
     */
    public function testPingExistsAndConsumesTheStoredApiKey(): void
    {
        self::assertTrue(
            method_exists(TackQuoteApiClient::class, 'ping'),
            'ping() is missing — the API key field has no consumer again (see #330/#340).'
        );

        $source = file_get_contents(__DIR__ . '/../../src/Service/TackQuoteApiClient.php');
        self::assertIsString($source);

        // Strip comments, so prose mentioning getApiKey cannot satisfy this.
        $code = preg_replace('#/\*.*?\*/#s', '', $source);
        $code = preg_replace('#//[^\n]*#', '', (string) $code);
        $ping = strstr((string) $code, 'function ping');
        self::assertIsString($ping, 'ping() body not found in source');

        self::assertStringContainsString(
            'getApiKey(',
            $ping,
            'ping() no longer reads the stored API key, which is the whole point of it.'
        );
    }
}
