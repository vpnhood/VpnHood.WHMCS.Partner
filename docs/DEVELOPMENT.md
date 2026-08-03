# VpnHood! Partner Connector — Developer Guide

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
                               + the addon page: upstream product sync
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
under **System Settings → Addon Modules → VpnHood! Partner Connector Configuration**; there is
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
   `accessCode` per service, so it cannot deliver N keys. The notice flags the option
   (comparing against the upstream's `allowMultipleQuantities`, `null` on older Hubs), and
   `_CreateAccount` rejects any service with quantity > 1. The Hub additionally rejects
   `order` calls with `quantity > 1` unless the upstream product allows multiple quantities.

## Product sync (addon page)

`vpnhoodpartnerconfig_output()` renders the addon's own admin page (**Addons → VpnHood
Partner Connector Configuration**). It calls the Hub's existing `getProducts` — **no new Hub
action, so the cross-repo contract is unchanged** — lists every product the provider mapped
to this partner, and creates a local WHMCS product for the ones that do not exist yet.

"Exists" is keyed on `tblproducts.servertype = 'vpnhoodpartner' AND configoption1 =
downstreamRef` — the same pair `_CreateAccount` sends upstream, so the page's status column
answers exactly the question that matters.

Created products (via the `AddProduct` localAPI):

| Field | Value | Why |
|-------|-------|-----|
| `module` / `configoption1` | `vpnhoodpartner` / `downstreamRef` | the mapping the connector needs, preselected |
| `paytype` | upstream `paymentType` | a mismatch is what `vpnhoodpartner_cycleNotice` flags |
| enabled cycles | upstream `availableCycles`, at **0.00** | the Hub says what a product *is*, never what to charge |
| `hidden` | yes | a 0.00 product must not be orderable before the admin prices it |
| `autosetup` | `payment` | provision when the customer's invoice is paid |
| `allowqty` | left off | the connector stores one `upstreamOrderId` + `accessCode` per service and rejects quantity > 1, so mirroring the upstream's setting would create a product whose orders always fail |

WHMCS specifics this relies on (verified on **9.0.3**):

- In `tblpricing`, a cycle column of `-1` means *disabled*; passing **0** through
  `AddProduct`'s `pricing` array is what **enables** a cycle. So the payload enables exactly
  the upstream's cycles and leaves the rest at -1.
- `AddProduct` writes only the currencies it is given, so the payload covers every row in
  `tblcurrencies`.
- `AddProduct` **silently ignores `allowqty`** — which happens to match the intent above, but
  means it can never be set from here.
- There is **no `DeleteProduct` API**. The sync is therefore deliberately **create-only**: it
  never edits or removes an existing product, and re-running it is a no-op.

A POST may only create a ref present in the `getProducts` response *just fetched for that
request* — the form's values are never trusted on their own. The form is CSRF-protected with
a per-admin-session token (same pattern as the Hub addon).

The page also flags products that need attention: a **Payment Type mismatch** against the
upstream, **Needs pricing** (every enabled cycle still 0), and **Hidden**.

Covered by `tests/integration/sync-products.test.sh` in the **VpnHood.WHMCS** repo.

## Lifecycle → Hub action mapping

| WHMCS hook | Hub action | Notes |
|------------|-----------|-------|
| `_CreateAccount` | `order` | sends the service's `billingCycle`; stores `upstreamOrderId` + `accessCode` |
| `_Renew` | `renew` | settles the outstanding upstream renewal invoice from partner credit |
| `_SuspendAccount` | `suspend` | forwards WHMCS's `suspendreason` upstream as `suspendReason` |
| `_UnsuspendAccount` | `unsuspend` | |
| `_TerminateAccount` | `terminate` | |
| `_ClientArea` | — | renders the stored `accessCode` directly; no Hub call on page view |

The client area shows the access code delivered at provisioning time — no button, no AJAX,
no re-fetch through the Hub. If the upstream code is ever rotated, the stored value goes
stale until the next full provisioning; there is currently no live re-fetch path from the
connector.

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
| `suspend` | `upstreamOrderId`, `suspendReason?` | `status` |
| `unsuspend` / `terminate` / `cancel` | `upstreamOrderId` | `status` |
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
- `upstreamOrderId` — required by every lifecycle relay.
- `accessCode` — the code delivered at provisioning time, rendered directly in the client
  area. It is **not** re-fetched from the Hub afterward.

(CSV/bulk delivery is not stored by the connector; it delivers a single access code.)

## Extending

