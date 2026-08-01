# Connector test fixtures

[connector-fixtures.json](connector-fixtures.json) declares everything the
**connector** side of the shared dev WHMCS must have before tests run:

| Fixture | Value |
| --- | --- |
| Installation | `vpnhoodpartner` + `vpnhoodpartnerconfig` module files present (warns if not deployed) |
| Connector config | `vpnhoodpartnerconfig` activated and pointed at the dev Hub with the test partner's ApiKey/ApiSecret, `SkipTlsVerify` on (dev) |
| Product group | **VpnHood! CONNECT (Partner Shop)** (`partner-shop`) |
| Product (onetime, $3.00) | **One-Month Premium Code** (`partner-one-month-premium-code`) → upstream `reseller-one-month-premium-code` |
| Product (recurring, $3.00/mo) | **One-Month Premium Code (Subscription)** (`partner-one-month-premium-code-subscription`) → upstream `…-subscription` |
| Buyer client | **`test-buyer@vpnhood.com`** ("Test Buyer") — the end customer who orders the connector products |

There is deliberately **no apply engine here**: the spec is applied by
`VpnHood.WHMCS/tests/bootstrap/init-skeleton.sh` (the hub repo owns the shared
dev environment and the idempotent skeleton engine). Running that one command
bootstraps hub + connector together; it picks this file up automatically when
the two repos are checked out side by side.

Dev-test topology: hub **and** connector are installed on the **same** dev
WHMCS. That works because the connector talks to the Hub over plain HTTPS —
it does not care that the Hub is the same install. Margin in the test data:
buyer pays $3.00 for the connector product, the connector orders upstream at
$2.00 from the reseller's credit.
