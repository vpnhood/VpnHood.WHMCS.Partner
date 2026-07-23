# VpnHood Partner Connector — Developer Guide

Developer-facing documentation for this repository. End-user/partner install steps are in
the top-level `README.md`; this document explains the **internals and the upstream API
contract** so the connector can be maintained and extended.

## What this repo is

A single WHMCS **server/provisioning module** (`vpnhoodpartner`) that a partner installs on
**their own WHMCS**. Instead of talking to a VPN access server, it provisions by calling the
partner's upstream provider WHMCS, where the **VpnHood! Partner Hub** addon is installed.

```
Partner's customer ─▶ Partner WHMCS (this module) ─▶ Provider WHMCS (Partner Hub API) ─▶ Access server
                                                       (paid from partner's prepaid credit)
```

The upstream Hub addon lives in the separate **VpnHood.WHMCS** repo
(`modules/addons/vpnhoodpartnerhub/`). This connector is intentionally decoupled and knows
**only** the Hub's HTTP contract (below) — it never touches the access server.

## Layout

```
modules/
  addons/vpnhoodpartnerconfig/
    vpnhoodpartnerconfig.php   global Hub connection settings (URL, key, secret)
  servers/vpnhoodpartner/
    vpnhoodpartner.php         WHMCS lifecycle hooks + _ConfigOptions + _ClientArea
    lib/HubClient.php          cURL client for the Hub API (key/secret over HTTPS)
    templates/
      clientarea.tpl           shows the delivered access code
      error.tpl
    whmcs.json
```

## Connection / configuration

This is a **server module** (`RequiresServer => false`) paired with a companion **addon**,
`vpnhoodpartnerconfig`, that holds the single global Hub connection — mirroring the
`vpnhoodconfig` + MANAGER pattern in the VpnHood.WHMCS repo. The partner configures it once
under **System Settings → Addon Modules → VpnHood Partner Connector Configuration**; there is
no WHMCS "Server" to set up.

`HubClient::fromConfig` reads the addon settings from `tbladdonmodules`:

| Addon setting   | Used as |
|-----------------|---------|
| `HubUrl`        | Hub base URL (bare host or full URL) |
| `ApiKey`        | partner API key → `X-Vpnhood-Key` |
| `ApiSecret`     | partner API secret → `X-Vpnhood-Secret` |
| `SkipTlsVerify` | DEV ONLY: skip TLS certificate verification |

The API path `/modules/addons/vpnhoodpartnerhub/api.php` is appended automatically
(`HubClient::API_PATH`).

Product config option `configoption1` = **Upstream Product** (`downstreamRef`). It is a
**dropdown** populated live from the Hub (`getProducts`), so the partner picks from exactly
the products the provider mapped to their account. `_ConfigOptions` also renders a config-time
**compatibility check** (`vpnhoodpartner_cycleNotice`), caught before any customer orders:

1. **Payment Type** — the partner product's WHMCS Payment Type (`tblproducts.paytype`:
   `free`/`onetime`/`recurring`) must equal the upstream product's `paymentType`. This is
   checked *first* because billing cycles only exist for recurring products — WHMCS stores a
   one-time price in the `monthly` pricing column, which a pure cycle comparison would
   misread as a phantom Monthly cycle.
2. **Billing cycles** (recurring products only) — when both sides are `recurring`, the
   product's enabled Pricing cycles must all be offered by the selected upstream product's
   `availableCycles`; otherwise a warning is shown.
