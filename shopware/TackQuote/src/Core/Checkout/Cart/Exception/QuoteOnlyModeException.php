<?php declare(strict_types=1);

namespace TackQuote\TackQuote\Core\Checkout\Cart\Exception;

use Shopware\Core\Framework\HttpException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Thrown when something tries to put a line item into the cart while the storefront is in
 * B2B quote-only mode.
 *
 * Extends Shopware\Core\Framework\HttpException (the 6.5+ base for plugin exceptions) so
 * the refusal is a real 403 in both API scopes rather than an uncaught 500: the Store API
 * renders it as a JSON error object with the code below, and the storefront renders the
 * error page for 403.
 */
class QuoteOnlyModeException extends HttpException
{
    public const QUOTE_ONLY_MODE_ACTIVE = 'TACKQUOTE__QUOTE_ONLY_MODE_ACTIVE';

    public static function cartDisabled(): self
    {
        return new self(
            Response::HTTP_FORBIDDEN,
            self::QUOTE_ONLY_MODE_ACTIVE,
            'This storefront is running in quote-only mode. Products cannot be added to the cart; request a quote instead.'
        );
    }
}
