# VpnHood Partner Connector (WHMCS)

Resell **VpnHood** VPN keys from **your own WHMCS** at **your own prices**, without
running any VPN infrastructure and without writing any code.

When your customer buys a VPN product on your WHMCS, this module places an order on
your account with your provider's WHMCS (the **VpnHood! Partner Hub**), pays for it
from your **prepaid credit balance** there, receives the access key, and delivers it
to your customer — automatically.

```
Your customer ─▶ Your WHMCS (this module) ─▶ Provider's WHMCS (Partner Hub) ─▶ VpnHood access server
                                              (paid from your prepaid credit)
```

You never connect to the VPN access server directly, and you never handle the
provider's payment — you simply keep credit topped up with your provider.

## What you need from your provider

Your provider will give you:

1. **Hub URL** – the address of their WHMCS (e.g. `https://store.yourprovider.com`).
2. **API Key** and **API Secret** – your partner credentials.
3. One or more **Upstream Product References** (e.g. `vpn-monthly`) — the products you
   are allowed to sell.
4. A **prepaid credit balance** on your account with them.

## Installation

1. Copy `modules/servers/vpnhoodpartner/` into your WHMCS `/modules/servers/` directory.
2. In WHMCS Admin go to **System Settings → Products/Services → Servers → Add New Server**:
   - **Hostname**: your provider's Hub URL (e.g. `store.yourprovider.com`).
   - **Secure (SSL)**: enabled.
   - **Username**: your **API Key**.
   - **Password**: your **API Secret**.
   - **Module**: select **VpnHood Partner Connector**.
   - Click **Test Connection** is not required; save the server.
3. (Recommended) Put this server into a **Server Group** for assignment.

## Product setup

1. Go to **System Settings → Products/Services** and create a product at *your* price.
2. **Module Settings** tab:
   - **Module Name**: `VpnHood Partner Connector` (`vpnhoodpartner`).
   - **Server Group**: the group containing your Hub server.
   - **Upstream Product Reference**: the reference your provider assigned (e.g. `vpn-monthly`).
3. Set your own pricing on the **Pricing** tab. Set the billing cycle to match the
   upstream product's cycle.

## How it works

| Event in your WHMCS | What the connector does upstream |
|---------------------|----------------------------------|
| Order placed / activated | `order` → provisions a key, returns the access code |
| Renewal | `renew` → keeps the upstream key's expiry in sync |
| Suspend | `suspend` → suspends the upstream key |
| Unsuspend | `unsuspend` → reactivates the upstream key |
| Terminate / Cancel | `terminate` → expires the upstream key |

The delivered access code is shown to your customer in their **client area** for the
service. (Bulk/CSV products show a CSV download instead.)

## Troubleshooting

- **"Connection to VpnHood Partner Hub failed"** – check the server Hostname/SSL and that
  the Hub URL is reachable.
- **"Invalid API credentials" / "suspended"** – verify your API Key/Secret and that your
  partner account is active with your provider.
- **"Insufficient credit"** – top up your prepaid balance with your provider.
- All errors are recorded under **Utilities → Logs → Module Log** (search `vpnhoodpartner`).

## License

LGPL-2.1 — see [LICENSE](LICENSE).
