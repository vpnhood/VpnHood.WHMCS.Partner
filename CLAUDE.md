# CLAUDE.md

Guidance for working in this repository.

## What this is

A single WHMCS **server/provisioning module** (`vpnhoodpartner`) that a **partner** installs
on **their own WHMCS** to resell VpnHood keys. It provisions by calling the provider's
upstream WHMCS (the **VpnHood! Partner Hub** addon), paying from the partner's prepaid
**credit** there — it never talks to the VPN access server directly.

The upstream Hub addon lives in the separate **VpnHood.WHMCS** repo
(`modules/addons/vpnhoodpartnerhub/`). This connector is decoupled and depends only on the
Hub's HTTP contract.

## Read this first

**Before changing anything, read [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md).** It covers the
module internals, the server/config field mapping, the lifecycle→action mapping, the stored
service properties, and — most importantly — the **upstream Hub API contract**.

## Key rules

- **The Hub API table in `docs/DEVELOPMENT.md` is the integration boundary.** If you change
  what actions/payloads this connector sends, update the Hub repo (**VpnHood.WHMCS**) in the
  same release, or partners break.
- **Store ids, not the code.** `_CreateAccount` persists `upstreamOrderId` + `accessTokenId`
  in `serviceProperties`. The access code is **not** cached: the client area's "Get Premium
  Code" button fetches it live via the Hub's `getAccessCode`, so a rotated code is always
  correct. Page renders still make no Hub call — only the button click does.
- **Every lifecycle relay needs `upstreamOrderId`** from `serviceProperties` (use
  `vpnhoodpartner_upstreamOrderId()`); fail loudly if missing.
- **Errors:** `logModuleCall('vpnhoodpartner', ...)` and return a `VpnHood Partner Error: ...`
  string from lifecycle hooks.
- **Folder naming:** lowercase letters/numbers only (no underscores/spaces).
- **No build/lint/test tooling** is configured (no PHP CLI in this environment). Verify on a
  live WHMCS pair (partner + provider) — see `docs/DEVELOPMENT.md`.

## Where things are

- Module: `modules/servers/vpnhoodpartner/`
- Hub HTTP client: `modules/servers/vpnhoodpartner/lib/HubClient.php`
- Client-area templates: `modules/servers/vpnhoodpartner/templates/`
- Developer guide + API contract: `docs/DEVELOPMENT.md`
