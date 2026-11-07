# White-Label Guide — run your own VPN brand

The partner connector is more than resale: it is the foundation for a complete
VPN brand of your own. Your storefront, your prices, your apps, your app-store
listings — with VpnHood invisible behind them, running the infrastructure.

White label is this connector plus your own app. In-app purchases are an option
you can add:

```text
Required  Your web store       your WHMCS + this connector      sell premium codes under your brand
Required  Your branded apps    your builds of the open-source   your name, icon and package
                               VpnHood client                   on the stores
Optional  In-app purchases     the vpnhoodiap addon (its own    Google Play / App Store sales
                               release) on your WHMCS           land as orders in YOUR store
```

Every sale works the same way: an order in **your WHMCS** is paid from your
**prepaid credit** with VpnHood, provisions a key upstream, and delivers the
premium code to your customer. You set retail prices; the margin is yours.

## Your web store

This is the connector's core and is fully covered by the [README](../README.md):
install `vpnhoodpartner.zip`, configure your API key/secret, sync or create
products at your prices. Your customers buy on your site, receive a premium code
in their client area, and redeem it in a VpnHood-compatible client app.

Everything your customer sees — the storefront, invoices, emails, support — is
your brand. VpnHood never appears in the transaction.

## Your branded apps

The VpnHood client apps are open source (LGPL-2.1), so a branded build is your
own name, logo, icon and package/bundle id on top of the maintained engine.

What this means in practice:

- **You own the store listings.** The apps are published from **your** Google
  Play / Apple developer accounts, under your brand, because the stores tie in-app
  products and payouts to the publisher account. You can publish them yourself,
  or VpnHood can do it for you as a separate service.
- **You track upstream releases.** Your build inherits engine fixes by rebuilding
  on new VpnHood releases; the LGPL terms travel with the code.
- **Premium codes just work.** A customer who bought on your web store enters
  their premium code in your branded app — no extra integration needed.

Talk to VpnHood before starting a branded app: the team will point you at the
right app template and the branding surface for your case.

## In-app purchases in your apps (optional)

The **vpnhoodiap** addon is a package of its own, installed from its own release
beside this connector (setup below). With your store and apps in place, it turns purchases
made *inside* your branded apps into normal orders in your WHMCS:

```text
customer buys in your app ─▶ Google/Apple pays YOU (you are the merchant)
        app sends proof    ─▶ vpnhoodiap on your WHMCS verifies it with the store,
                              creates the client + order + paid invoice,
        this connector     ─▶ provisions the key from your prepaid credit,
        the app receives   ─▶ the premium code — automatically, in one call
```

Renewals, cancellations and refunds flow in through store webhooks and keep the
service, invoices and the upstream key in sync — including revoking refunded
purchases. App-store sales, web sales and support all live in the one WHMCS
panel you already run.

### What you need for in-app purchases

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
   **vpnhoodiappay** payment gateway in WHMCS admin. On WHMCS 9.1 or later, also set
   **Invoice Immutability** (General Settings → Invoices) to *Immutability disabled
   (billing notes still enabled)*: the addon corrects and cancels store invoices, which
   9.1's default refuses.
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

- **Web sale:** customer pays *your* payment gateway → your WHMCS
  order → wholesale key paid from your VpnHood credit.
- **In-app sale:** customer pays Google/Apple → the store pays *you*
  (minus its cut) → your WHMCS order records the sale → wholesale key paid from
  your VpnHood credit.

In both cases: your customer, your revenue, your support relationship. Your only
obligation to VpnHood is keeping the prepaid credit topped up.

## Checklist

- [ ] Web store: WHMCS + connector installed, products synced, prices set
- [ ] Apps: branded app builds published from your own developer accounts
- [ ] In-app purchases on WHMCS 9.1+: Invoice Immutability set to *Immutability disabled (billing notes still enabled)*
- [ ] In-app purchases: [Google Play setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-GOOGLE-PLAY.md) completed for your Android app
- [ ] In-app purchases: [Apple App Store setup](https://github.com/vpnhood/VpnHood.WHMCS.Iap/blob/main/docs/IAP-APPLE-APP-STORE.md) completed for your iOS app
- [ ] Sandbox/internal-testing purchase verified end-to-end before going live

Questions about any of this — or to get started as a white-label partner — contact
VpnHood through your partner channel.
