/*
 * The net-terms (credit) application form.
 *
 * ── Why this file exists ────────────────────────────────────────────────────
 *
 * `POST {proxy}/credit-application` shipped in the API on 2026-09-04 (#423
 * Phase 4) and NOTHING CALLED IT. Worse than its sibling: the route also needed
 * migration 280, which was itself unapplied in production until 2026-09-05, so
 * it answered HTTP 500 for the whole period in which nothing called it. Two
 * independent failures that could not meet, because nothing looked at either.
 *
 * ── Anonymous is a REFUSAL here, not a public branch ────────────────────────
 *
 * The controller answers a signed-out shopper with 401 "Sign in to your account
 * to apply for net terms", because an application has to belong to somebody.
 * So this block must NOT render a form to a logged-out visitor: filling in a
 * legal business name, a tax id and three trade references only to be told to
 * sign in is the worst version of this feature. It shows the prompt instead,
 * and Liquid already knows whether there is a customer — no request needed to
 * find out.
 *
 * Identity still comes from Shopify's signature, never from this page: the
 * `customer.id` in the markup only decides which of the two views to draw.
 * https://shopify.dev/docs/apps/build/online-store/app-proxies/authenticate-app-proxies
 */
(window.TackQuoteQ = window.TackQuoteQ || []).push((ns) => {
  /*
   * The server refuses a body over 16 KB (`MAX_CREDIT_APPLICATION_BODY_BYTES`)
   * before it even verifies the signature. Checking here as well turns a bare
   * 400 into a sentence naming the field the shopper should shorten — and the
   * server keeps its own check, because a client-side limit is a courtesy and
   * not a control.
   */
  const MAX_BODY_BYTES = 16 * 1024;

  ns.boot('.tackquote-block[data-tackquote-mode="credit-application"]', (root) => {
    const proxy = ns.safeProxyPath(root.dataset.tackquoteProxy);
    const body = root.querySelector('[data-tackquote-credit-body]');
    if (!proxy || !body) return;

    const msg = (name) => root.dataset[name] || '';
    const signedIn = root.dataset.tackquoteSignedIn === 'true';

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

    function field(id, labelText, opts) {
      const wrap = document.createElement('div');
      wrap.className = 'tackquote-credit__field';

      const label = document.createElement('label');
      label.htmlFor = id;
      label.textContent = labelText;
      wrap.appendChild(label);

      const control = document.createElement(opts.tag || 'input');
      control.id = id;
      control.name = opts.name;
      if (opts.tag !== 'textarea') control.type = opts.type || 'text';
      if (opts.tag === 'textarea') control.rows = 3;
      if (opts.required) control.required = true;
      if (opts.maxLength) control.maxLength = opts.maxLength;
      if (opts.min != null) control.min = String(opts.min);
      if (opts.max != null) control.max = String(opts.max);
      if (opts.autocomplete) control.autocomplete = opts.autocomplete;
      wrap.appendChild(control);
      return wrap;
    }

    /*
     * The fields, and their bounds, are the DTO's — `SubmitCreditApplicationDto`
     * in the API. `maxLength` here mirrors each `@MaxLength`, so a shopper is
     * stopped by the input rather than by a validation error after typing 2,000
     * characters of notes. Where the two could drift, the server wins; this is
     * the courtesy copy of the rule.
     *
     * `billingAddress` is deliberately absent. It is `Record<string, unknown>`
     * on the DTO with no shape the storefront could render honestly, and
     * inventing a structure here would send a shape the seller's review screen
     * does not expect.
     */
    function buildForm() {
      const form = document.createElement('form');
      form.className = 'tackquote-credit__form';
      form.noValidate = false;

      form.appendChild(
        field('tq-credit-name', msg('msgLabelBusiness'), {
          name: 'legalBusinessName',
          required: true,
          maxLength: 200,
          autocomplete: 'organization',
        }),
      );
      form.appendChild(
        field('tq-credit-email', msg('msgLabelEmail'), {
          name: 'contactEmail',
          type: 'email',
          required: true,
          maxLength: 320,
          autocomplete: 'email',
        }),
      );
      form.appendChild(
        field('tq-credit-phone', msg('msgLabelPhone'), {
          name: 'contactPhone',
          type: 'tel',
          maxLength: 40,
          autocomplete: 'tel',
        }),
      );
      form.appendChild(
        field('tq-credit-tax', msg('msgLabelTaxId'), { name: 'taxId', maxLength: 64 }),
      );
      form.appendChild(
        field('tq-credit-limit', msg('msgLabelLimit'), {
          name: 'requestedLimit',
          type: 'number',
          min: 0,
          max: 100000000,
        }),
      );
      form.appendChild(
        field('tq-credit-terms', msg('msgLabelTerms'), {
          name: 'requestedTermsDays',
          type: 'number',
          min: 0,
          max: 365,
        }),
      );
      form.appendChild(
        field('tq-credit-notes', msg('msgLabelNotes'), {
          name: 'notes',
          tag: 'textarea',
          maxLength: 2000,
        }),
      );

      const status = document.createElement('p');
      status.className = 'tackquote-credit__status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');

      const submit = document.createElement('button');
      submit.type = 'submit';
      submit.className = 'tackquote-credit__submit';
      submit.textContent = msg('msgSubmit');

      form.appendChild(submit);
      form.appendChild(status);

      form.addEventListener('submit', (event) => {
        event.preventDefault();
        if (!form.checkValidity()) {
          form.reportValidity();
          return;
        }

        const payload = {};
        const text = (name) => {
          const el = form.elements.namedItem(name);
          const value = el && typeof el.value === 'string' ? el.value.trim() : '';
          return value === '' ? undefined : value;
        };
        const number = (name) => {
          const value = text(name);
          if (value === undefined) return undefined;
          const parsed = Number(value);
          return Number.isFinite(parsed) ? parsed : undefined;
        };

        payload.legalBusinessName = text('legalBusinessName');
        payload.contactEmail = text('contactEmail');
        payload.contactPhone = text('contactPhone');
        payload.taxId = text('taxId');
        payload.requestedLimit = number('requestedLimit');
        payload.requestedTermsDays = number('requestedTermsDays');
        payload.notes = text('notes');

        /*
         * Undefined keys are dropped rather than sent as null. `main.ts` runs
         * ValidationPipe with `forbidNonWhitelisted`, and an optional field sent
         * as null fails `@IsString()` — so an empty phone box would 400 the whole
         * application. The signup block records the same class of trap for
         * checkboxes sent as "on".
         */
        for (const key of Object.keys(payload)) {
          if (payload[key] === undefined) delete payload[key];
        }

        const serialized = JSON.stringify(payload);
        if (new Blob([serialized]).size > MAX_BODY_BYTES) {
          status.textContent = msg('msgTooLarge');
          return;
        }

        submit.disabled = true;
        status.textContent = msg('msgSubmitting');

        ns.fetchJson(`${proxy}/credit-application`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
          body: serialized,
          // Longer than a read. A shopper who has filled in seven fields would
          // rather wait than be asked to do it again.
          timeoutMs: 10000,
        })
          .then((result) => {
            /*
             * `already_pending` is a SUCCESS, not an error: the server returns
             * it when this customer already has an application under review, and
             * telling them "something went wrong" would invite a duplicate that
             * the seller then has to reconcile. Both outcomes replace the form,
             * because in both cases there is an application on file.
             */
            const message =
              result && result.status === 'already_pending'
                ? msg('msgAlreadyPending')
                : msg('msgSuccess');
            show(line(message, 'tackquote-credit__success'));
          })
          .catch(() => {
            submit.disabled = false;
            status.textContent = msg('msgError');
          });
      });

      return form;
    }

    if (!signedIn) {
      // No request is made at all. The answer is already known, and asking
      // would spend one of three attempts per fifteen minutes on a 401.
      show(line(msg('msgSignIn'), 'tackquote-credit__signin'));
      return;
    }

    show(buildForm());
  });
});
