# Shopware App registration & signing protocol (verified)

Reference notes for `shopware/TackQuoteApp` and for the TackQuote API endpoints that
serve it (`apps/api/src/modules/integrations/shopware-app/` in the `ackm04/tack` repo).

**Every claim below was verified against Shopware's own source code**, not against
prose documentation and not against sibling code in our repo. The doc pages
(`developer.shopware.com/docs/guides/plugins/apps/app-base-guide`,
`.../lifecycle/app-registration-setup`) describe the handshake but do **not**
pin down the exact HMAC inputs, the canonical query ordering, or the
re-registration behaviour — all three are load-bearing and all three were read
out of the implementations listed here.

## Sources (fetched 2026-08-23)

| What | Source of truth |
|---|---|
| Manifest schema | `shopware/shopware@trunk` `src/Core/Framework/App/Manifest/Schema/manifest-3.0.xsd` |
| App-side handshake | `shopware/app-php-sdk@main` `src/Registration/RegistrationService.php` |
| Signature verification | `shopware/app-php-sdk@main` `src/Authentication/RequestVerifier.php` |
| Secret rotation / dual signature | `shopware/app-php-sdk@main` `src/Authentication/DualSignatureRequestVerifier.php` |
| Proof computation | `shopware/app-php-sdk@main` `src/Authentication/ResponseSigner.php` |
| Outbound webhook shape | `shopware/shopware@trunk` `src/Core/Framework/App/Payload/AppPayloadServiceHelper.php` |
| Outbound signing middleware | `shopware/shopware@trunk` `src/Core/Framework/App/Hmac/Guzzle/AuthMiddleware.php` |

All HMACs are **HMAC-SHA256, lowercase hex**, compared in constant time
(`hash_equals` in PHP → `crypto.timingSafeEqual` for us).

---

## Step 1 — Shopware GETs `<setup><registrationUrl>`

Query parameters (exactly these three are used for the signature):

```
shop-id=<opaque shop id>&shop-url=<https://store.example>&timestamp=<unix seconds>
```

Header: `shopware-app-signature`

The signed message is **NOT the raw query string**. `RequestVerifier` re-builds a
canonical string in a fixed order from the parsed (URL-decoded) values:

```php
// RequestVerifier::buildValidationQuery()
return sprintf(
    'shop-id=%s&shop-url=%s&timestamp=%s',
    $queries['shop-id'],
    $queries['shop-url'],
    $queries['timestamp']
);
```

Key: the **app secret** — the value of `<setup><secret>` in the manifest for a
locally/privately distributed app, or the secret issued by the Shopware Account
for a store-distributed app.

Consequence worth stating plainly: extra query params are ignored by the
signature, and the parameter order on the wire is irrelevant. Reconstructing the
message from the raw query string is a bug that only shows up when Shopware
reorders params or adds one.

Shopware also sends an `sw-version` header carrying the shop's Shopware version.

### Re-registration (a shop that is already confirmed)

`DualSignatureRequestVerifier::authenticateRegistrationRequest()` requires a
**second** signature when the shop already exists with a confirmed
registration, and either the app enforces double signatures, the shop has
previously verified with one, or the header is simply present:

Header: `shopware-shop-signature`, over the *same* canonical query string, keyed
with the shop's **current** (pre-rotation) shop secret.

This is the anti-hijack control: without it, anyone holding the app secret could
re-register an existing `shop-id` and take over the installation. Verifying it
proves the caller also holds the secret only that shop possesses.

## Step 2 — the app's response

HTTP 200, `Content-Type: application/json`:

```json
{
  "proof": "<hex hmac>",
  "secret": "<freshly generated per-shop secret>",
  "confirmation_url": "https://api.example/…/confirm"
}
```

`proof` is computed over the **concatenation with no separator** of shop id,
shop url and the app's technical name, keyed with the **app secret**:

```php
// ResponseSigner::getRegistrationSignature()
$this->sign(
    implode('', [
        $proofParameters['shop-id'],
        $proofParameters['shop-url'],
        $appConfiguration->getAppName()
    ]),
    $appConfiguration->getAppSecret()
);
```

Two traps here, both visible only in the source:

1. The `shop-url` fed into the proof is the **raw value from the query string**,
   not a normalized/sanitized one. `RegistrationService` sanitizes the URL it
   *stores* (collapsing `//` in the path) but builds `$proofParameters` from the
   raw query — see its own log line, `'signed-shop-url' => $proofParameters['shop-url']`,
   annotated "Raw URL as signed into the proof; differs from the sanitized
   shop-url when the path is normalized."
2. The app name in the proof must equal the manifest `<meta><name>` exactly.

The generated `secret` is stored as a **pending** secret. The shop's existing
secret must keep working until step 3 succeeds, otherwise a failed
re-registration bricks a live installation.