3. **Allow Multiple Quantities** — with this Pricing-tab option WHMCS creates ONE service
   with quantity N (paid N×), but the connector stores exactly one `upstreamOrderId` +
   `accessTokenId` per service, so it cannot deliver N keys. The notice flags the option
   (comparing against the upstream's `allowMultipleQuantities`, `null` on older Hubs), and
   `_CreateAccount` rejects any service with quantity > 1. The Hub additionally rejects
   `order` calls with `quantity > 1` unless the upstream product allows multiple quantities.

## Lifecycle → Hub action mapping

| WHMCS hook | Hub action | Notes |
|------------|-----------|-------|
| `_CreateAccount` | `order` | sends the service's `billingCycle`; stores `upstreamOrderId` + `accessTokenId` |
| `_Renew` | `renew` | settles the outstanding upstream renewal invoice from partner credit |
| `_SuspendAccount` | `suspend` | |
| `_UnsuspendAccount` | `unsuspend` | |
| `_TerminateAccount` | `terminate` | |
| `_ClientArea` | `getAccessCode` | only on the "Get Premium Code" click (AJAX); the page render itself makes no Hub call |

The client area no longer renders a stored code. A **Get Premium Code** button fetches the
**current** code live from the Hub on click (mirroring the VpnHood Store module), so a rotated
or re-issued code is always correct. The normal page render still performs no upstream call.

**Renewal is manual upstream.** Recurring Hub products do not auto-renew: the upstream WHMCS
generates a renewal invoice and leaves it Unpaid until `renew` settles it from the partner's
credit. `renew` therefore no longer takes `nextDueDate` — the upstream derives the new term
when the invoice is paid. If the upstream has not generated that invoice yet, `renew` returns
**409** and `_Renew` fails loudly rather than reporting a renewal that did not happen.

## Upstream Hub API contract (must match VpnHood.WHMCS)

`POST <hub>/modules/addons/vpnhoodpartnerhub/api.php`, JSON body `{ "action", ... }`,
headers `X-Vpnhood-Key`, `X-Vpnhood-Secret`. Response envelope:
`{ "success": true, "data": {...} }` or `{ "success": false, "error": "..." }`.
`HubClient::call` unwraps `data` and throws on `success=false`.

| Action | Request params | `data` returned |
|--------|----------------|-----------------|
| `getBalance` | — | `clientId, balance, currency` |
| `getProducts` | — | `products[] { downstreamRef, name, paymentType, allowMultipleQuantities, billingCycleMonths, availableCycles }` |
| `order` | `downstreamRef`, `billingCycle?`, `quantity?`, `customerReference?` | `keys[] { upstreamOrderId, customerReference, deliveryType, accessTokenId + accessCode \| csv }` |
| `renew` | `upstreamOrderId` | `status, nextDueDate` (**409** when no renewal invoice is outstanding yet) |
| `suspend` / `unsuspend` / `terminate` / `cancel` | `upstreamOrderId` | `status` |
| `getOrder` | `upstreamOrderId` | `status, nextDueDate` |
| `getAccessCode` | `upstreamOrderId` | `accessTokenId, accessCode` |
| `getTransactions` | — | `transactions[]` |

> `upstreamOrderId` is the upstream WHMCS **order id** and is the handle for every action.
> The Hub resolves it to the underlying service itself, scoped to the calling partner, so an
> order belonging to another partner returns `404`. Note `getAccessCode` does **not** take
> `accessTokenId` — the Hub resolves the token from the partner's own order and never trusts
> an id from the request.

> ⚠️ This table is the integration boundary. If you change it here, change it in the Hub
> repo's addon `README.md` and `docs/ARCHITECTURE.md` in the same release, or partners break.

`paymentType` is the upstream product's WHMCS Payment Type (`free`/`onetime`/`recurring`).
When absent (a Hub build predating the field), the connector must NOT assume a value: it
skips the Payment Type comparison with an "update the Hub" info notice and falls back to the
cycle-only check. `availableCycles` is a list of cycle lengths in months (e.g. `[1, 12]`),
meaningful only when `paymentType` is `recurring`. `billingCycle` is a WHMCS cycle name
(`monthly`…`triennially`); the Hub validates it against the product's `availableCycles` and
rejects an unsupported cycle (HTTP 422) — except for `onetime`/`free` products, where
`billingCycle` is ignored (WHMCS reports such services' cycle as "One Time", not a cycle
name) and the Hub places the order as `onetime`. The connector also warns at config time
from `_ConfigOptions` about both a Payment Type mismatch and, for recurring products, an
unsupported billing cycle.

## Stored service properties

`_CreateAccount` persists exactly two properties on the WHMCS service:
- `upstreamOrderId` — required by every lifecycle relay and by `getAccessCode`.
- `accessTokenId` — reference/diagnostics only. It is **not** sent to the Hub; the Hub
  resolves the token from our own order.

(CSV/bulk delivery is not stored by the connector; it delivers a single access code.)

## Extending

- **New lifecycle relay:** add a `vpnhoodpartner_*` hook that calls
  `vpnhoodpartner_relayLifecycle($params, '<action>')`; add the matching action to the Hub.
- **Surface more upstream data in the client area:** prefer storing it at `_CreateAccount`
  time over per-view Hub calls (keeps the client area fast and resilient to Hub downtime).
- **Errors:** always `logModuleCall('vpnhoodpartner', ...)` and return a `VpnHood Partner
  Error: ...` string from lifecycle hooks (WHMCS shows it in the admin/module log).

## Conventions & testing

- PHP 7.4+; no PHP toolchain/lint is configured in this environment — verify on a live WHMCS.
- WHMCS module folder names: lowercase letters/numbers, no underscores/spaces.
- End-to-end test needs a partner WHMCS + a provider WHMCS running the Hub: create a product
  mapped to an allowed `downstreamRef`, place a test order as an end customer, confirm the
  key is delivered in the client area, then trigger renew/suspend/terminate and confirm they
  propagate upstream (check the Hub's `mod_vpnhood_partner_log`).
