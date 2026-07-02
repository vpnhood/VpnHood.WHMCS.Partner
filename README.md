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
3. One or more **products** mapped to your account — you pick these from a dropdown when
   setting up your own products (no need to copy any reference by hand).
4. A **prepaid credit balance** on your account with them.

## Installation

1. Copy `modules/servers/vpnhoodpartner/` into your WHMCS `/modules/servers/` directory and
   `modules/addons/vpnhoodpartnerconfig/` into your WHMCS `/modules/addons/` directory.
2. In WHMCS Admin go to **System Settings → Addon Modules**, activate **VpnHood Partner
   Connector Configuration**, then click **Configure** and enter:
   - **Hub URL**: your provider's Hub URL (e.g. `https://store.yourprovider.com`).
   - **API Key**: your partner API Key.
   - **API Secret**: your partner API Secret.

That's the whole connection setup — there is **no WHMCS "Server" to configure**.

## Product setup

1. Go to **System Settings → Products/Services** and create a product at *your* price.
2. **Module Settings** tab:
   - **Module Name**: `VpnHood Partner Connector` (`vpnhoodpartner`).
   - **Upstream Product**: pick from the dropdown — it lists exactly the products your
     provider mapped to your account (each label shows its available billing cycles).
3. Set your own pricing on the **Pricing** tab, matching the upstream product's cycle(s).
   If you enable a billing cycle the upstream product does not offer, the Module Settings
   tab shows a warning, and orders on that cycle are rejected at purchase time.

## How it works

| Event in your WHMCS | What the connector does upstream |
|---------------------|----------------------------------|
| Order placed / activated | `order` → provisions a key, returns the access code |
| Renewal | `renew` → keeps the upstream key's expiry in sync |
| Suspend | `suspend` → suspends the upstream key |
| Unsuspend | `unsuspend` → reactivates the upstream key |
| Terminate / Cancel | `terminate` → expires the upstream key |

The delivered access code is shown to your customer in their **client area** for the
service.

## Troubleshooting

- **"Connection to VpnHood Partner Hub failed"** – check the **Hub URL** in the addon
  configuration and that it is reachable over HTTPS.
- **"Invalid API credentials" / "suspended"** – verify your API Key/Secret and that your
  partner account is active with your provider.
- **"Insufficient credit"** – top up your prepaid balance with your provider.
- All errors are recorded under **Utilities → Logs → Module Log** (search `vpnhoodpartner`).

## License

LGPL-2.1 — see [LICENSE](LICENSE).
