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
modules/servers/vpnhoodpartner/
  vpnhoodpartner.php       WHMCS lifecycle hooks + _ConfigOptions + _ClientArea
  lib/HubClient.php        cURL client for the Hub API (key/secret over HTTPS)
  templates/
    clientarea.tpl         shows the delivered access code (Normal delivery)
    clientarea-csv.tpl     CSV download UI (bulk delivery)
    error.tpl
  whmcs.json
```

## Connection / configuration

The Hub connection is a WHMCS **Server** (`RequiresServer => true`). `HubClient::fromParams`
maps server fields:

| WHMCS server field | Used as |
|--------------------|---------|
| Hostname           | Hub base URL (bare host or full URL) |
| Secure (SSL)       | http vs https when scheme is omitted |
| Username           | partner API key → `X-Vpnhood-Key` |
| Password           | partner API secret → `X-Vpnhood-Secret` |

The API path `/modules/addons/vpnhoodpartnerhub/api.php` is appended automatically
(`HubClient::API_PATH`).

Product config option `configoption1` = **Upstream Product Reference** (`downstreamRef`),
the key the provider mapped to the partner's account.

## Lifecycle → Hub action mapping

| WHMCS hook | Hub action | Notes |
|------------|-----------|-------|
| `_CreateAccount` | `order` | stores `upstreamServiceId` + delivered code in `serviceProperties` |
| `_Renew` | `renew` | sends `nextDueDate` for expiry sync |
| `_SuspendAccount` | `suspend` | |
| `_UnsuspendAccount` | `unsuspend` | |
| `_TerminateAccount` | `terminate` | |
| `_ClientArea` | — | renders the stored code/CSV; no upstream round-trip |

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
| `getProducts` | — | `products[] { downstreamRef, name, billingCycleMonths }` |
| `order` | `downstreamRef`, `quantity?`, `customerReference?` | `keys[] { upstreamServiceId, orderId, deliveryType, accessCode|csv }` |
| `renew` | `upstreamServiceId`, `nextDueDate?` | `status, nextDueDate` |
| `suspend` / `unsuspend` / `terminate` / `cancel` | `upstreamServiceId` | `status` |
| `getOrder` | `upstreamServiceId` | `status, nextDueDate` |
| `getTransactions` | — | `transactions[]` |

> ⚠️ This table is the integration boundary. If you change it here, change it in the Hub
> repo's addon `README.md` and `docs/ARCHITECTURE.md` in the same release, or partners break.

## Stored service properties

`_CreateAccount` persists on the WHMCS service:
- `upstreamServiceId` — required by every lifecycle relay.
- `deliveryType` — `normal` | `csv` (chooses the client-area template).
- `accessCode` — for Normal delivery.
- `csv` — for bulk delivery.

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
