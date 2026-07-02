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
the products the provider mapped to their account. `_ConfigOptions` also renders a
**billing-cycle warning** when the product's enabled Pricing cycles are not all offered by the
selected upstream product — caught at config time, before any customer orders.

## Lifecycle → Hub action mapping

| WHMCS hook | Hub action | Notes |
|------------|-----------|-------|
| `_CreateAccount` | `order` | sends the service's `billingCycle`; stores `upstreamServiceId` + `accessCode` |
| `_Renew` | `renew` | sends `nextDueDate` for expiry sync |
| `_SuspendAccount` | `suspend` | |
| `_UnsuspendAccount` | `unsuspend` | |
| `_TerminateAccount` | `terminate` | |
| `_ClientArea` | — | renders the stored access code; no upstream round-trip |

The access code is fetched once at provisioning time and stored locally, so the client area
renders without calling the Hub again.

## Upstream Hub API contract (must match VpnHood.WHMCS)

`POST <hub>/modules/addons/vpnhoodpartnerhub/api.php`, JSON body `{ "action", ... }`,
headers `X-Vpnhood-Key`, `X-Vpnhood-Secret`. Response envelope:
`{ "success": true, "data": {...} }` or `{ "success": false, "error": "..." }`.
`HubClient::call` unwraps `data` and throws on `success=false`.

| Action | Request params | `data` returned |
|--------|----------------|-----------------|
| `getBalance` | — | `clientId, balance, currency` |
| `getProducts` | — | `products[] { downstreamRef, name, billingCycleMonths, availableCycles }` |
| `order` | `downstreamRef`, `billingCycle?`, `quantity?`, `customerReference?` | `keys[] { upstreamServiceId, orderId, deliveryType, accessCode|csv }` |
| `renew` | `upstreamServiceId`, `nextDueDate?` | `status, nextDueDate` |
| `suspend` / `unsuspend` / `terminate` / `cancel` | `upstreamServiceId` | `status` |
| `getOrder` | `upstreamServiceId` | `status, nextDueDate` |
| `getTransactions` | — | `transactions[]` |

> ⚠️ This table is the integration boundary. If you change it here, change it in the Hub
> repo's addon `README.md` and `docs/ARCHITECTURE.md` in the same release, or partners break.

`availableCycles` is a list of cycle lengths in months (e.g. `[1, 12]`). `billingCycle` is a
WHMCS cycle name (`monthly`…`triennially`); the Hub validates it against the product's
`availableCycles` and rejects an unsupported cycle (HTTP 422). The connector also warns about
mismatches at config time from `_ConfigOptions`.

## Stored service properties

`_CreateAccount` persists exactly two properties on the WHMCS service:
- `upstreamServiceId` — required by every lifecycle relay.
- `accessCode` — the delivered code shown in the client area.

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
