/**
 * TackQuote storefront app: quote list, drawer, and the shared multi-step quote form.
 *
 * One component owns all of it because there is one form per page (rendered by
 * Block\QuoteList in before.body.end) serving two entry points — "Request a Quote" on a
 * product page, which quotes just that product, and the quote list, which quotes
 * everything collected across the store.
 *
 * The list is held in localStorage and NEVER in the Magento cart: quoting must not touch
 * stock, cart totals or checkout, and Magento's own cart is internally a "quote"
 * (Magento_Quote). The WooCommerce plugin made the same choice for the same reasons.
 *
 * SECURITY — the browser stores only a SKU, a display name and a quantity. Price is never
 * stored or sent; the controller re-resolves every product server-side. localStorage is
 * fully editable by the shopper, so a posted price would let anyone quote themselves any
 * amount.
 *
 * @package TackQuote_Quotes
 */
define([
    'jquery',
    'underscore',
    'Magento_Ui/js/modal/modal',
    'Magento_Customer/js/customer-data',
    'mage/translate'
], function ($, _, modal, customerData, $t) {
    'use strict';

    var STORAGE_KEY = 'tack_quote_list',
        MAX_ITEMS = 50;

    /**
     * The form key must be read at submit time, never from server-rendered HTML: pages are
     * full-page-cached, so a baked-in key is stale for every visitor.
     * Magento_PageCache's form-key-provider.js fills the input from the form_key cookie.
     *
     * @param {String} name
     * @return {String}
     */
    function getCookie(name) {
        var parts = document.cookie.split(';'),
            prefix = name + '=',
            i,
            part;

        for (i = 0; i < parts.length; i++) {
            part = parts[i].replace(/^\s+/, '');

            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.substring(prefix.length));
            }
        }

        return '';
    }

    /**
     * localStorage can throw (Safari private browsing, quota) and can contain anything the
     * shopper typed into devtools. Every read is defensive; a corrupt list degrades to an
     * empty one rather than breaking the page.
     *
     * @return {Array}
     */
    function readList() {
        var raw, parsed;

        try {
            raw = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return [];
        }

        if (!raw) {
            return [];
        }

        try {
            parsed = JSON.parse(raw);
        } catch (e) {
            return [];
        }

        if (!_.isArray(parsed)) {
            return [];
        }

        return parsed
            .filter(function (row) {
                return row && typeof row.sku === 'string' && row.sku !== '';
            })
            .map(function (row) {
                return {
                    sku: String(row.sku),
                    name: typeof row.name === 'string' ? row.name : row.sku,
                    qty: Math.max(1, parseInt(row.qty, 10) || 1),
                    // Chosen variant, e.g. {"144":"166"}. Without carrying this the
                    // server resolves the PARENT sku at the cheapest variant's price.
                    superAttribute: _.isObject(row.superAttribute) ? row.superAttribute : {}
                };
            })
            .slice(0, MAX_ITEMS);
    }

    /**
     * @param {Array} list
     */
    function writeList(list) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
        } catch (e) {
            // Storage unavailable or full: the in-memory list still works for this page.
        }
    }

    return function (config, element) {
        var $root = $(element),
            $modal = $root.find('[data-role="tackquote-modal"]'),
            $form = $root.find('.tackquote-form'),
            $error = $root.find('.tackquote-modal-error'),
            $done = $root.find('.tackquote-done'),
            $steps = $root.find('.tackquote-step'),
            $stepList = $root.find('.tackquote-steps'),
            $markers = $root.find('[data-step-marker]'),
            $next = $root.find('.tackquote-next'),
            $back = $root.find('.tackquote-back'),
            $submit = $root.find('.tackquote-submit-btn'),
            $toolbar = $root.find('.tackquote-actions'),
            $review = $root.find('[data-role="tackquote-review"]'),
            $widget = $root.find('[data-role="tackquote-widget"]'),
            $drawer = $root.find('[data-role="tackquote-drawer"]'),
            $items = $root.find('[data-role="tackquote-items"]'),
            $empty = $root.find('[data-role="tackquote-empty"]'),
            $count = $root.find('[data-role="tackquote-count"]'),
            companyMode = $root.data('company-mode'),
            submitUrl = $root.data('submit-url'),
            stepOrder = [],
            current = 0,
            // 'single' quotes the product whose page we are on; 'list' quotes everything
            // collected. Set when the form is opened.
            mode = 'single',
            singleItem = null,
            modalInstance;

        if (!$form.length || !$modal.length) {
            return;
        }

        $steps.each(function () {
            stepOrder.push($(this).data('step'));
        });

        modalInstance = modal({
            type: 'popup',
            modalClass: 'tackquote-modal-popup',
            title: config.modalTitle || $t('Request a Quote'),
            buttons: []
        }, $modal);

        // The container carries `hidden` purely to stop the form flashing inline before
        // this runs. Once the widget owns visibility that attribute fights it, collapsing
        // the dialog body to nothing.
        $modal.removeAttr('hidden');

        /* ---------------------------------------------------------------- list + drawer */

        function renderList() {
            var list = readList();

            $count.text(list.length);
            $widget.prop('hidden', list.length === 0);
            $empty.prop('hidden', list.length > 0);

            $items.empty();
            _.each(list, function (item) {
                var $li = $('<li class="tackquote-drawer__item"></li>'),
                    $name = $('<span class="tackquote-drawer__name"></span>').text(item.name),
                    $qty = $('<input type="number" min="1" class="input-text tackquote-drawer__qty">')
                        .val(item.qty)
                        .attr('aria-label', $t('Quantity for ') + item.name),
                    $remove = $('<button type="button" class="action tackquote-drawer__remove">&times;</button>')
                        .attr('aria-label', $t('Remove ') + item.name);

                $qty.on('change', function () {
                    updateQty(item.sku, parseInt($(this).val(), 10));
                });
                $remove.on('click', function () {
                    removeItem(item.sku);
                });

                $li.append($name).append($qty).append($remove);
                $items.append($li);
            });
        }

        /**
         * @param {Object} item {sku, name, qty}
         */
        function addItem(item) {
            var list = readList(),
                existing = _.find(list, function (row) {
                    // Match on the SELECTION too: merging on sku alone collapsed
                    // Size XS/Black and Size XL/Gray into one line of the parent sku.
                    return row.sku === item.sku &&
                        _.isEqual(row.superAttribute || {}, item.superAttribute || {});
                });

            if (existing) {
                // Adding the same product again increases the quantity rather than
                // creating a duplicate line the seller would have to reconcile.
                existing.qty += item.qty;
            } else {
                if (list.length >= MAX_ITEMS) {
                    showError($t('Your quote list is full. Remove an item before adding another.'));

                    return;
                }
                list.push(item);
            }

            writeList(list);
            renderList();
            openDrawer();
        }

        function updateQty(sku, qty) {
            var list = readList();

            _.each(list, function (row) {
                if (row.sku === sku) {
                    row.qty = Math.max(1, qty || 1);
                }
            });
            writeList(list);
            renderList();
        }

        function removeItem(sku) {
            writeList(readList().filter(function (row) {
                return row.sku !== sku;
            }));
            renderList();
        }

        function openDrawer() {
            $drawer.prop('hidden', false);
            $widget.find('.tackquote-widget__toggle').attr('aria-expanded', 'true');
        }

        function closeDrawer() {
            $drawer.prop('hidden', true);
            $widget.find('.tackquote-widget__toggle').attr('aria-expanded', 'false');
        }

        /* ------------------------------------------------------------------------- form */

        function showError(text) {
            $error.text(text).prop('hidden', false);
        }

        /**
         * Inline message beside the control the shopper just used.
         *
         * showError() writes into the modal, which is CLOSED when adding to the list — so
         * using it there produced a button that appeared to do nothing at all, with no
         * message and no console error.
         *
         * @param {Object} $context Element wrapping the trigger.
         * @param {String} text
         */
        function notify($context, text) {
            var $msg = $context.find('.tackquote-inline-note');

            if (!$msg.length) {
                $msg = $('<p class="tackquote-inline-note" role="alert"></p>');
                $context.append($msg);
            }

            $msg.text(text);
        }

        function clearMessages() {
            $error.prop('hidden', true).text('');
        }

        function markInvalid($field, message) {
            $field.attr('aria-invalid', 'true').addClass('mage-error');
            showError(message);
            $field.trigger('focus');
        }

        function clearInvalid() {
            $form.find('[aria-invalid]').removeAttr('aria-invalid').removeClass('mage-error');
        }

        function goToStep(index) {
            current = Math.max(0, Math.min(index, stepOrder.length - 1));

            $steps.each(function (i) {
                $(this).prop('hidden', i !== current);
            });
            $markers.each(function (i) {
                $(this).toggleClass('_active', i === current);
                $(this).toggleClass('_complete', i < current);
            });

            $back.prop('hidden', current === 0);
            $next.prop('hidden', current === stepOrder.length - 1);
            $submit.prop('hidden', current !== stepOrder.length - 1);

            $steps.eq(current).find('input, select, textarea').filter(':visible').first().trigger('focus');
        }

        function validateCurrentStep() {
            var valid = true;

            clearInvalid();

            $steps.eq(current).find('input, select, textarea').each(function () {
                var $field = $(this),
                    isRequired = $field.prop('required') || $field.data('tq-required') === 1,
                    value = $.trim($field.val() || '');

                if (!valid) {
                    return;
                }

                if (isRequired && $field.attr('type') === 'checkbox' && !$field.prop('checked')) {
                    markInvalid($field, $t('Please tick this box to continue.'));
                    valid = false;

                    return;
                }

                if (isRequired && $field.attr('type') !== 'checkbox' && value === '') {
                    markInvalid($field, $t('This field is required.'));
                    valid = false;

                    return;
                }

                if ($field.attr('type') === 'email' && value !== '' &&
                    !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    markInvalid($field, $t('Please enter a valid email address.'));
                    valid = false;
                }
            });

            if (valid) {
                clearMessages();
            }

            return valid;
        }

        function prefillFromCustomer() {
            var customer = customerData.get('customer')();

            if (!customer || !customer.firstname) {
                return;
            }

            if (!$form.find('[name="firstName"]').val()) {
                $form.find('[name="firstName"]').val(customer.firstname);
            }

            if (customer.lastname && !$form.find('[name="lastName"]').val()) {
                $form.find('[name="lastName"]').val(customer.lastname);
            }
        }

        /**
         * Render the review step from whatever is being quoted, so the shopper confirms the
         * actual contents rather than trusting an invisible payload.
         *
         * @param {Array} rows
         */
        function renderReview(rows) {
            $review.empty();
            _.each(rows, function (row) {
                var $li = $('<li class="tackquote-summary-list__item"></li>'),
                    $name = $('<span></span>').text(row.name),
                    $qty = $('<span class="tackquote-summary-list__qty"></span>')
                        .text('x' + row.qty);

                $review.append($li.append($name).append($qty));
            });
        }

        /**
         * Configurable/bundle products cannot be quoted from a bare parent SKU — the seller
         * would receive a variant they cannot fulfil. Magento renders the chosen options as
         * super_attribute inputs on the add-to-cart form.
         *
         * @return {Object}
         */
        /**
         * Whether the product page shows ANY option control the shopper must engage with.
         *
         * Bundles emit bundle_option[...], grouped emit super_group[...], custom options
         * emit options[...]. Checking only super_attribute made the gate below fire
         * unconditionally on those types, so "Add to Quote" was permanently dead on every
         * bundle, grouped and custom-option product in the catalogue.
         *
         * @return {Boolean}
         */
        function hasSelectableOptions() {
            return $('#product_addtocart_form')
                .find('[name^="super_attribute"], [name^="bundle_option"], [name^="super_group"], [name^="options["]')
                .length > 0;
        }

        function collectSuperAttributes() {
            var selections = {};

            $('#product_addtocart_form').find('[name^="super_attribute"]').each(function () {
                var name = $(this).attr('name'),
                    match = name && name.match(/super_attribute\[(\d+)]/),
                    value = $(this).val();

                if (match && value) {
                    selections[match[1]] = value;
                }
            });

            return selections;
        }

        /**
         * @param {String} nextMode 'single' | 'list'
         * @param {Object|null} item
         */
        function openForm(nextMode, item) {
            mode = nextMode;
            singleItem = item || null;

            clearMessages();
            clearInvalid();

            if (!$done.prop('hidden')) {
                $done.prop('hidden', true);
                $stepList.prop('hidden', false);
                $toolbar.prop('hidden', false);
                $form[0].reset();
            }

            prefillFromCustomer();
            renderReview(mode === 'list' ? readList() : [singleItem]);
            goToStep(0);
            closeDrawer();
            modalInstance.openModal();
        }

        function showDone(data) {
            var title, body;

            if (data.awaitingApproval) {
                // A company pending approval is NOT a finished signup. A plain success here
                // would leave the buyer waiting for access nobody granted.
                title = $t('Request received — your account needs approval');
                body = $t(
                    'We have your quote request. Your company account is being reviewed, ' +
                    'and we will email you as soon as it is approved.'
                );
            } else {
                title = $t('Quote request sent');
                body = data.quoteNumber ?
                    $t('Your reference is ') + data.quoteNumber + '. ' +
                        $t('A sales rep will be in touch shortly.') :
                    $t('A sales rep will be in touch shortly.');
            }

            $done.find('.tackquote-done__title').text(title);
            $done.find('.tackquote-done__body').text(body);

            $steps.prop('hidden', true);
            $stepList.prop('hidden', true);
            $toolbar.prop('hidden', true);
            $done.prop('hidden', false);
            $done.trigger('focus');
        }

        function submitForm() {
            var formKeyInput = $form.find('[name="form_key"]'),
                formKey = (formKeyInput.val() || '') || getCookie('form_key'),
                payload;

            if (!formKey) {
                showError($t('Could not verify the form. Please reload the page and try again.'));

                return;
            }

            payload = $form.serializeArray();
            payload.push({ name: 'form_key', value: formKey });

            if (mode === 'list') {
                // Only sku + qty travel; the controller re-resolves name and price.
                payload.push({
                    name: 'items',
                    value: JSON.stringify(readList().map(function (row) {
                        return {
                            sku: row.sku,
                            qty: row.qty,
                            superAttribute: row.superAttribute || {}
                        };
                    }))
                });
            } else {
                payload.push({ name: 'sku', value: singleItem.sku });
                payload.push({ name: 'qty', value: singleItem.qty });
                _.each(singleItem.superAttribute || {}, function (value, id) {
                    payload.push({ name: 'super_attribute[' + id + ']', value: value });
                });
            }

            $submit.prop('disabled', true);
            clearMessages();

            $.ajax({
                url: submitUrl,
                type: 'POST',
                dataType: 'json',
                data: $.param(payload),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (data) {
                if (!data || !data.success) {
                    showError((data && data.message) ||
                        $t('Could not create the quote. Please try again.'));

                    if (data && data.field) {
                        $form.find('[name="' + data.field + '"]')
                            .attr('aria-invalid', 'true')
                            .addClass('mage-error');
                    }

                    return;
                }

                // The list has been quoted; leaving it populated invites a duplicate
                // request for the same products.
                if (mode === 'list') {
                    writeList([]);
                    renderList();
                }

                showDone(data);
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON;

                showError((response && response.message) ||
                    $t('Could not reach TackQuote. Please try again.'));
            }).always(function () {
                $submit.prop('disabled', false);
            });
        }

        /* --------------------------------------------------------------------- wiring */

        // Product-page and listing triggers are bound on document, because listing pages
        // re-render product tiles (layered navigation, infinite scroll) after this runs.
        $(document).on('click', '.tackquote-add-btn', function () {
            var $ctx = $(this).closest('[data-role="tackquote-product"]'),
                requiresOptions = String($ctx.data('requires-options')) === '1',
                qtyField = $('#product_addtocart_form').find('[name="qty"]'),
                qty = Math.max(1, parseInt(qtyField.val(), 10) || 1);

            // On a listing there is no option UI at all, so such products link to their
            // product page instead of silently quoting an unfulfillable parent SKU.
            if (requiresOptions && $ctx.data('product-url')) {
                window.location.href = $ctx.data('product-url');

                return;
            }

            var superAttribute = collectSuperAttributes();

            /*
             * Only configurable selections can be resolved server-side today, so anything
             * else that needs a choice (bundle, grouped, custom options) is sent to its
             * product page rather than quietly quoting an unfulfillable parent SKU.
             */
            if (requiresOptions && hasSelectableOptions() && _.isEmpty(superAttribute)) {
                notify($ctx, $t('Please choose the options you need, then add to your quote.'));

                return;
            }

            if (requiresOptions && !hasSelectableOptions()) {
                notify($ctx, $t('This product has to be configured on its own page before it can be quoted.'));

                return;
            }

            addItem({
                sku: String($ctx.data('sku')),
                name: String($ctx.data('name')),
                qty: qty,
                superAttribute: superAttribute
            });
        });

        $(document).on('click', '.tackquote-request-btn', function () {
            var $ctx = $(this).closest('[data-role="tackquote-product"]'),
                requiresOptions = String($ctx.data('requires-options')) === '1',
                superAttribute = collectSuperAttributes(),
                qtyField = $('#product_addtocart_form').find('[name="qty"]'),
                qty = Math.max(1, parseInt(qtyField.val(), 10) || 1);

            if (requiresOptions && _.isEmpty(superAttribute)) {
                // Tell them beside the button, not inside a modal they have not opened.
                notify($ctx, $t('Please choose the options you need, then request a quote.'));

                return;
            }

            openForm('single', {
                sku: String($ctx.data('sku')),
                name: String($ctx.data('name')),
                qty: qty,
                superAttribute: superAttribute
            });
        });

        $root.on('click', '.tackquote-widget__toggle', function () {
            if ($drawer.prop('hidden')) {
                openDrawer();
            } else {
                closeDrawer();
            }
        });

        $root.on('click', '.tackquote-drawer__close', closeDrawer);

        $root.on('click', '[data-role="tackquote-request-list"]', function () {
            if (readList().length === 0) {
                return;
            }
            openForm('list', null);
        });

        /*
         * Quote-only mode: the CTA on the cart page (Block\QuoteOnlyNotice).
         *
         * Bound on `document`, NOT on $root, and this is the whole point of it existing
         * separately. $root is the quote-list widget's own element (line 116), so every
         * delegated handler above only ever sees clicks INSIDE that widget. The cart-page
         * notice renders into the `content` container, outside $root — reusing
         * `tackquote-request-list` for it would have produced a button that binds to nothing
         * and silently does nothing when clicked. On a quote-only storefront that is the
         * last button the shopper has.
         *
         * It also deliberately does NOT bail out on an empty list the way the handler above
         * does. Someone redirected off checkout may have items in their CART and nothing in
         * their quote list; openDrawer() shows the drawer's own empty state, which tells
         * them how to start one. A silent no-op would not.
         */
        $(document).on('click', '[data-role="tackquote-quote-only-cta"]', function (event) {
            event.preventDefault();
            openDrawer();
        });

        $next.on('click', function () {
            if (validateCurrentStep()) {
                goToStep(current + 1);
            }
        });

        $back.on('click', function () {
            clearMessages();
            goToStep(current - 1);
        });

        $form.on('submit', function (event) {
            event.preventDefault();

            /*
             * Implicit submission: pressing Enter in any field submits the form, and the
             * `hidden` attribute does NOT exempt the submit button from being the default.
             * Without this guard, Enter in a step-1 field posted the whole quote — skipping
             * the company step entirely, so a company_only tenant received an individual
             * registration with no company name.
             */
            if (current !== stepOrder.length - 1) {
                if (validateCurrentStep()) {
                    goToStep(current + 1);
                }

                return;
            }

            if (validateCurrentStep()) {
                submitForm();
            }
        });

        if (companyMode === 'required') {
            $form.find('[name="companyName"]').prop('required', true);
        }

        renderList();
        goToStep(0);
    };
});
