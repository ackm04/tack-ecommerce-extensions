<?php
/**
 * "Request a Quote" button block for the product view page.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block;

use Magento\Catalog\Block\Product\Context;
use Magento\Catalog\Model\Product;
use Magento\Framework\View\Element\Template;
use Magento\Framework\Registry;
use Magento\Framework\Data\Form\FormKey;
use TackQuote\Quotes\Model\Config;

class RequestQuote extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var Registry
     */
    private $registry;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @param Context $context
     * @param Config $config
     * @param Registry $registry
     * @param FormKey $formKey
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        Registry $registry,
        FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->registry = $registry;
        $this->formKey = $formKey;
    }

    /**
     * @return string
     */
    public function getFormKeyValue(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isButtonEnabled();
    }

    /**
     * @return string
     */
    public function getButtonLabel(): string
    {
        return $this->config->getButtonLabel();
    }

    /**
     * @return Product|null
     */
    public function getCurrentProduct(): ?Product
    {
        $product = $this->registry->registry('current_product');

        return $product instanceof Product ? $product : null;
    }

    /**
     * @return string Absolute URL to the module's submit controller.
     */
    public function getSubmitUrl(): string
    {
        return $this->getUrl('tackquote/quote/submit');
    }
}
