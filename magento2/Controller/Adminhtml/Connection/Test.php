<?php
/**
 * Admin AJAX endpoint behind the dashboard's "Test connection" button.
 *
 * Model\Api\Client::testConnection() has existed and worked since the module's first
 * version but nothing ever called it, so an admin had no way to tell a wrong API key
 * from a wrong base URL from a store that simply had no products quoted yet. This wires
 * it to a button.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Adminhtml\Connection;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use TackQuote\Quotes\Model\Api\Client;
use TackQuote\Quotes\Model\Config;

class Test extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'TackQuote_Quotes::overview';

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Client
     */
    private $client;

    /**
     * @param Context $context
     * @param Config $config
     * @param Client $client
     */
    public function __construct(Context $context, Config $config, Client $client)
    {
        parent::__construct($context);
        $this->config = $config;
        $this->client = $client;
    }

    /**
     * @return JsonResult
     */
    public function execute()
    {
        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);

        // Distinguish "not configured yet" from "configured but rejected" — they need
        // completely different fixes, and a single "connection failed" hides which.
        if (!$this->config->isEnabled()) {
            return $result->setData([
                'success' => false,
                'state' => 'disabled',
                'message' => __('TackQuote is disabled. Enable it in Settings first.'),
            ]);
        }

        if ($this->config->getApiKey() === '') {
            return $result->setData([
                'success' => false,
                'state' => 'no_key',
                'message' => __('No API key is set. Add one in Settings.'),
            ]);
        }

        $response = $this->client->testConnection();

        if (!empty($response['ok'])) {
            return $result->setData([
                'success' => true,
                'state' => 'connected',
                'message' => __('Connected to TackQuote at %1.', $this->config->getApiBaseUrl()),
            ]);
        }

        return $result->setData([
            'success' => false,
            'state' => 'error',
            'message' => $response['message'] ?? __('Could not reach TackQuote.'),
        ]);
    }
}
