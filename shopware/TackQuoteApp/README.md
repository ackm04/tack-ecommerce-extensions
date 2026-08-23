# TackQuote App for Shopware 6 (Cloud + self-hosted)

A Shopware **App**. Installs on **Shopware Cloud (SaaS)**, where PHP plugins
cannot be installed.

## Why this exists alongside `../TackQuote`

`../TackQuote` is a **platform plugin**: `composer.json` declares
`"type": "shopware-platform-plugin"`, it requires `shopware/core`, and
`src/TackQuote.php` extends `Shopware\Core\Framework\Plugin`. Plugins are PHP
that runs *inside* the shop, so they have no `manifest.xml` — and a Cloud store's
installer looks for exactly that file. Installing the plugin on Cloud fails with:

```
No manifest.xml found
```

Apps are the other extension model: no PHP in the shop, just a declarative
`manifest.xml` plus HTTP endpoints hosted by us. That is what this directory is.

Keep both. The plugin remains the right choice for a self-hosted shop that wants
storefront template changes; this app is the only thing installable on Cloud.

> The directory name `TackQuoteApp` is not cosmetic. Shopware resolves an app by
> its directory, requires the directory to equal `<meta><name>`, and concatenates
> that same name into the registration `proof` HMAC. `TackQuote` was already
> taken by the plugin, so the app takes `TackQuoteApp`. Renaming either the
> directory or the name breaks registration.

## Validate before you ship

```bash
python3 bin/validate-manifest.py
```

Two layers, and the second is the one that matters:

1. XSD validation against `manifest-3.0.xsd` from `shopware/shopware` trunk.
2. Checks the XSD **provably cannot** make.

`<meta>` is declared `xs:choice maxOccurs="unbounded"`, so a manifest missing
`<author>`, `<copyright>`, `<license>`, `<version>` or `<compatibility>` is
schema-valid and still rejected by Shopware at `bin/console app:refresh`.
Confirmed by mutation: deleting `<author>` and deleting `<version>` both pass
`xmllint` cleanly. **"xmllint says valid" is not "Shopware will accept it."**

The validator also catches a name/directory mismatch, a non-`https`
`registrationUrl`, and a `<setup><secret>` that got committed.

## Installing

### Shopware Cloud

**Corrected 2026-08-23 by measurement.** This section previously said Cloud
installs from the Shopware Store only, with no sideload path. That is **wrong**:
a Cloud store accepts a zip through the Admin API.

Verified against a live Cloud sandbox (Shopware **6.7.12.1**):

```bash
# 1. token
curl -sX POST "$STORE/api/oauth/token" -H 'Content-Type: application/json' \
  -d '{"grant_type":"client_credentials","client_id":"...","client_secret":"..."}'

# 2. upload — this endpoint EXISTS on Cloud and accepts a private app
curl -sX POST "$STORE/api/_action/extension/upload" \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@TackQuoteApp.zip"
```

The store accepted the zip and immediately ran the registration handshake
against `<setup><registrationUrl>`. So a `<secret>` in the manifest is required
for Cloud too — the Store/Shopware-Account path is one option, not the only one.

Two things also confirmed on that store, worth knowing:

* All of Shopware's own first-party apps are `selfManaged=true` with an **HTTPS
  `path`** (e.g. `https://copilot.apps.shopware.io`), not a filesystem path. Apps
  whose code lives on their own server are the normal Cloud model.
* The `<meta><name>` must equal the directory name inside the zip, and the
  manifest must validate against `manifest-3.0.xsd`. The XSD makes `license` and
  `compatibility` **required**, which the prose docs do not mention — validate
  against the schema, not against an example.

**Known blocker, not an app defect.** Registration is a server-to-server callback
from Shopware to `registrationUrl`. If that host sits behind a bot challenge, the
install fails with:

```
FRAMEWORK__APP_REGISTRATION_FAILED
App registration for "TackQuoteApp" failed: Got status code 403,
with response: <title>Just a moment...</title>
```

That is Cloudflare (or equivalent) challenging Shopware's egress, not a signature
problem — the handshake itself was verified working by calling it directly:
unsigned → 401, wrong signature → 401, correctly signed → 200 with a `proof`
equal to `HMAC-SHA256(shopId + shopUrl + appName, appSecret)`. Exempt the
registration and webhook paths from bot protection before installing. On a
Cloudflare **Free** plan note that Bot Fight Mode *cannot* be skipped with a WAF
rule — Cloudflare documents that it runs outside the Ruleset Engine — so use an
IP Access rule for the vendor's egress ranges, upgrade for Super Bot Fight Mode,
or disable the challenge.

### Self-hosted, or a Cloud dev/sandbox with app sideloading enabled

```bash
cp -r TackQuoteApp <shopware-root>/custom/apps/TackQuoteApp
cd <shopware-root>
bin/console app:refresh          # reads the manifest, prompts for permissions
bin/console app:activate TackQuoteApp
```

For local development add a `<secret>` to `<setup>` **in your working copy only**:

```xml
<setup>
    <registrationUrl>https://your-tunnel.example/v1/integrations/shopware/app/register</registrationUrl>
    <secret>a-long-random-dev-secret</secret>
</setup>
```

A `<secret>` present in the manifest tells the shop to authenticate directly
against your endpoint instead of going through the Shopware Account. Never
commit one — `bin/validate-manifest.py` fails the build if you do.

## What registration requires from us

`<setup><registrationUrl>` is fetched **by the shop, over the public internet**.
It follows a four-step HMAC handshake (GET register → signed JSON response →
POST confirm → per-shop secret for all later traffic), specified in
[`../../docs/SHOPWARE_APP_PROTOCOL.md`](../../docs/SHOPWARE_APP_PROTOCOL.md) with
citations to Shopware's own source.

**This app cannot complete installation against an endpoint the shop cannot
reach.** `localhost` will not do. Registration needs
`https://api.tackquote.com/v1/integrations/shopware/app/register` deployed and
publicly resolvable, with a valid TLS certificate, before any store — sandbox
included — can install the app.

## Permissions

Read-only, deliberately: `product`, `product_price`, `category`, `currency`,
`customer`, `customer_group`, `order`, `order_line_item`, `sales_channel`.

TackQuote reads the catalog through the Admin API credentials the shop hands over
at registration confirmation. No write scopes are requested, because the app does
not write back to Shopware yet — see below.

## Webhooks

| name | event |
|---|---|
| `tackquote-app-lifecycle-activated` | `app.activated` |
| `tackquote-app-lifecycle-deactivated` | `app.deactivated` |
| `tackquote-app-lifecycle-deleted` | `app.deleted` |
| `tackquote-order-placed` | `checkout.order.placed` |

Every inbound webhook is HMAC-SHA256 verified against the per-shop secret before
its body is parsed, and compared in constant time.

## Not implemented yet

Stated plainly rather than implied by the manifest:

- **No admin module.** There is no `<admin>` section, so the app adds no UI
  inside the Shopware administration. A merchant links their store to a
  TackQuote tenant from the TackQuote seller portal, not from Shopware.
- **No storefront "Request a Quote" button.** That is template work, and an app
  cannot ship Twig overrides the way `../TackQuote` does. Storefront quoting on
  Cloud needs an App Script or a theme-level change and is not built here.
- **No writes back to Shopware.** Quote-to-order conversion inside Shopware is
  not implemented, which is why no write permissions are requested.
- **Not submitted to the Shopware Store.** Until it is, Cloud stores cannot
  install it at all.
