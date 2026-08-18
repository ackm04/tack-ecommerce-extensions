{*
 * "Request a Quote" button rendered via hookDisplayProductActions.
 * Submission is handled client-side by views/js/tackquotes.js, which POSTs
 * to {$tackquotes_ajax_url} (controllers/front/quoterequest.php).
 *}
<div class="tackquotes-widget" id="tackquotes-widget">
    <button type="button"
            class="btn btn-secondary tackquotes-open-btn"
            id="tackquotes-open-btn"
            data-product-id="{$tackquotes_product_id|intval}"
            data-ajax-url="{$tackquotes_ajax_url|escape:'html':'UTF-8'}"
            {* Messages are handed to the JS already translated. The script must not hold
               English of its own, or these two strings are the only part of the module a
               merchant cannot translate — every other wording goes through {l} or trans(). *}
            data-msg-success="{l s='Quote request sent. Check your email for a link to the quote.' d='Modules.Tackquotes.Shop'|escape:'html':'UTF-8'}"
            data-msg-network-error="{l s='Could not reach TackQuote. Please try again.' d='Modules.Tackquotes.Shop'|escape:'html':'UTF-8'}">
        {$tackquotes_button_label|escape:'html':'UTF-8'}
    </button>

    <div class="tackquotes-modal" id="tackquotes-modal" style="display:none;">
        <div class="tackquotes-modal-content">
            <button type="button" class="tackquotes-close" id="tackquotes-close" aria-label="Close">&times;</button>
            <h4>{$tackquotes_button_label|escape:'html':'UTF-8'}</h4>
            <div class="tackquotes-field">
                <label for="tackquotes-email">{l s='Email' d='Modules.Tackquotes.Shop'}</label>
                <input type="email" id="tackquotes-email" required>
            </div>
            <div class="tackquotes-field">
                <label for="tackquotes-quantity">{l s='Quantity' d='Modules.Tackquotes.Shop'}</label>
                <input type="number" id="tackquotes-quantity" value="1" min="1">
            </div>
            <div class="tackquotes-field">
                <label for="tackquotes-note">{l s='Note (optional)' d='Modules.Tackquotes.Shop'}</label>
                <textarea id="tackquotes-note" rows="3"></textarea>
            </div>
            <div class="tackquotes-error" id="tackquotes-error" style="display:none;"></div>
            <div class="tackquotes-success" id="tackquotes-success" style="display:none;"></div>
            <button type="button" class="btn btn-primary" id="tackquotes-submit">
                {l s='Send request' d='Modules.Tackquotes.Shop'}
            </button>
        </div>
    </div>
</div>