- **New lifecycle relay:** add a `vpnhoodpartner_*` hook that calls
  `vpnhoodpartner_relayLifecycle($params, '<action>')`; add the matching action to the Hub.
- **Surface more upstream data in the client area:** prefer storing it at `_CreateAccount`
  time over per-view Hub calls (keeps the client area fast and resilient to Hub downtime).
- **Errors:** always `logModuleCall('vpnhoodpartner', ...)` and return a `VpnHood Partner
  Error: ...` string from lifecycle hooks (WHMCS shows it in the admin/module log).

## Versioning

One number covers the whole repo. Both halves of the connector ship in one zip, are
released together and are only ever tested together, so they always carry the same
version — a partner running mismatched halves is running an untested combination, and
the addon page says so.

`VERSION` at the repo root is the source of truth; `scripts/set-version.sh` copies it
into the two places that ship it. SemVer, optionally with a pre-release tail
(`1.5.0-rc.1`) — but never build metadata (`+sha`), because PHP's `version_compare()`
cannot order it.

- **`modules/addons/vpnhoodpartnerconfig/vpnhoodpartnerconfig.php`** — the `'version'`
  key of `_config()`. This one is **functional**: WHMCS records it in `tbladdonmodules`
  and calls `vpnhoodpartnerconfig_upgrade()` when the file on disk declares a newer one,
  so this number is what triggers a partner's upgrade.
- **`modules/servers/vpnhoodpartner/whmcs.json`** — the `"version"` key. WHMCS has no
  native version display for provisioning modules, so the addon page reads it back
  (`vpnhoodpartnerconfig_versionLine`) and compares it with the addon's own.

Because the addon's number *drives behaviour on a partner's install*, the version has
to be correct in the committed source at the commit it describes. That is why it is a
file in the repo and not something derived from the git tag: a tag cannot carry a
number into the zip. The tag mirrors `VERSION`; it never defines it.

`vpnhoodpartnerconfig_upgrade($vars)` is the only hook that runs on an upgrade. Guard
every migration with `version_compare($vars['version'], 'x.y.z', '<')` and make it
idempotent — partners jump several versions at once, and a failed upgrade is re-run on
the next admin page load. **Extracting the zip only adds and overwrites**: when a
release deletes a module file, `unlink()` it there or partners keep running dead code.

## Releasing

`.github/workflows/release.yml`, run by hand — nothing releases on push.

```powershell
./_publish.ps1              # patch bump, tag, release
./_publish.ps1 minor        # or major / none
./_publish.ps1 -Version 2.0.0-rc.1   # exact number; auto-marked as a pre-release
./_publish.ps1 -Draft       # build and tag, but leave the release unpublished
```

`_publish.ps1` checks your tree matches `origin` (the zip is built from the pushed
branch, so uncommitted work would silently not be in it), dispatches the workflow and
follows it. Or use **Actions → Release → Run workflow**.

**The bump happens in CI, never locally.** The workflow increments `VERSION`, runs
`set-version.sh`, commits, tags `v<version>` and publishes. Two people releasing at
once cannot collide: `concurrency: group: release` serialises the runs, so the second
checks out the branch after the first has pushed its bump and reads the incremented
number. `patch` is the default; `minor`/`major` are a judgement about what changed and
stay a human decision.

The workflow refuses to release if `set-version.sh --check` finds drift, if the PHP
does not lint, or if the tag already exists — a shipped version is immutable, because
partners already have that exact zip.

The only permission it needs is `contents: write`, using the automatic `GITHUB_TOKEN`;
there is no secret to configure. If `main` is ever protected against direct pushes,
that bump commit is what will fail — allow `github-actions[bot]` to bypass, or release
from an unprotected branch.

The asset is `vpnhoodpartner-<version>.zip`, containing **only** `modules/` (plus a
copy of `LICENSE` in each module folder). Everything in it lands in the partner's WHMCS
root, so `docs/`, `scripts/`, `tests/` and `.github/` are deliberately absent — the
workflow fails the build if any path outside `modules/` gets in. It is byte-stable for
a given commit, and published with a `.sha256` alongside.

## Conventions & testing

- PHP 7.4+; no PHP toolchain/lint is configured in this environment — verify on a live WHMCS.
- WHMCS module folder names: lowercase letters/numbers, no underscores/spaces.
- End-to-end test needs a partner WHMCS + a provider WHMCS running the Hub: create a product
  mapped to an allowed `downstreamRef`, place a test order as an end customer, confirm the
  key is delivered in the client area, then trigger renew/suspend/terminate and confirm they
  propagate upstream (check the Hub's `mod_vpnhood_partner_log`).
