<?php
/**
 * TackQuote admin dashboard — connection status and configuration at a glance.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Adminhtml\Dashboard;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

class Index extends Action implements HttpGetActionInterface
{
    /**
     * Matches the top-level menu resource, not the config one: viewing status must not
     * require store-configuration rights. See etc/acl.xml.
     */
    public const ADMIN_RESOURCE = 'TackQuote_Quotes::overview';

    /**
     * Render the TackQuote dashboard page.
     *
     * @return Page
     */
    public function execute()
    {
        /** @var Page $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $result->setActiveMenu('TackQuote_Quotes::overview');
        $result->getConfig()->getTitle()->prepend(__('TackQuote'));

        return $result;
    }
}
