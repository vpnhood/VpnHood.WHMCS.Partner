<?php

/**
 * VpnHood! Partner (connector)
 *
 * A WHMCS server/provisioning module that a PARTNER installs on THEIR OWN WHMCS.
 * When the partner's customer orders a VPN product, this module does NOT talk to
 * the VpnHood access server directly. Instead it calls the partner's upstream
 * WHMCS ("VpnHood! Partner Hub" addon), which places an order on the partner's
 * account there (paid from the partner's prepaid credit balance), provisions the
 * key on the access server, and returns the access code. The connector then
 * delivers that code to the partner's own customer.
 *
 * Configure the connection once under WHMCS → System Settings → Addon Modules →
 * VpnHood Partner Connector Configuration (Hub URL, API key, API secret). The
 * per-product upstream mapping is chosen from a dropdown on the product's Module
 * Settings tab (populated live from the Hub).
 *
 * @see  README.md for setup steps.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . '/lib/HubClient.php';

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodPartner\HubClient;

function vpnhoodpartner_MetaData(): array
{
    return [
        'DisplayName'   => 'VpnHood Partner Connector',
        'APIVersion'    => '1.1',
        // Connection lives in the vpnhoodpartnerconfig addon, not a WHMCS Server.
        'RequiresServer' => false,
    ];
}

/**
 * Product configuration: pick which upstream Hub product this product maps to.
 *
 * The dropdown is populated live from the Hub (getProducts) so the partner selects
 * from exactly the products the provider mapped to their account. When the product's
 * enabled billing cycles do not all match the upstream product's available cycles, a
 * warning is shown here so it is caught at config time — before any customer orders.
 */
function vpnhoodpartner_ConfigOptions(array $params = []): array
{
    try {
        $hub = HubClient::fromConfig();
        $products = $hub->call('getProducts')['products'] ?? [];

        $options = [];
        $cyclesByRef = [];
        $payTypeByRef = [];
        $allowQtyByRef = [];
        foreach ($products as $p) {
            $ref = (string) ($p['downstreamRef'] ?? '');
            if ($ref === '') {
                continue;
            }

            // null = the Hub predates the paymentType field; never invent a type for it.
            $payType = isset($p['paymentType'])
                ? vpnhoodpartner_normalizePayType((string) $p['paymentType'])
                : null;
            $payTypeByRef[$ref] = $payType;

            // null = the Hub predates the allowMultipleQuantities field.
            $allowQtyByRef[$ref] = isset($p['allowMultipleQuantities'])
                ? (bool) $p['allowMultipleQuantities']
                : null;

            $available = array_map('intval', $p['availableCycles'] ?? []);
            if (!$available && isset($p['billingCycleMonths'])) {
                $available = [(int) $p['billingCycleMonths']];
            }
            $cyclesByRef[$ref] = $available;

            $label = (string) ($p['name'] ?? $ref);
            // Billing cycles are only meaningful for recurring products; for one-time/free
            // products show the payment type instead of a (phantom) "Monthly" cycle. An
            // unknown type (older Hub) falls back to the legacy cycle labels.
            if ($payType === null || $payType === 'recurring') {
                $cycleLabels = array_map('vpnhoodpartner_cycleLabel', $available);
                if ($cycleLabels) {
                    $label .= ' — ' . implode(', ', $cycleLabels);
                }
            } else {
                $label .= ' — ' . vpnhoodpartner_payTypeLabel($payType);
            }
            $options[$ref] = $label;
        }

        $field = [
            'FriendlyName' => 'Upstream Product',
            'Type'         => 'dropdown',
            'Options'      => $options,
            'Description'  => 'The product your provider mapped to your account.',
            'Default'      => '',
        ];

        // Config-time billing-cycle check, folded into THIS field's description so it always
        // renders (a separate 'none' field is not reliably shown by WHMCS). It reports its
        // state in every case, so a missing banner is never silent/ambiguous.
        $field['Description'] .= vpnhoodpartner_cycleNotice($params, $cyclesByRef, $payTypeByRef, $allowQtyByRef);

        return ['downstreamRef' => $field];
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return [
            'error' => [
                'FriendlyName' => 'VpnHood Partner Connector',
                'Type'         => 'none',
                'Description'  => "<div class='alert alert-danger' style='margin-bottom:0;'>Could not load upstream"
                    . ' products: ' . htmlspecialchars($e->getMessage())
                    . '. Check <b>System Settings → Addon Modules → VpnHood Partner Connector Configuration</b>.</div>',
            ],
        ];
    }
}

