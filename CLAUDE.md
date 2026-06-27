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
- **Fetch once, store locally.** Read the access code at `_CreateAccount` time and persist it
  in `serviceProperties`; render the client area from stored data (no per-view Hub calls).
- **Every lifecycle relay needs `upstreamServiceId`** from `serviceProperties`; fail loudly
  if missing.
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
