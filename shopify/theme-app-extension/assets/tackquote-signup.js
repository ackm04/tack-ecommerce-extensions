/**
 * Wholesale account application, rendered from a TackQuote form definition.
 *
 * Every request goes to the MERCHANT's own domain at the app proxy path, and
 * Shopify forwards it to TackQuote with `shop` and `logged_in_customer_id`
 * signed using the app's client secret. No tenant id travels from this page at
 * all — the server derives it from the signed `shop`.
 *
 * That signature is also what lets this form skip the Turnstile challenge the
 * open public endpoint requires: Turnstile validates a hostname, and this page
 * is on the merchant's domain, not TackQuote's. A signed proxy request is the
 * stronger proof anyway — it names the merchant rather than asserting "probably
 * a human".
 *
 * https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  ns.boot('.tackquote-block[data-tackquote-mode="signup"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-signup-body]');
    const formSlug = root.dataset.tackquoteForm;
    if (!proxy || !body || !formSlug) return;

    const msg = (name) => root.dataset[name] || '';

    function show(node) {
      body.textContent = '';
      body.appendChild(node);
    }

    function line(text, className) {
      const p = document.createElement('p');
      if (className) p.className = className;
      p.textContent = text;
      return p;
    }

    /**
     * Builds one input for a form field.
     *
     * Field types come from the merchant's own form definition, so an unknown
     * type falls back to a text input rather than being dropped — a field the
     * seller asked for that silently never renders is worse than one rendered
     * plainly.
     */
    function fieldControl(field) {
      const id = `tq-${formSlug}-${field.key}`;
      let control;

      if (field.type === 'textarea') {
        control = document.createElement('textarea');
        control.rows = 4;
      } else if (field.type === 'select' && Array.isArray(field.options)) {
        control = document.createElement('select');
        for (const option of field.options) {
          const opt = document.createElement('option');
          opt.value = String(option);
          opt.textContent = String(option);
          control.appendChild(opt);
        }
      } else if (field.type === 'checkbox') {
        // A REAL checkbox, not a text box. `checkbox` is one of the seven field
        // types the seller can configure in TackQuote, and it used to fall
        // through to the `else` below and render as free text — so a merchant
        // whose form asks "I agree to the terms" got a box to type into, and
        // the submission was then rejected outright (see `readValue`).
        control = document.createElement('input');
        control.type = 'checkbox';
      } else {
        control = document.createElement('input');
        control.type =
          field.type === 'email' || field.type === 'tel' || field.type === 'number'
            ? field.type
            : 'text';
      }

      control.id = id;
      control.name = field.key;
      if (field.required) control.required = true;
      return control;
    }

    /**
     * The value to send for one field.
     *
     * A checkbox MUST travel as a JSON boolean. The API validates it with a
     * `typeof === 'boolean'` check and answers 400 "<label> must be a checkbox
     * value" for anything else, so sending `control.value` — which is the string
     * "on" for a ticked box, and "on" for an unticked one too — made every
     * submission of every form containing a checkbox fail. Nothing caught it:
     * the block and the API live in different repositories, so no type check
     * spans the boundary, and the failure only appears once a merchant adds a
     * checkbox to their form.
     *
     * Every other type travels as a string, including `number` — the API
     * validates that one with a regex against a string, not a JS number.
     */
    function readValue(field, control) {
      if (field.type === 'checkbox') return control.checked === true;
      return control.value;
    }

    function renderForm(definition) {
      const fields = Array.isArray(definition.fields) ? definition.fields : [];
      if (fields.length === 0) {
        show(line(msg('msgUnavailable'), 'tackquote-signup__error'));
        return;
      }

      const form = document.createElement('form');
      form.className = 'tackquote-signup__form';
      form.noValidate = false;

      for (const field of fields) {
        const wrap = document.createElement('div');
        wrap.className = 'tackquote-signup__field';

        const control = fieldControl(field);

        const label = document.createElement('label');
        label.htmlFor = control.id;
        label.textContent = field.label || field.key;
        if (field.required) label.textContent += ' *';

        wrap.appendChild(label);
        wrap.appendChild(control);
        form.appendChild(wrap);
      }

      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'tackquote-signup__submit';
      submit.textContent = msg('msgSubmit') || 'Apply';
      form.appendChild(submit);

      const status = document.createElement('p');
      status.className = 'tackquote-signup__status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      form.appendChild(status);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (submit.disabled) return;

        // The browser's own validity check first, so a missing required field is
        // reported without a round trip.
        if (!form.checkValidity()) {
          status.textContent = msg('msgRequired');
          form.reportValidity();
          return;
        }

        submit.disabled = true;
        status.textContent = msg('msgSubmitting');

        const values = {};
        for (const field of fields) {
          const control = form.elements.namedItem(field.key);
          if (control) values[field.key] = readValue(field, control);
        }

        ns.fetchJson(`${proxy}/wholesale-signup/${encodeURIComponent(formSlug)}`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: JSON.stringify({ values }),
          // Longer than a price lookup's deadline. This one writes, and a
          // shopper who has filled in six fields would rather wait than be told
          // to do it again.
          timeoutMs: 10000,
        })
          .then((result) => {
            // Prefer the merchant's own success message — they wrote it against
            // this form in TackQuote, and it is where they say what happens next
            // ("we review applications on Mondays"). The locale string is the
            // fallback for a form that has none.
            const message =
              typeof result?.message === 'string' && result.message.trim()
                ? result.message
                : msg('msgSuccess');

            // Replace the form outright. Leaving it on screen invites a second
            // submission, and the application has already been recorded.
            show(line(message, 'tackquote-signup__success'));
          })
          .catch(() => {
            submit.disabled = false;
            status.textContent = msg('msgError');
          });
      });

      show(form);
    }

    ns.fetchJson(`${proxy}/wholesale-signup/${encodeURIComponent(formSlug)}`)
      .then(renderForm)
      .catch(() => {
        // A form that cannot load is reported plainly. It is not an error the
        // shopper can act on, so it does not pretend to be one.
        show(line(msg('msgUnavailable'), 'tackquote-signup__error'));
      });
  });
});