## Step 3 — Shopware POSTs `confirmation_url`

Body:

```json
{
  "apiKey": "SWIA…",
  "secretKey": "…",
  "timestamp": "1592398983",
  "shopUrl": "https://store.example",
  "shopId": "sqX6cqHi6hbj"
}
```

Header: `shopware-shop-signature` — HMAC over the **raw request body bytes**,
keyed with the **pending secret** the app returned in step 2
(`DualSignatureRequestVerifier::authenticateRegistrationConfirmation()`, leg
`pending-secret`).

For a re-registration of an already-confirmed shop, a second leg is required:
header `shopware-shop-signature-previous`, same raw body, keyed with the shop's
**current** secret (leg `previous-signature`).

On success the app: promotes pending → current secret (retaining the previous
secret plus a `secretsRotatedAt` timestamp), stores `apiKey`/`secretKey`, marks
the registration confirmed, and returns **HTTP 204**.

`apiKey`/`secretKey` are Admin API `client_credentials` credentials — the same
grant verified against the sandbox below.

## Step 4 — every subsequent inbound request

| Direction / kind | Where the signature lives | Signed message | Key |
|---|---|---|---|
| Webhook (POST) | header `shopware-shop-signature` | raw body bytes | shop secret |
| Signed GET (admin module iframe, action-button GET) | **query param** `shopware-shop-signature` | the query string with `&shopware-shop-signature=<sig>` removed | shop secret |
| Storefront call | header `shopware-app-token` | JWT: HS256, `iss` = shop id | shop secret |
| App → Shopware response (action buttons) | header `shopware-app-signature` | response body | shop secret |

Webhook POSTs also carry `sw-version`; signed GETs may carry `sw-version` as a
query param instead.

**Secret rotation grace window:** `DualSignatureRequestVerifier` retries failed
verification against the previous secret for `INFLIGHT_ALLOWANCE = 60` seconds
after `secretsRotatedAt`. Past that window a previous-secret match is logged and
then **rejected**. Implementations that skip this drop in-flight webhooks on
every re-registration.

## Tenant derivation (our rule, not Shopware's)

`CLAUDE.md` forbids deriving the tenant from a caller-supplied value. Applied here:

- `shop-id` **is** inside the HMAC-protected message, so it is vendor-signed and
  therefore admissible as a *lookup key*.
- It is **not** admissible as a tenant identifier. On first registration the only
  key involved is the app secret, which is global to the app — so a `shop-id` at
  that point proves "some Shopware store", never "this TackQuote tenant".
- Therefore registration creates an **unclaimed** shop row (`tenant_id NULL`).
  A shop is bound to a tenant only through an authenticated TackQuote session
  claiming it. Inbound webhooks resolve tenant as
  `shopId (signed) → shop row → tenant_id`, and are rejected while unclaimed.

## Manifest facts from the XSD

- `<manifest>` children are `xs:all` — order free, `<meta>` required, everything
  else `minOccurs="0"`.
- `<meta>` is `xs:choice maxOccurs="unbounded"`. **This means the XSD does not
  enforce the presence of `author`, `copyright`, `license`, `version` or
  `compatibility`** — a manifest missing them is schema-valid but rejected by
  Shopware's own runtime manifest validation at `app:refresh`. Do not treat
  "xmllint passes" as "Shopware will accept it".
- `<setup>`: `xs:all`, `registrationUrl` required, `secret` optional (the
  optional `secret` is what makes local/private distribution work without the
  Shopware Account).
- `<webhooks>/<webhook>` `name` attribute is constrained unique by
  `xs:unique name="uniqueWebhookName"`.
- `<permissions>` accepts `read`/`create`/`update`/`delete` and `crud`.
- Root element must carry
  `xsi:noNamespaceSchemaLocation="…/manifest-3.0.xsd"`; the schema has no target
  namespace (`elementFormDefault="qualified"`, no `targetNamespace`).

## Sandbox verification (2026-08-23)

Shopware Cloud sandbox, Admin API `client_credentials` grant:

```
POST /api/oauth/token  -> HTTP 200   (token_type Bearer, expires_in 600)
GET  /api/_info/version -> HTTP 200  {"version":"6.7.12.1"}
GET  /api/_info/config  -> HTTP 200  (exposes shopId, appUrl)
POST /api/search/product -> HTTP 200 (meta.total 0 — sandbox catalog is empty)
```

Credentials live in an operator-held env file outside every repo and are
deliberately absent from this document.

## UNVERIFIED

- Whether Shopware Cloud **categorically** refuses PHP platform plugins. The
  docs assert apps are "the extension mechanism designed for Shopware's Cloud
  environment" but stop short of a prohibition. What *is* established
  empirically is the failure this work fixes: the Cloud installer rejected our
  plugin with `No manifest.xml found`, i.e. it required an App. The fix is the
  same either way.
