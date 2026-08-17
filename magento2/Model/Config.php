<?php
/**
 * Reads TackQuote configuration (Stores > Configuration > TackQuote > TackQuote Settings).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

use Magento\Store\Model\ScopeInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;

class Config
{
    private const XML_PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const XML_PATH_API_BASE_URL = 'tackquote_quotes/general/api_base_url';
    private const XML_PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const XML_PATH_SHOW_BUTTON = 'tackquote_quotes/storefront/show_button';
    private const XML_PATH_BUTTON_LABEL = 'tackquote_quotes/storefront/button_label';
    private const XML_PATH_SHOW_ADD_TO_QUOTE = 'tackquote_quotes/storefront/show_add_to_quote';
    private const XML_PATH_ADD_TO_QUOTE_LABEL = 'tackquote_quotes/storefront/add_to_quote_label';
    private const XML_PATH_SHOW_ON_LISTING = 'tackquote_quotes/storefront/show_on_listing';
    private const XML_PATH_CHECKOUT_LABEL = 'tackquote_quotes/storefront/checkout_button_label';

    private const DEFAULT_API_BASE_URL = 'https://api.tackquote.com/v1';
    private const DEFAULT_BUTTON_LABEL = 'Request a Quote';
    private const DEFAULT_ADD_TO_QUOTE_LABEL = 'Add to Quote';
    private const DEFAULT_CHECKOUT_LABEL = 'Request quote for these items';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     */
    public function __construct(ScopeConfigInterface $scopeConfig, EncryptorInterface $encryptor)
    {
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
    }

    /**
     * Whether the module is enabled for the current website scope.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isEnabled(?int $storeId = null): bool
    {
        return (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * The TackQuote API base URL for the given store.
     *
     * @param int|null $storeId
     * @return string Base URL without trailing slash.
     */
    public function getApiBaseUrl(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_BASE_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $value = rtrim(trim($value), '/');

        return $value !== '' ? $value : self::DEFAULT_API_BASE_URL;
    }

    /**
     * The API key, decrypted.
     *
     * The field uses Magento\Config\Model\Config\Backend\Encrypted, so what is stored —
     * and what ScopeConfig hands back — is CIPHERTEXT of the form "0:3:…". Returning that
     * directly meant the module authenticated to TackQuote with an encrypted blob and
     * every request came back "Invalid API key", no matter how correct the key was. The
     * encrypted backend model encrypts on write; nothing decrypts on read unless it is
     * done here.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        $stored = (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($stored === '') {
            return '';
        }

        $decrypted = trim((string) $this->encryptor->decrypt($stored));
        if ($decrypted !== '') {
            return $decrypted;
        }

        // A value written straight to core_config_data (bin/magento config:set on an older
        // Magento, a migration, a fixture) is not ciphertext, and decrypt() returns ''
        // for it. Falling back to the raw value keeps those installs working rather than
        // silently treating a valid key as absent.
        return trim($stored);
    }

    /**
     * Whether the module is enabled AND has an API key, i.e. can actually call TackQuote.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isConfigured(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getApiKey($storeId) !== '';
    }

    /**
     * Whether the single-product "Request a Quote" button renders on product pages.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isButtonEnabled(?int $storeId = null): bool
    {
        return $this->isConfigured($storeId) && (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_BUTTON,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Label for the single-product "Request a Quote" button.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getButtonLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_BUTTON_LABEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? $value : self::DEFAULT_BUTTON_LABEL;
    }

    /**
     * Whether shoppers can collect several products into a quote list.
     *
     * Gated on isConfigured() for the same reason as the single-product button: without
     * an API key nothing can be submitted, so offering the control would only produce a
     * dead end.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isAddToQuoteEnabled(?int $storeId = null): bool
    {
        return $this->isConfigured($storeId) && (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_ADD_TO_QUOTE,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Label for the "Add to Quote" control.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getAddToQuoteLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_ADD_TO_QUOTE_LABEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? $value : self::DEFAULT_ADD_TO_QUOTE_LABEL;
    }

    /**
     * Whether the "Add to Quote" control also appears on category and search listings.
     *
     * @param int|null $storeId
     * @return bool
     */
    public function isListingButtonEnabled(?int $storeId = null): bool
    {
        return $this->isAddToQuoteEnabled($storeId) && (bool) $this->scopeConfig->isSetFlag(
            self::XML_PATH_SHOW_ON_LISTING,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Label for the button that submits the whole quote list.
     *
     * @param int|null $storeId
     * @return string
     */
    public function getCheckoutButtonLabel(?int $storeId = null): string
    {
        $value = (string) $this->scopeConfig->getValue(
            self::XML_PATH_CHECKOUT_LABEL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        return $value !== '' ? $value : self::DEFAULT_CHECKOUT_LABEL;
    }
}
