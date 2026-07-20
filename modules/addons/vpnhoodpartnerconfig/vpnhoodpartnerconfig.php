<?php

/**
 * VpnHood! Partner Connector — Configuration (addon)
 *
 * Holds the GLOBAL connection to the provider's VpnHood! Partner Hub in one place:
 * Hub URL, API Key, API Secret. The `vpnhoodpartner` SERVER module reads these
 * settings (via HubClient::fromConfig) instead of a WHMCS "Server", so the partner
 * configures the connection once here rather than setting up a fake server.
 *
 * This mirrors the `vpnhoodconfig` addon pattern used by the VpnHood! MANAGER server
 * module in the VpnHood.WHMCS repo.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function vpnhoodpartnerconfig_config()
{
    return [
        'name'        => 'VpnHood! Partner Connector Configuration',
        'description' => 'Connection to your provider\'s VpnHood! Partner Hub. '
            . 'The VpnHood! Partner Connector server module reads these settings.',
        'version'     => '1.0',
        'author'      => 'VpnHood!',

        'fields' => [

            'HubUrl' => [
                'FriendlyName' => 'Hub URL',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => 'Your provider\'s WHMCS base URL, e.g. https://store.yourprovider.com',
                'Default'      => '',
            ],

            'ApiKey' => [
                'FriendlyName' => 'API Key',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => 'Your partner API Key (sent as X-Vpnhood-Key).',
                'Default'      => '',
            ],

            'ApiSecret' => [
                'FriendlyName' => 'API Secret',
                'Type'         => 'password',
                'Size'         => '60',
                'Description'  => 'Your partner API Secret (sent as X-Vpnhood-Secret).',
                'Default'      => '',
            ],

            'SkipTlsVerify' => [
                'FriendlyName' => 'Skip TLS Verification (DEV ONLY)',
                'Type'         => 'yesno',
                'Description'  => 'Development only: accept self-signed / loopback Hub certificates. '
                    . 'Never enable in production.',
                'Default'      => '',
            ],
        ],
    ];
}

function vpnhoodpartnerconfig_activate()
{
}

function vpnhoodpartnerconfig_deactivate()
{
}

function vpnhoodpartnerconfig_output($vars)
{
    echo '<p>These settings are used by the <strong>VpnHood! Partner Connector</strong> server module to reach'
        . ' your provider\'s VpnHood! Partner Hub. Configure the <strong>Hub URL</strong>, <strong>API Key</strong>'
        . ' and <strong>API Secret</strong> above, then assign the connector module to your products.</p>';
}
