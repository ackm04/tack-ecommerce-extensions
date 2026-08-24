<?php
/**
 * "Applies to" dropdown for quote-only mode.
 *
 * The option VALUES are the QuoteOnlyRules::SCOPE_* constants themselves, so the admin
 * dropdown and the storefront rule cannot drift apart: adding a scope means adding a
 * constant, and this list is derived from it rather than restating it.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use TackQuote\Quotes\Model\QuoteOnlyRules;

class QuoteOnlyScope implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            [
                'value' => QuoteOnlyRules::SCOPE_ALL,
                'label' => __('Everyone'),
            ],
            [
                'value' => QuoteOnlyRules::SCOPE_GUESTS,
                'label' => __('Guests only (signed-in customers keep the cart)'),
            ],
            [
                'value' => QuoteOnlyRules::SCOPE_GROUPS,
                'label' => __('Selected customer groups'),
            ],
        ];
    }
}
