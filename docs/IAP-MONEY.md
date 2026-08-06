# In-App Purchases — how money appears in your WHMCS

What the `vpnhoodiap` addon writes into your billing tables, what those numbers
mean, and what they deliberately do **not** mean. Read this before your first
store sale — and hand it to whoever does your accounting.

Part of the [White-Label Guide](WHITE-LABEL.md). Store setup:
[Google Play](IAP-GOOGLE-PLAY.md) · [Apple App Store](IAP-APPLE-APP-STORE.md).

## The one rule everything follows

**The store is the merchant of record.** Google/Apple charge the customer,
keep their cut, handle taxes and refunds, and pay you through their payout
cycle. Your WHMCS never touches the customer's money — the addon only creates
matching records so store customers look and behave like web customers:
a client, an order, a service to provision, and a paid invoice for the books.

Everything the addon writes is filtered to its own bookkeeping gateway,
**`vpnhoodiappay`** ("In-App Purchase (billed by the app store)"). Your web
sales, invoices, and mail are never touched.

## What lands on a store invoice

Every store purchase and renewal creates an invoice that is immediately marked
**Paid** with the store's own order id as the transaction id. Its total follows
one rule — *the truth when it is knowable, an explicit flag when it is not*:

| Case | Invoice total | Example |
| --- | --- | --- |
| Store charged in the client's WHMCS currency | **the real charge** | Play charged $46.99 → invoice $46.99 |
| Store charged in a different currency | **0.00** — the "money lives at the store" flag | Play charged ₺259.99 → invoice 0.00 |
| Real charge unknown (API hiccup) | the WHMCS product's book price | fallback only |

The real charge — including a foreign-currency one — is always written into the
invoice line text (*"The store charged 259.99 TRY, see your store receipt"*)
and shown in **Addons → VpnHood! IAP → Purchases → "Store Paid"**, so support
can always answer "what did this customer actually pay" without Play Console
access. Amounts are **never converted** between currencies: a converted number
would match neither the customer's receipt nor your payout, so it is not
invented in the first place.

## What your reports will show

- **Counts are always complete**: purchases, renewals, refunds per day/product
  all appear, filterable by the `vpnhoodiappay` gateway.
- **Values are exact for same-currency sales and 0.00 for foreign ones** — so a
  revenue graph that includes store sales *undercounts* foreign revenue by
  design.
- **For real accounting, use the store's payout reports** (Play Console /
  App Store Connect financial reports). They are the only numbers that include
  the store's commission and settled currency conversion. When reconciling,
  exclude the `vpnhoodiappay` gateway from WHMCS revenue and take the store
  payouts instead.
- Store-collected VAT never appears in WHMCS tax reports (invoice lines are
  written untaxed — the store already handled tax).

## Refunds

Refunds are **store-initiated only** (Play Console / App Store — an admin
cannot refund a store customer from WHMCS, there is no store money to move
from here). When the store refunds or revokes a purchase, the addon hears
about it (push notification and a daily safety sweep) and mirrors it: the
service is terminated, the invoice is marked Refunded, and a reversing
transaction is booked for whatever was originally recorded. Replayed
notifications never double-book.

## Renewals

Your WHMCS never charges anyone — there is nothing to charge. The renewal
invoice WHMCS generates simply waits (all customer mail for `vpnhoodiappay`
invoices is suppressed) until the store reports the renewal; the addon then
marks it paid with the new store order id, which is what advances the due date
and extends the service. If a subscription ends at the store, the service is
terminated and its leftover unpaid renewal invoice is cancelled automatically.

## Customer-visible surface

Store customers get **no email from WHMCS** — no invoices, reminders, payment
confirmations, or product welcome mail (the store sends its own receipts). If
one ever logs into your client area, every store invoice is marked Paid, names
the store, and says nothing is due.

## If you deactivate the addon

The API and webhook endpoints answer 404, processing stops, and nothing else
in your WHMCS changes. In-flight purchases are protected by the store itself:
an unacknowledged purchase is automatically refunded by Google after a few
days, so customers are never charged for something that was never delivered.
Tables and history are kept for reactivation.
