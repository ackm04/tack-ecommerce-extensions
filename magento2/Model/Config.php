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

class Config
{
    private const XML_PATH_ENABLED = 'tackquote_quotes/general/enabled';
    private const XML_PATH_API_BASE_URL = 'tackquote_quotes/general/api_base_url';
    private const XML_PATH_API_KEY = 'tackquote_quotes/general/api_key';
    private const XML_PATH_SHOW_BUTTON = 'tackquote_quotes/storefront/show_button';
    private const XML_PATH_BUTTON_LABEL = 'tackquote_quotes/storefront/button_label';

    private const DEFAULT_API_BASE_URL = 'https://api.tackquote.com/v1';
    private const DEFAULT_BUTTON_LABEL = 'Request a Quote';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(ScopeConfigInterface $scopeConfig)
    {
        $this->scopeConfig = $scopeConfig;
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
     * @param int|null $storeId
     * @return string
     */
    public function getApiKey(?int $storeId = null): string
    {
        return (string) $this->scopeConfig->getValue(
            self::XML_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * @param int|null $storeId
     * @return bool
     */
    public function isConfigured(?int $storeId = null): bool
    {
        return $this->isEnabled($storeId) && $this->getApiKey($storeId) !== '';
    }

    /**
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
}
