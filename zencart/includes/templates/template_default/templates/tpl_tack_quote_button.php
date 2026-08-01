<?php
/**
 * TackQuote for Zen Cart — storefront "Request a Quote" button + modal.
 *
 * This is a template *partial*, not an auto-wired hook: Zen Cart's default
 * product_info template has no plugin hook point at the "Add to Cart" button
 * the way PrestaShop's `displayProductActions` does, so wiring this in
 * requires one manual line in your template's product_info file — see
 * README.md "Installation" step 4 for the exact line and file
 * (`tpl_product_info_display.php`) to edit. Once required, it renders on the
 * product info page for the product identified by `$_GET['products_id']`.
 *
 * Uses Zen Cart's own $current_page_base template lookup helper
 * ($template->get_template_dir) is not required here since assets are
 * referenced with plain relative paths under this template's own
 * css/ and jscript/ directories, copied alongside this file.
 */

if (!defined('TACK_ENABLE_WIDGET') || TACK_ENABLE_WIDGET !== 'true') {
    return;
}
if (!defined('TACK_API_KEY') || TACK_API_KEY === '') {
    // Not configured yet — don't show a button that can't work.
    return;
}

$tackProductId = isset($_GET['products_id']) ? (int) $_GET['products_id'] : 0;
if (!$tackProductId) {
    return;
}

$tackButtonLabel = defined('TACK_BUTTON_LABEL') && TACK_BUTTON_LABEL !== ''
    ? TACK_BUTTON_LABEL
    : 'Request a Quote';
?>
<link rel="stylesheet" type="text/css" href="includes/templates/template_default/css/tack_quote_button.css">
<script type="text/javascript" src="includes/templates/template_default/jscript/tack_quote_button.js" defer></script>

<div class="tackQuoteWidget" id="tackQuoteWidget">
    <button type="button"
            class="tackQuoteOpenBtn"
            id="tackQuoteOpenBtn"
            data-product-id="<?php echo (int) $tackProductId; ?>"
            data-ajax-url="ajax_tack_quote_request.php">
        <?php echo htmlspecialchars($tackButtonLabel, ENT_QUOTES, 'UTF-8'); ?>
    </button>

    <div class="tackQuoteModal" id="tackQuoteModal" style="display:none;">
        <div class="tackQuoteModalContent">
            <button type="button" class="tackQuoteClose" id="tackQuoteClose" aria-label="Close">&times;</button>
            <h4><?php echo htmlspecialchars($tackButtonLabel, ENT_QUOTES, 'UTF-8'); ?></h4>
            <div class="tackQuoteField">
                <label for="tackQuoteEmail">Email</label>
                <input type="email" id="tackQuoteEmail" required>
            </div>
            <div class="tackQuoteField">
                <label for="tackQuoteQuantity">Quantity</label>
                <input type="number" id="tackQuoteQuantity" value="1" min="1">
            </div>
            <div class="tackQuoteField">
                <label for="tackQuoteNote">Note (optional)</label>
                <textarea id="tackQuoteNote" rows="3"></textarea>
            </div>
            <div class="tackQuoteError" id="tackQuoteError" style="display:none;"></div>
            <div class="tackQuoteSuccess" id="tackQuoteSuccess" style="display:none;"></div>
            <button type="button" class="tackQuoteSubmit" id="tackQuoteSubmit">Send request</button>
        </div>
    </div>
</div>
