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
 * Configure the connection under WHMCS → System Settings → Products/Services →
 * Servers (hostname = upstream WHMCS URL, username = API key, password = secret).
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
        'RequiresServer' => true, // The "server" is the upstream VpnHood Partner Hub (WHMCS).
    ];
}

/**
 * Product configuration: which upstream product this product maps to.
 */
function vpnhoodpartner_ConfigOptions(): array
{
    return [
        'downstreamRef' => [
            'FriendlyName' => 'Upstream Product Reference',
            'Type'         => 'text',
            'Size'         => '30',
            'Description'  => 'The product reference your provider mapped to your account (their "Downstream Ref"). Example: vpn-monthly',
            'Default'      => '',
        ],
    ];
}

/**
 * Provision: place the order upstream and store the delivered key.
 */
function vpnhoodpartner_CreateAccount(array $params): string
{
    try {
        $hub = HubClient::fromParams($params);

        $data = $hub->call('order', [
            'downstreamRef'     => $params['configoption1'],
            'quantity'          => 1,
            'customerReference' => (string) $params['serviceid'],
        ]);

        if (empty($data['keys'][0])) {
            throw new Exception('Upstream order returned no key.');
        }
        $key = $data['keys'][0];

        // Persist the upstream service id (for lifecycle relays) and the delivered
        // key (for client-area display) on this service.
        $params['model']->serviceProperties->save([
            'upstreamServiceId' => $key['upstreamServiceId'] ?? '',
            'deliveryType'      => $key['deliveryType'] ?? 'normal',
            'accessCode'        => $key['accessCode'] ?? '',
            'csv'               => $key['csv'] ?? '',
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

        $hub = HubClient::fromParams($params);
        $hub->call($action, array_merge(['upstreamServiceId' => $upstreamServiceId], array_filter($extra)));

        return 'success';
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'VpnHood Partner Error: ' . $e->getMessage();
    }
}

/**
 * Client area: show the delivered access code (or CSV download) to the
 * partner's own customer. The key was fetched and stored at provisioning time,
 * so no upstream round-trip is needed here.
 */
function vpnhoodpartner_ClientArea(array $params): array
{
    $deliveryType = $params['model']->serviceProperties->get('deliveryType') ?: 'normal';

    // CSV download is served inline when requested via AJAX.
    if ($deliveryType === 'csv'
        && isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest'
    ) {
        $csv = (string) $params['model']->serviceProperties->get('csv');
        while (ob_get_level()) {
            ob_end_clean();
        }
        header_remove('Content-Type');
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="access_codes_' . (int) $params['serviceid'] . '.csv"');
        header('Access-Control-Expose-Headers: Content-Disposition');
        header('Content-Length: ' . strlen($csv));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        echo $csv;
        exit;
    }

    if ($deliveryType === 'csv') {
        return ['templatefile' => 'clientarea-csv'];
    }

    return [
        'templatefile'      => 'clientarea',
        'templateVariables' => [
            'accessCode' => (string) $params['model']->serviceProperties->get('accessCode'),
        ],
    ];
}