/** Human label for a billing cycle length in months. */
function vpnhoodpartner_cycleLabel(int $months): string
{
    $labels = [1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually', 12 => 'Annually', 24 => 'Biennially', 36 => 'Triennially'];
    return $labels[$months] ?? ($months . ' mo');
}

/** Normalize a WHMCS "Payment Type" to one of free|onetime|recurring (defaulting to recurring). */
function vpnhoodpartner_normalizePayType(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['free', 'onetime', 'recurring'], true) ? $type : 'recurring';
}

/** Human label for a WHMCS "Payment Type". */
function vpnhoodpartner_payTypeLabel(string $type): string
{
    $labels = ['free' => 'Free', 'onetime' => 'One Time', 'recurring' => 'Recurring'];
    return $labels[vpnhoodpartner_normalizePayType($type)];
}

/** WHMCS "Payment Type" (free|onetime|recurring) configured on a product's Details tab. */
function vpnhoodpartner_productPaymentType(int $productId): string
{
    $paytype = (string) Capsule::table('tblproducts')->where('id', $productId)->value('paytype');
    return vpnhoodpartner_normalizePayType($paytype);
}

/**
 * Alert when the partner product has "Allow Multiple Quantities" enabled (Pricing tab).
 *
 * With that option, WHMCS creates ONE service with a quantity — the customer pays N× the
 * price — but the connector stores exactly one upstream service id + access code per
 * service, so it cannot deliver N keys. _CreateAccount therefore rejects quantity > 1,
 * and the Hub likewise rejects bulk orders unless the upstream product allows them.
 * $upstreamAllows: the upstream product's setting, or null when the Hub doesn't report it.
 */
function vpnhoodpartner_quantityNotice(int $pid, ?bool $upstreamAllows): string
{
    $localAllows = (bool) Capsule::table('tblproducts')->where('id', $pid)->value('allowqty');
    if (!$localAllows) {
        return '';
    }

    if ($upstreamAllows === null) {
        $upstreamText = 'The Hub does not report whether the upstream product allows it.';
    } elseif ($upstreamAllows) {
        $upstreamText = 'The upstream product allows it, but the connector delivers exactly one access key per service.';
    } else {
        $upstreamText = 'The upstream product does not allow it.';
    }

    return vpnhoodpartner_cycleAlert(
        'danger',
        'This product has <b>Allow Multiple Quantities</b> enabled. ' . $upstreamText
        . ' <b>Orders with a quantity above 1 will be rejected.</b> Disable it on the <b>Pricing</b> tab.'
    );
}

/**
 * Billing cycle lengths (in months) enabled on a WHMCS product's Pricing tab.
 * A cycle is enabled when its tblpricing column is >= 0 (WHMCS uses -1 for disabled).
 */
