# In-App Purchases — Apple App Store setup

How to connect **your** iOS app to the bundled `vpnhoodiap` addon so
subscriptions bought inside your branded app become orders in your WHMCS. Part
of the [White-Label Guide](WHITE-LABEL.md); the general concepts (money flow,
catalog, what the addon does) are described there.

Everything below happens in accounts **you** own — Apple pays the app's
publisher, so VpnHood cannot do any of these steps for you.

## Prerequisites

- Your branded iOS app in **your** App Store Connect (TestFlight is enough to
  start), with a bundle id.
- The connector installed and working (Layer 1 of the White-Label Guide), and
  the **VpnHood! IAP** addon + **vpnhoodiappay** gateway activated in your WHMCS.

## 1. App Store Connect: Server API key

The addon verifies every purchase directly with Apple through the App Store
Server API, authenticated by a key you generate once:

1. In **App Store Connect → Users and Access → Integrations → App Store Server
   API**, generate an API key.
2. Note the **Issuer ID** and the key's **Key ID**, and download the **`.p8`
   private key** — Apple lets you download it exactly once; store it safely.

## 2. WHMCS: register the app

In **Addons → VpnHood! IAP → Apps**, add an app:

| Field | Value |
| --- | --- |
| Store | `appstore` |
| Package / bundle name | your app's bundle id (e.g. `com.yourbrand.vpn`) |
| OAuth client ids | the audience(s) of your Sign in with Apple tokens — normally just the bundle id (add your Services ID too if you also sign in from the web) |
| Credentials | a JSON object with the three values from step 1 (below) — stored encrypted, write-only |

The credentials JSON:

```json
{
  "issuerId": "xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "keyId": "ABC123DEFG",
  "privateKey": "-----BEGIN PRIVATE KEY-----\n...\n-----END PRIVATE KEY-----"
}
```

Save the row, then copy the generated **webhook URL** — you need it in the next
step. It looks like:

```
https://your-whmcs.example.com/modules/addons/vpnhoodiap/webhook.php?store=appstore&t=<secret>
```

## 3. App Store Connect: server notifications

Renewals, cancellations and refunds arrive through **App Store Server
Notifications V2**:

1. In **App Store Connect → your app → App Information → App Store Server
   Notifications**, set the **Version 2** notification URL — for both
   **Production** and **Sandbox** — to the webhook URL from step 2.

There is nothing else to authenticate: Apple signs every notification, and the
addon verifies that signature against Apple's own root certificate in addition
to the secret in the URL.

## 4. Products and catalog mapping

1. In **App Store Connect → your app → Subscriptions**, create your
   auto-renewable subscriptions and note each **Product ID**.
2. In **Addons → VpnHood! IAP → Catalog**, map each Product ID to one of your
   connector products and its billing cycle. Leave the base-plan field empty —
   base plans are a Google Play concept; an Apple product maps on its Product
   ID alone.

Only mapped products are ever provisioned. A purchase of an unmapped product is
recorded, refused cleanly, and flagged to you — never guessed at.

## 5. Test before going live

Create a **sandbox tester** (App Store Connect → Users and Access → Sandbox)
and buy a subscription in your TestFlight/development build while signed in as
that tester. Sandbox needs no special configuration — when Apple's production
environment reports the transaction as unknown, the addon retries against the
sandbox automatically.

Then verify in WHMCS:

- **Purchases** tab: the purchase row is `provisioned`.
- A client exists, the order is **Active**, the invoice is **Paid** with the
  Apple transaction id as its transaction id.
- Your customer-visible service shows the delivered access code.
- No invoice email was sent to the buyer (the store already receipts them).

Sandbox subscriptions renew every few minutes — within a few cycles you should
see paid renewal invoices, then a cancellation running to its expiry. That
exercises the whole lifecycle.

## Lifecycle reference

| Store event | What happens in your WHMCS |
| --- | --- |
| Purchase | Client + order + paid invoice, key provisioned, code delivered |
| Renewal (incl. billing recovery) | Renewal invoice paid, expiry advanced, upstream key kept in sync |
| Auto-renew turned off | Service stays active until the paid-for expiry |
| Grace period / billing retry | Service kept / suspended accordingly, recovers automatically |
| Refund / revoke | Service terminated, refund transaction recorded |

## Notes for your app build

- Purchases must carry the account token the app obtains at sign-in
  (`appAccountToken`) — the store purchase is bound to the signed-in customer,
  and a purchase presented by a different account is refused. The VpnHood client
  integration handles this; it is only relevant if you modify the billing code.
- Sign in with Apple obliges an in-app account-deletion option under Apple's
  review guidelines (5.1.1(v)) — plan for it in your listing.

## Troubleshooting

- **Notifications don't arrive** — confirm the V2 URL (not V1) is set for both
  Production and Sandbox and matches the Apps tab URL exactly.
- **`purchase.verify` fails with a store error** — the credentials JSON is
  malformed (all three fields required, `privateKey` with `\n` line breaks) or
  the API key was revoked in App Store Connect.
- **Sign-in rejected with an audience error** — the token's audience isn't in
  **OAuth client ids**; add your bundle id (and Services ID, if used).
- **Purchase parks as unmapped** — the Product ID isn't in the Catalog tab; add
  the mapping, the purchase is redeemed on retry.
- Every webhook and API call is recorded in the addon's **Events** and **Log**
  tabs; provisioning errors also appear in **Utilities → Logs → Module Log**.
