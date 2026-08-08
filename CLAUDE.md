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
- **Store the order id and the code.** `_CreateAccount` persists `upstreamOrderId` +
  `accessCode` in `serviceProperties`. The code is fetched once at provisioning time and
  displayed directly in the client area — no button, no AJAX, no re-fetch through the Hub.
- **Every lifecycle relay needs `upstreamOrderId`** from `serviceProperties` (use
  `vpnhoodpartner_upstreamOrderId()`); fail loudly if missing.
- **Errors:** `logModuleCall('vpnhoodpartner', ...)` and return a `VpnHood Partner Error: ...`
  string from lifecycle hooks.
- **Folder naming:** lowercase letters/numbers only (no underscores/spaces).
- **Bump the version for ANY change to a module's files** — templates, hooks, a comment —
  not only schema or DB changes. WHMCS decides what to reinstall by comparing the stamped
  version, so an edit shipped under an unchanged number silently never reaches the install.
- **Never hand-edit a module's version.** The root `VERSION` file is the single source of
  truth; `scripts/set-version.sh` stamps it into `vpnhoodpartnerconfig` (`_config()`) and
  `vpnhoodpartner` (`whmcs.json`). `.github/workflows/release.yml` bumps the patch number,
  tags and releases on every push to main. This repo versions **independently** of the Hub
  repo — the Hub API contract couples them, not the version number.
- **No build/lint/test tooling** is configured (no PHP CLI in this environment). The only CI
  is the release workflow, which versions and tags — it does not build or test. Verify on a
  live WHMCS pair (partner + provider) — see `docs/DEVELOPMENT.md`.

## Tests

- `tests/bootstrap/connector-fixtures.json` — connector-side fixtures for the shared dev
  WHMCS (installation config, partner-shop products, buyer client). Applied by the hub
  repo's `VpnHood.WHMCS/tests/bootstrap/init-skeleton.sh`, which owns the apply engine —
  run that before any test. Keep this spec in sync when connector config/products change.

## Dev server & credentials

- Credentials live outside the repo in `..\.user\whmcs\` (i.e. `<Vh root>\.user\whmcs\`):
  `ssh.openssh` (private key), `ssh.ppk`, `ssh.pub`.
- This connector is verified on the same dev WHMCS as the Hub:
  `ssh -i <Vh root>\.user\whmcs\ssh.openssh whmcsdev@webhost-ftps.vpnhood.com`, web root
  `/home/whmcsdev/web/whmcs-dev.vpnhood.com/public_html`, site `https://whmcs-dev.vpnhood.com`.

## Where things are

- Module: `modules/servers/vpnhoodpartner/`
- Hub connection settings + product-sync admin page: `modules/addons/vpnhoodpartnerconfig/`
- Hub HTTP client: `modules/servers/vpnhoodpartner/lib/HubClient.php`
- Client-area templates: `modules/servers/vpnhoodpartner/templates/`
- Developer guide + API contract: `docs/DEVELOPMENT.md`