function vpnhoodpartner_productEnabledCycleMonths(int $productId): array
{
    $row = Capsule::table('tblpricing')
        ->where('type', 'product')
        ->where('relid', $productId)
        ->orderBy('currency')
        ->first();
    if (!$row) {
        return [];
    }

    $map = ['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12, 'biennially' => 24, 'triennially' => 36];
    $months = [];
    foreach ($map as $column => $m) {
        if (isset($row->$column) && (float) $row->$column >= 0) {
            $months[] = $m;
        }
    }
    return $months;
}

/** Render an alert box for the cycle notice. */
function vpnhoodpartner_cycleAlert(string $level, string $html): string
{
    return "<div class='alert alert-{$level}' style='margin-top:8px;margin-bottom:0;'>" . $html . '</div>';
}

/**
 * Config-time compatibility check. Unlike a plain warning, this reports its state in EVERY
 * case so a missing banner is never ambiguous: it tells the admin whether the product id was
 * found, whether a selection has been saved, whether the Payment Type matches the upstream
 * product, and (for recurring products) whether the billing cycles match or mismatch.
 *
 * Payment Type is checked before cycles: billing cycles only exist for recurring products, so
 * a one-time/free product mapped to a recurring upstream (or vice versa) is a mismatch that a
 * pure cycle comparison would miss — WHMCS stores a one-time price in the "monthly" column,
 * which would otherwise read as a phantom Monthly cycle.
 *
 * "Allow Multiple Quantities" is also checked: the connector stores exactly one upstream
 * service id + access code per WHMCS service, so a quantity above 1 cannot be delivered
 * faithfully and is rejected at order time (see _CreateAccount).
 */
function vpnhoodpartner_cycleNotice(array $params, array $cyclesByRef, array $payTypeByRef, array $allowQtyByRef): string
{
    // WHMCS does not reliably pass 'pid' into _ConfigOptions on the product edit page;
    // the product id is in the request there (configproducts.php?action=edit&id=X).
    $pid = (int) ($params['pid'] ?? ($_REQUEST['id'] ?? 0));
    if ($pid <= 0) {
        return vpnhoodpartner_cycleAlert('info', 'Compatibility check: product id not available on this page yet.');
    }

    $savedRef = (string) Capsule::table('tblproducts')->where('id', $pid)->value('configoption1');
    if ($savedRef === '') {
        return vpnhoodpartner_cycleAlert('info', 'Compatibility check: pick an upstream product and click <b>Save Changes</b> to validate.');
    }
    if (!isset($cyclesByRef[$savedRef])) {
        return vpnhoodpartner_cycleAlert('info', 'Compatibility check: the saved upstream product is no longer offered — re-select one.');
    }

    // Payment Type must match the upstream product before cycles are even comparable.
    // An upstream type the Hub did not report (a build predating the paymentType field)
    // must never be mistaken for a real value — say so, then fall back to the cycle check.
    $prefix = vpnhoodpartner_quantityNotice($pid, $allowQtyByRef[$savedRef] ?? null);
    $localType = vpnhoodpartner_productPaymentType($pid);
    $upstreamType = $payTypeByRef[$savedRef] ?? null;
    if ($upstreamType === null) {
        $prefix .= vpnhoodpartner_cycleAlert(
            'info',
            'Payment Type check skipped: the Hub does not report it. Update the <b>VpnHood! Partner Hub</b>'
            . ' addon on the provider WHMCS to enable this check.'
        );
    } else {
        $localLabel = vpnhoodpartner_payTypeLabel($localType);
        $upstreamLabel = vpnhoodpartner_payTypeLabel($upstreamType);
        if ($localType !== $upstreamType) {
            return $prefix . vpnhoodpartner_cycleAlert(
                'danger',
                'This product\'s <b>Payment Type</b> is <b>' . htmlspecialchars($localLabel)
                . '</b>, but the upstream product is <b>' . htmlspecialchars($upstreamLabel)
                . '</b>. <b>Orders Will be Rejected.</b> Set the Payment Type on the <b>Pricing</b> tab to <b>'
                . htmlspecialchars($upstreamLabel) . '</b> to match.'
            );
        }
        // For non-recurring products there are no billing cycles to compare.
        if ($localType !== 'recurring') {
            return $prefix . vpnhoodpartner_cycleAlert(
                'success',
                'Payment Type matches the upstream product (<b>' . htmlspecialchars($upstreamLabel)
                . '</b>). No billing cycles to compare.'
            );
        }
    }

    $available = $cyclesByRef[$savedRef];
    $enabled = vpnhoodpartner_productEnabledCycleMonths($pid);
    if (!$enabled) {
        return $prefix . vpnhoodpartner_cycleAlert('info', 'Cycle check: no billing cycle is enabled on the <b>Pricing</b> tab yet.');
    }

    $unsupported = array_values(array_diff($enabled, $available));
    $okLabels = array_map('vpnhoodpartner_cycleLabel', $available);
    if (!$unsupported) {
        return $prefix . vpnhoodpartner_cycleAlert(
            'success',
            'Billing cycles match the upstream product (offers <b>' . htmlspecialchars(implode(', ', $okLabels)) . '</b>).'
        );
    }

    $badLabels = array_map('vpnhoodpartner_cycleLabel', $unsupported);
    return $prefix . vpnhoodpartner_cycleAlert(
        'warning',
        'This product has billing cycle(s) <b>' . htmlspecialchars(implode(', ', $badLabels))
        . '</b> enabled that the upstream product does not offer (it offers <b>'
        . htmlspecialchars(implode(', ', $okLabels)) . '</b>). Orders on the unsupported cycle(s) will be'
        . ' rejected. Align the <b>Pricing</b> tab with the upstream cycles.'
    );
}

/**
 * Provision: place the order upstream and store the delivered key.
 */
function vpnhoodpartner_CreateAccount(array $params): string
{
    try {
        // "Allow Multiple Quantities" creates one WHMCS service with quantity N (the
        // customer pays N× the price), but this connector stores exactly one upstream
        // service id + access code per service — provisioning would deliver 1 key for N
        // paid units. Reject loudly instead; the config-time notice warns about this.
        $qty = max(1, (int) ($params['model']->qty ?? 1));
        if ($qty > 1) {
            throw new Exception(
                'This service was ordered with quantity ' . $qty . ', but the connector delivers exactly'
                . ' one access key per service. Disable "Allow Multiple Quantities" on the product\'s'
                . ' Pricing tab.'
            );
        }

        $hub = HubClient::fromConfig();

        $data = $hub->call('order', [
            'downstreamRef'     => (string) $params['configoption1'],
            // The cycle the customer chose; the Hub validates it against the upstream
            // product and rejects an unsupported cycle (purchase-time enforcement).
            'billingCycle'      => (string) ($params['model']->billingcycle ?? ''),
            'quantity'          => 1,
            'customerReference' => (string) $params['serviceid'],
        ]);

        if (empty($data['keys'][0])) {
            throw new Exception('Upstream order returned no key.');
        }
        $key = $data['keys'][0];

        // Persist only what later steps need: the upstream service id (required by
        // every lifecycle relay) and the delivered access code (client-area display).
        $params['model']->serviceProperties->save([
            'upstreamServiceId' => $key['upstreamServiceId'] ?? '',
            'accessCode'        => $key['accessCode'] ?? '',
        ]);

        return 'success';
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'VpnHood Partner Error: ' . $e->getMessage();
    }
}

function vpnhoodpartner_Renew(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'renew', [
        'nextDueDate' => $params['model']['nextduedate'] ?? null,
    ]);
}

function vpnhoodpartner_SuspendAccount(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'suspend');
}

function vpnhoodpartner_UnsuspendAccount(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'unsuspend');
}

function vpnhoodpartner_TerminateAccount(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'terminate');
}

/**
 * Shared lifecycle relay to the upstream Hub.
 */
function vpnhoodpartner_relayLifecycle(array $params, string $action, array $extra = []): string
{
    try {
        $upstreamServiceId = $params['model']->serviceProperties->get('upstreamServiceId');
        if (!$upstreamServiceId) {
            throw new Exception('Missing upstream service id; was the order provisioned?');
        }

        $hub = HubClient::fromConfig();
        $hub->call($action, array_merge(['upstreamServiceId' => $upstreamServiceId], array_filter($extra)));

        return 'success';
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'VpnHood Partner Error: ' . $e->getMessage();
    }
}

/**
 * Client area: show the delivered access code to the partner's own customer.
 * The code was fetched and stored at provisioning time, so no upstream round-trip
 * is needed here.
 */
function vpnhoodpartner_ClientArea(array $params): array
{
    return [
        'templatefile'      => 'clientarea',
        'templateVariables' => [
            'accessCode' => (string) $params['model']->serviceProperties->get('accessCode'),
        ],
    ];
}
