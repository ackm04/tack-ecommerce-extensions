<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;

/**
 * @covers \TackQuote\Quotes\Model\Config
 */
class ConfigTest extends TestCase
{
    private const PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const PATH_API_BASE_URL = 'tackquote_quotes/general/api_base_url';
    private const PATH_SHOW_BUTTON = 'tackquote_quotes/storefront/show_button';
    private const PATH_SHOW_ADD_TO_QUOTE = 'tackquote_quotes/storefront/show_add_to_quote';
    private const PATH_SHOW_ON_LISTING = 'tackquote_quotes/storefront/show_on_listing';

    /**
     * @var ScopeConfigInterface&MockObject
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface&MockObject
     */
    private $encryptor;

    /**
     * @var Config
     */
    private $config;

    protected function setUp(): void
    {
        $this->scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $this->encryptor = $this->createMock(EncryptorInterface::class);
        $this->config = new Config($this->scopeConfig, $this->encryptor);
    }

    /**
     * Stub getValue()/isSetFlag() from a path => value map.
     *
     * @param array<string, mixed> $values
     * @param array<string, bool> $flags
     * @return void
     */
    private function stubConfig(array $values = [], array $flags = []): void
    {
        $this->scopeConfig->method('getValue')
            ->willReturnCallback(static function ($path) use ($values) {
                return $values[$path] ?? null;
            });
        $this->scopeConfig->method('isSetFlag')
            ->willReturnCallback(static function ($path) use ($flags) {
                return $flags[$path] ?? false;
            });
    }

    /**
     * The whole point of getApiKey(): the field's backend model is
     * Magento\Config\Model\Config\Backend\Encrypted, so what comes back from ScopeConfig is
     * ciphertext of the form "0:3:…". Returning it verbatim authenticated to TackQuote with
     * an encrypted blob and every call came back "Invalid API key".
     */
    public function testGetApiKeyDecryptsAnEncryptedStoredValue(): void
    {
        $ciphertext = '0:3:Dwv1Q2n+2M0wZ6iVQ4pQpX8Nz0zH3d5nJj0rQ1a2b3c4d5e6==';

        $this->stubConfig([self::PATH_API_KEY => $ciphertext]);

        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->with($ciphertext)
            ->willReturn('tq_live_realkey123');

        $this->assertSame('tq_live_realkey123', $this->config->getApiKey());
    }

    /**
     * decrypt() returns '' for a value that was never ciphertext (config:set on an older
     * Magento, a migration, a fixture). Treating that as "no key" would silently disable a
     * correctly configured store.
     */
    public function testGetApiKeyFallsBackToTheRawValueWhenDecryptReturnsEmpty(): void
    {
        $this->stubConfig([self::PATH_API_KEY => '  tq_plaintext_key  ']);

        $this->encryptor->expects($this->once())
            ->method('decrypt')
            ->willReturn('');

        $this->assertSame('tq_plaintext_key', $this->config->getApiKey());
    }

    public function testGetApiKeyReturnsEmptyWithoutCallingDecryptWhenNothingIsStored(): void
    {
        $this->stubConfig([self::PATH_API_KEY => '']);

        $this->encryptor->expects($this->never())->method('decrypt');

        $this->assertSame('', $this->config->getApiKey());
    }

    public function testGetApiKeyTrimsWhitespaceAroundTheDecryptedValue(): void
    {
        $this->stubConfig([self::PATH_API_KEY => '0:3:abc']);

        $this->encryptor->method('decrypt')->willReturn("  tq_live_key\n");

        $this->assertSame('tq_live_key', $this->config->getApiKey());
    }

    public function testIsConfiguredRequiresBothTheEnabledFlagAndAKey(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [self::PATH_ENABLED => true]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertTrue($this->config->isConfigured());
    }

    public function testIsConfiguredIsFalseWhenTheModuleIsDisabledEvenWithAKey(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [self::PATH_ENABLED => false]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertFalse($this->config->isConfigured());
    }

    /**
     * Without a key nothing can be submitted, so the button must not render even when the
     * merchant has ticked both "Enable" and "Show button". This is the "no key means no
     * button, and no error either" behaviour — it is deliberate, and it is what
     * isConfigured() gating buys.
     */
    public function testIsButtonEnabledIsFalseWithoutAnApiKeyEvenWhenBothFlagsAreOn(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => ''],
            [self::PATH_ENABLED => true, self::PATH_SHOW_BUTTON => true]
        );

        $this->assertFalse($this->config->isButtonEnabled());
    }

    public function testIsButtonEnabledIsTrueWhenConfiguredAndTheFlagIsOn(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [self::PATH_ENABLED => true, self::PATH_SHOW_BUTTON => true]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertTrue($this->config->isButtonEnabled());
    }

    public function testIsButtonEnabledIsFalseWhenTheShowButtonFlagIsOff(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [self::PATH_ENABLED => true, self::PATH_SHOW_BUTTON => false]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertFalse($this->config->isButtonEnabled());
    }

    public function testIsAddToQuoteEnabledIsFalseWithoutAnApiKey(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => ''],
            [self::PATH_ENABLED => true, self::PATH_SHOW_ADD_TO_QUOTE => true]
        );

        $this->assertFalse($this->config->isAddToQuoteEnabled());
    }

    /**
     * The listing control is a placement of the Add-to-Quote feature, not a feature of its
     * own: turning it on while Add to Quote is off would render a button that leads nowhere.
     */
    public function testIsListingButtonEnabledIsFalseWhenAddToQuoteIsOff(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [
                self::PATH_ENABLED => true,
                self::PATH_SHOW_ADD_TO_QUOTE => false,
                self::PATH_SHOW_ON_LISTING => true,
            ]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertFalse($this->config->isListingButtonEnabled());
    }

    public function testIsListingButtonEnabledIsTrueOnlyWhenBothAddToQuoteAndListingAreOn(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => '0:3:abc'],
            [
                self::PATH_ENABLED => true,
                self::PATH_SHOW_ADD_TO_QUOTE => true,
                self::PATH_SHOW_ON_LISTING => true,
            ]
        );
        $this->encryptor->method('decrypt')->willReturn('tq_live_key');

        $this->assertTrue($this->config->isListingButtonEnabled());
    }

    public function testIsListingButtonEnabledIsFalseWithoutAnApiKeyEvenWhenEveryFlagIsOn(): void
    {
        $this->stubConfig(
            [self::PATH_API_KEY => ''],
            [
                self::PATH_ENABLED => true,
                self::PATH_SHOW_ADD_TO_QUOTE => true,
                self::PATH_SHOW_ON_LISTING => true,
            ]
        );

        $this->assertFalse($this->config->isListingButtonEnabled());
    }

    public function testGetApiBaseUrlStripsTrailingSlashAndFallsBackToTheDefault(): void
    {
        $this->stubConfig([self::PATH_API_BASE_URL => ' https://api.example.test/v1/ ']);

        $this->assertSame('https://api.example.test/v1', $this->config->getApiBaseUrl());
    }

    public function testGetApiBaseUrlReturnsTheDefaultWhenUnset(): void
    {
        $this->stubConfig([self::PATH_API_BASE_URL => '']);

        $this->assertSame('https://api.tackquote.com/v1', $this->config->getApiBaseUrl());
    }

    public function testLabelsFallBackToTheirDefaults(): void
    {
        $this->stubConfig([]);

        $this->assertSame('Request a Quote', $this->config->getButtonLabel());
        $this->assertSame('Add to Quote', $this->config->getAddToQuoteLabel());
        $this->assertSame('Request quote for these items', $this->config->getCheckoutButtonLabel());
    }
}
