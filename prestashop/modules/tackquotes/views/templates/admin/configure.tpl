{*
 * "Test connection" block appended below the main settings form in
 * TackQuotes::renderForm(). Mirrors the WooCommerce plugin's separate
 * "Test connection" form (includes/class-tack-settings.php::render_page()).
 *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-plug"></i> {l s='Test connection' d='Modules.Tackquotes.Admin'}
    </div>
    <p class="help-block">
        {l s='Uses the saved API URL and key to call TackQuote. Save settings first if you just changed them.' d='Modules.Tackquotes.Admin'}
    </p>
    <form method="post" action="{$test_connection_url|escape:'html':'UTF-8'}">
        <input type="hidden" name="submitTackQuotesTestConnection" value="1">
        <button type="submit" class="btn btn-default">
            {l s='Test TackQuote connection' d='Modules.Tackquotes.Admin'}
        </button>
    </form>
</div>
