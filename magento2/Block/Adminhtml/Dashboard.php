<?php
/**
 * Backing block for the TackQuote admin dashboard.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use TackQuote\Quotes\Model\Config;

class Dashboard extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @param Context $context
     * @param Config $config
     * @param array $data
     */
    public function __construct(Context $context, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
    }

    /**
     * Whether TackQuote is switched on for the current scope.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    /**
     * Whether the storefront button will actually render. Mirrors
     * Config::isButtonEnabled() so the dashboard can explain a missing button rather
     * than leaving an admin to wonder why the storefront looks unchanged.
     *
     * @return bool
     */
    public function isButtonEnabled(): bool
    {
        return $this->config->isButtonEnabled();
    }

    /**
     * Whether an API key has been saved.
     *
     * @return bool
     */
    public function hasApiKey(): bool
    {
        return $this->config->getApiKey() !== '';
    }

    /**
     * The configured TackQuote API base URL, for display.
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->config->getApiBaseUrl();
    }

    /**
     * The storefront quote button's current label, for display.
     *
     * @return string
     */
    public function getButtonLabel(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * Admin AJAX URL behind the "Test connection" button.
     *
     * @return string
     */
    public function getTestConnectionUrl(): string
    {
        return $this->getUrl('tackquote/connection/test');
    }

    /**
     * Deep link to this module's own section of Stores > Configuration.
     *
     * @return string
     */
    public function getSettingsUrl(): string
    {
        return $this->getUrl('adminhtml/system_config/edit', ['section' => 'tackquote_quotes']);
    }

    /**
     * Setup checklist explaining whether the storefront button will show.
     *
     * The single most useful diagnostic on this page: exactly why the storefront button
     * is or is not showing, in the order an admin would need to fix them.
     *
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    public function getChecklist(): array
    {
        $hasKey = $this->hasApiKey();

        return [
            [
                'label' => (string) __('TackQuote is enabled'),
                'ok' => $this->config->isEnabled(),
                'hint' => (string) __('Turn on "Enable TackQuote" in Settings.'),
            ],
            [
                'label' => (string) __('API key is set'),
                'ok' => $hasKey,
                'hint' => (string) __(
                    'Create a key in TackQuote under Settings > Developer > API Keys, '
                    . 'with the quotes:write scope.'
                ),
            ],
            [
                'label' => (string) __('Quote button is turned on'),
                'ok' => $this->isButtonEnabled(),
                'hint' => (string) __(
                    'Enable "Show quote button on product pages" in Settings. It also needs an API key.'
                ),
            ],
        ];
    }
}
