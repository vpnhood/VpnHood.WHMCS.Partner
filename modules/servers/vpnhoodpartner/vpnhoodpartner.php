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
        foreach ($products as $p) {
            $ref = (string) ($p['downstreamRef'] ?? '');
            if ($ref === '') {
                continue;
            }

            $available = array_map('intval', $p['availableCycles'] ?? []);
            if (!$available && isset($p['billingCycleMonths'])) {
                $available = [(int) $p['billingCycleMonths']];
            }
            $cyclesByRef[$ref] = $available;

            $label = (string) ($p['name'] ?? $ref);
            $cycleLabels = array_map('vpnhoodpartner_cycleLabel', $available);
            if ($cycleLabels) {
                $label .= ' — ' . implode(', ', $cycleLabels);
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
        $field['Description'] .= vpnhoodpartner_cycleNotice($params, $cyclesByRef);

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

/**
 * Billing cycle lengths (in months) enabled on a WHMCS product's Pricing tab.
 * A cycle is enabled when its tblpricing column is >= 0 (WHMCS uses -1 for disabled).
 */
function vpnhoodpartner_productEnabledCycleMonths(int $productId): array
{
    $row = \WHMCS\Database\Capsule::table('tblpricing')
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
 * Config-time billing-cycle check. Unlike a plain warning, this reports its state in EVERY
 * case so a missing banner is never ambiguous: it tells the admin whether the product id was
 * found, whether a selection has been saved, and whether the cycles match or mismatch.
 */
function vpnhoodpartner_cycleNotice(array $params, array $cyclesByRef): string
{
    // WHMCS does not reliably pass 'pid' into _ConfigOptions on the product edit page;
    // the product id is in the request there (configproducts.php?action=edit&id=X).
    $pid = (int) ($params['pid'] ?? ($_REQUEST['id'] ?? 0));
    if ($pid <= 0) {
        return vpnhoodpartner_cycleAlert('info', 'Cycle check: product id not available on this page yet.');
    }

    $savedRef = (string) \WHMCS\Database\Capsule::table('tblproducts')->where('id', $pid)->value('configoption1');
    if ($savedRef === '') {
        return vpnhoodpartner_cycleAlert('info', 'Cycle check: pick an upstream product and click <b>Save Changes</b> to validate.');
    }
    if (!isset($cyclesByRef[$savedRef])) {
        return vpnhoodpartner_cycleAlert('info', 'Cycle check: the saved upstream product is no longer offered — re-select one.');
    }

    $available = $cyclesByRef[$savedRef];
    $enabled = vpnhoodpartner_productEnabledCycleMonths($pid);
    if (!$enabled) {
        return vpnhoodpartner_cycleAlert('info', 'Cycle check: no billing cycle is enabled on the <b>Pricing</b> tab yet.');
    }

    $unsupported = array_values(array_diff($enabled, $available));
    $okLabels = array_map('vpnhoodpartner_cycleLabel', $available);
    if (!$unsupported) {
        return vpnhoodpartner_cycleAlert(
            'success',
            'Billing cycles match the upstream product (offers <b>' . htmlspecialchars(implode(', ', $okLabels)) . '</b>).'
        );
    }

    $badLabels = array_map('vpnhoodpartner_cycleLabel', $unsupported);
    return vpnhoodpartner_cycleAlert(
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
