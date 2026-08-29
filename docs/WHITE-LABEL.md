# White-Label Guide — run your own VPN brand

The partner connector is more than resale: it is the foundation for a complete
VPN brand of your own. Your storefront, your prices, your apps, your app-store
listings — with VpnHood invisible behind them, running the infrastructure.

White-labeling has three layers. Each one builds on the previous, and you can
stop at any of them:

```text
Layer 1  Your web store        your WHMCS + this connector      sell keys under your brand
Layer 2  Your branded apps     your builds of the open-source   your name, icon and package
                               VpnHood client                   on the stores
Layer 3  In-app purchases      the bundled vpnhoodiap addon     Google Play / App Store sales
                               on your WHMCS                    land as orders in YOUR store
```

At every layer the plumbing is the same: an order in **your WHMCS** is paid from
your **prepaid credit** with VpnHood, provisions a key upstream, and delivers the
access code to your customer. You set retail prices; the margin is yours.

## Layer 1 — your web store

This is the connector's core and is fully covered by the [README](../README.md):
install `vpnhoodpartner.zip`, configure your API key/secret, sync or create
products at your prices. Your customers buy on your site, receive an access code
in their client area, and redeem it in a VpnHood-compatible client app.

Everything your customer sees — the storefront, invoices, emails, support — is
your brand. VpnHood never appears in the transaction.

## Layer 2 — your branded apps

The VpnHood client apps are open source (LGPL-2.1), and the app's entire visual
identity lives in its bundled web UI package — so a branded build is your own
name, icon, colors and package/bundle id on top of the maintained engine.

What this means in practice:

- **You own the store listings.** The apps are published from **your** Google
  Play / Apple developer accounts, under your brand. VpnHood cannot publish them
  for you — the stores tie in-app products and payouts to the publisher account.
- **You track upstream releases.** Your build inherits engine fixes by rebuilding
  on new VpnHood releases; the LGPL terms travel with the code.
- **Access codes just work.** A customer who bought on your web store (Layer 1)
  enters their access code in your branded app — no extra integration needed.

Talk to VpnHood before starting a branded app: the team will point you at the
right app template and the branding surface for your case.

## Layer 3 — in-app purchases in your apps

`vpnhoodpartner.zip` bundles the **vpnhoodiap** addon (it stays inactive until
you configure it). With Layers 1–2 in place, it turns purchases made *inside*
your branded apps into normal orders in your WHMCS:

```text
customer buys in your app ─▶ Google/Apple pays YOU (you are the merchant)
        app sends proof    ─▶ vpnhoodiap on your WHMCS verifies it with the store,
                              creates the client + order + paid invoice,
        this connector     ─▶ provisions the key from your prepaid credit,
        the app receives   ─▶ the access code — automatically, in one call
```

Renewals, cancellations and refunds flow in through store webhooks and keep the
service, invoices and the upstream key in sync — including revoking refunded
purchases. App-store sales, web sales and support all live in the one WHMCS
panel you already run.

### What you need for Layer 3

These are **your** accounts and credentials — in-app purchases are always paid
to the app's publisher, so none of this can be shared with or provided by
VpnHood:

| | Google Play | Apple App Store |
| --- | --- | --- |
| Developer account | Play Console (publisher of your app) | App Store Connect |
| API access | A Google Cloud **service account** with the Android Publisher API enabled, invited into your Play Console (view financial data, manage orders) | An **App Store Server API key** (`.p8`, issuer id + key id) |
| Purchase lifecycle | A **Pub/Sub topic + authenticated push subscription** for Real-Time Developer Notifications | App Store **Server Notifications V2** pointed at your webhook URL |
| Products | Subscriptions/base plans created in Play Console | Auto-renewable subscriptions in App Store Connect |

### Setting it up

Each store has its own step-by-step guide covering the whole path — store
account, API credentials, webhook, catalog mapping, and the test purchase that
proves it end-to-end:

- **[Google Play setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-GOOGLE-PLAY.md)**
- **[Apple App Store setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-APPLE-APP-STORE.md)**
- **[How money appears in your WHMCS](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-MONEY.md)** — invoice semantics,
  reports, refunds, and what to tell your accountant. Read before the first sale.

In outline, both follow the same shape:

1. Install the **`vpnhoodiap`** package (its own release —
   [VpnHood.WHMCS.Iap](https://github.com/vpnhood/VpnHood.WHMCS.Iap/releases/latest),
   extracted at your WHMCS root), then activate the **VpnHood! IAP** addon and the
   **vpnhoodiappay** payment gateway in WHMCS admin.
2. **Apps tab**: register each of your store apps — package/bundle id, the OAuth
   client ids your app's sign-in uses, and the store credentials (stored
   encrypted, write-only). Saving generates the app's unique webhook URL; give
   that URL to the store's notification setup.
3. **Catalog tab**: map each store product (and base plan) to one of your
   connector products. The mapping decides what gets provisioned — an unmapped
   store product is refused cleanly rather than guessed at.
4. Store purchases now appear under **Purchases**, with every webhook recorded
   under **Events**.

Customer-facing invoice emails for app-store sales are suppressed automatically
(the store already receipts the customer); the addon's own cron keeps entitlements
reconciled against the stores daily.

### Availability

The WHMCS side is a package of its own — `vpnhoodiap` is released separately from
the connector and installed alongside it, so you take it only if you sell in-app
purchases, and it updates on its own schedule. On the app side, the
store-billing integration (Google sign-in/billing, Apple sign-in/StoreKit) is
part of the current VpnHood client rollout — coordinate with VpnHood before
wiring your branded app for in-app purchases so your build picks up the
integration at the right version.

## The money, in one picture

- **Web sale (Layer 1):** customer pays *your* payment gateway → your WHMCS
  order → wholesale key paid from your VpnHood credit.
- **In-app sale (Layer 3):** customer pays Google/Apple → the store pays *you*
  (minus its cut) → your WHMCS order records the sale → wholesale key paid from
  your VpnHood credit.

In both cases: your customer, your revenue, your support relationship. Your only
obligation to VpnHood is keeping the prepaid credit topped up.

## Checklist

- [ ] Layer 1: WHMCS + connector installed, products synced, prices set
- [ ] Layer 2: branded app builds published from your own developer accounts
- [ ] Layer 3: [Google Play setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-GOOGLE-PLAY.md) completed for your Android app
- [ ] Layer 3: [Apple App Store setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-APPLE-APP-STORE.md) completed for your iOS app
- [ ] Sandbox/internal-testing purchase verified end-to-end before going live

Questions about any layer — or to get started as a white-label partner — contact
VpnHood through your partner channel.
