<?php
/**
 * "Test connection" button rendered inside Stores > Configuration > TackQuote.
 *
 * The dashboard already offers this, but the configuration screen is where an admin
 * actually pastes an API key — and having to save, navigate to another page and test
 * there is exactly the kind of round trip that leaves a store misconfigured because
 * nobody bothered to check.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class TestConnection extends Field
{
    /**
     * @var string
     */
    protected $_template = 'TackQuote_Quotes::system/config/test-connection.phtml';

    /**
     * The scope switcher and "Use system value" checkbox make no sense for a button.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * Render the button in place of the (absent) field value.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return $this->_toHtml();
    }

    /**
     * Admin AJAX URL the button posts to.
     *
     * @return string
     */
    public function getAjaxUrl(): string
    {
        return $this->getUrl('tackquote/connection/test');
    }

    /**
     * Markup for the "Test connection" button itself.
     *
     * @return string
     */
    public function getButtonHtml(): string
    {
        return (string) $this->getLayout()
            ->createBlock(\Magento\Backend\Block\Widget\Button::class)
            ->setData([
                'id' => 'tackquote_test_connection_button',
                'label' => __('Test connection'),
            ])
            ->toHtml();
    }
}
