# In-App Purchases — Google Play setup

How to connect **your** Google Play app to the bundled `vpnhoodiap` addon so
subscriptions bought inside your branded Android app become orders in your
WHMCS. Part of the [White-Label Guide](WHITE-LABEL.md); the general concepts
(money flow, catalog, what the addon does) are described there.

Everything below happens in accounts **you** own — Google pays the app's
publisher, so VpnHood cannot do any of these steps for you.

## Prerequisites

- Your branded Android app published in **your** Play Console (an internal
  testing track is enough to start).
- The connector installed and working (Layer 1 of the White-Label Guide), and
  the **VpnHood! IAP** addon + **vpnhoodiappay** gateway activated in your WHMCS.

## 1. Google Cloud: service account + API

1. In [Google Cloud Console](https://console.cloud.google.com/), pick (or
   create) a project and enable the **Google Play Android Developer API**.
2. Create a **service account** (e.g. `whmcs-iap@<project>.iam.gserviceaccount.com`)
   and download a **JSON key** for it. This key is what the addon uses to verify
   purchases — treat it like a password.

## 2. Play Console: grant the service account access

In **Play Console → Users and permissions**, invite the service account's email
with access to your app and these permissions:

- *View app information (read-only)*
- *View financial data*
- *Manage orders and subscriptions*

## 3. WHMCS: register the app

In **Addons → VpnHood! IAP → Apps**, add an app:

| Field | Value |
| --- | --- |
| Store | `googleplay` |
| Package / bundle name | your app's package name (e.g. `com.yourbrand.vpn`) |
| OAuth client ids | the OAuth 2.0 client ID(s) your app uses for Google sign-in (comma separated) |
| Credentials | paste the **service-account JSON key** — stored encrypted, write-only |

Save the row, then copy the generated **webhook URL** — you need it in the next
step. It looks like:

```
https://your-whmcs.example.com/modules/addons/vpnhoodiap/webhook.php?store=googleplay&t=<secret>
```

## 4. Pub/Sub: real-time developer notifications (RTDN)

Renewals, cancellations and refunds arrive through Google Pub/Sub:

1. In your Cloud project, create a **Pub/Sub topic** (any name).
2. On the topic, grant the Google Play publisher identity
   `google-play-developer-notifications@system.gserviceaccount.com` the
   **Pub/Sub Publisher** role.
3. Create a **subscription** on the topic with these settings:

   | Setting | Value |
   | --- | --- |
   | Subscription ID | your choice — e.g. `Portal.Push`, or `Portal.Push.Development` for a test install (bookkeeping only; nothing reads it) |
   | Delivery type | **Push** |
   | Endpoint URL | the webhook URL from step 3 |
   | Enable authentication | **ON** (OIDC token) — the webhook rejects unauthenticated pushes |
   | Service account | the push identity; can be the same service account from step 1 |
   | Audience | leave **empty** — it defaults to the endpoint URL, which is what the webhook verifies |
   | Expiration period | **Never expire** — the 31-day-inactivity default silently *deletes the subscription*, and notifications are lost from then on |
   | Message retention | default (7 days) — if your WHMCS is down, fixed later, every missed event redelivers itself; late/duplicate deliveries are safe (the addon dedups and re-fetches truth from the store) |
   | Ack deadline / retry policy | defaults |

   If the console warns that the Pub/Sub service agent needs the *Service
   Account Token Creator* role to mint tokens for the chosen account, accept
   the grant — it is Google's own signing plumbing.
4. Back in the WHMCS **Apps** tab, put that push service account's email into
   **Pub/Sub service account** — the webhook rejects pushes signed by anyone
   else.

## 5. Play Console: point RTDN at the topic

In **Play Console → Monetize → Monetization setup**, set the topic name
(`projects/<project>/topics/<name>`) and click **Send test notification**.

Success looks like: the request returns 200 and a row appears in the addon's
**Events** tab. If it doesn't, see Troubleshooting below.

## 6. Products and catalog mapping

1. In **Play Console → Monetize → Subscriptions**, create your subscriptions
   with base plans (and offers, if you use them).
2. In **Addons → VpnHood! IAP → Catalog**, map each `(product id, base plan id)`
   pair to one of your connector products and its billing cycle.

Only mapped products are ever provisioned. A purchase of an unmapped product is
recorded, refused cleanly, and flagged to you — never guessed at. Because
unacknowledged purchases are auto-refunded by Google after a few days, a
mapping mistake costs the customer nothing.

## 7. Test before going live

Add your Google account as a **license tester** (Play Console → Settings →
License testing) and buy a subscription from the internal-testing build of your
app. Then verify in WHMCS:

- **Purchases** tab: the purchase row is `provisioned`.
- A client exists, the order is **Active**, the invoice is **Paid** with the
  Google order id (`GPA.…`) as its transaction id.
- Your customer-visible service shows the delivered access code.
- No invoice email was sent to the buyer (the store already receipts them).

Test subscriptions renew every few minutes — within a few renewal cycles you
should also see paid renewal invoices appear, then a cancellation running to
its expiry. That exercises the whole lifecycle.

## Lifecycle reference

| Store event | What happens in your WHMCS |
| --- | --- |
| Purchase | Client + order + paid invoice, key provisioned, code delivered |
| Renewal | Renewal invoice paid, expiry advanced, upstream key kept in sync |
| Cancellation | Auto-renew off; service stays active until the paid-for expiry |
| Grace period / on hold | Service kept / suspended accordingly, recovers automatically |
| Refund / revoke | Service terminated, refund transaction recorded |

## Troubleshooting

- **Test notification doesn't arrive** — check the push subscription's endpoint
  URL matches the Apps tab exactly (the audience is pinned to it), OIDC
  authentication is enabled, and the push service-account email matches the
  **Pub/Sub service account** field.
- **`purchase.verify` fails with a store error** — the service account lacks
  Play Console permissions (step 2) or the Android Publisher API isn't enabled
  (step 1).
- **Purchase parks as unmapped** — the `(product id, base plan id)` pair isn't
  in the Catalog tab; add the mapping, the purchase is redeemed on retry.
- Every webhook and API call is recorded in the addon's **Events** and **Log**
  tabs; provisioning errors also appear in **Utilities → Logs → Module Log**.
