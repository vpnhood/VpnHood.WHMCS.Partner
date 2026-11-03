<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Daily refresh of the VpnHood update check.
 *
 * The check itself lives in modules/widgets/vpnhoodupdates.php, which every
 * VpnHood package ships at that same path; this is only its clock. The daily cron
 * is the one place where waiting a few seconds on github.com costs nobody
 * anything — the dashboard widget then renders what this left behind and never
 * makes a request of its own.
 *
 * Every installed VpnHood addon registers this same hook, on purpose: whichever
 * packages an install has, something winds the clock. The cache TTL makes every
 * call after the first one that day a no-op, so the duplicates cost nothing.
 *
 * Best-effort by design: an install with no outbound access to github.com, or a
 * GitHub outage, must cost nothing but a "check failed" line. The daily cron is
 * never allowed to fail over a version check.
 */
add_hook('DailyCronJob', 1, function () {
    try {
        $check = ROOTDIR . '/modules/widgets/vpnhoodupdates.php';
        if (!is_readable($check)) {
            return;
        }
        require_once $check;
        VpnHoodUpdateCheck::refresh();
    } catch (\Throwable $e) {
        logModuleCall('vpnhood', 'hook.update-check', [], $e->getMessage(), '');
    }
});

/**
 * Clear Ticket IP Addresses (this addon's option, off while unset): while it is on, WHMCS keeps no
 * IP address on tickets. WHMCS records the client's IP on the message that opens a ticket from the
 * client area (`tbltickets.ipaddress`) and shows it under that message, to the client and in the
 * admin area, and keeps one on each ticket rating (`tblticketfeedback.ip`); no setting turns it
 * off. A ticket's IP is cleared as the ticket opens; the daily cron clears what is left: ratings,
 * which no hook announces, and the tickets stored before. No localAPI command writes either column.
 *
 * The hub package has the same option under its own names (VpnHood.WHMCS,
 * includes/hooks/vpnhood-ticket-ip.php): an install running both loads both files, so a shared
 * function name would stop WHMCS with a redeclaration. Each acts on its own setting.
 */
function vpnhoodpartnerconfig_clearsTicketIps(): bool
{
    return \WHMCS\Database\Capsule::table('tbladdonmodules')->where('module', 'vpnhoodpartnerconfig')
        ->where('setting', 'ClearTicketIpAddresses')->value('value') === 'on';
}

/** TicketOpen, TicketOpenAdmin: the new ticket keeps no IP. A failure never fails the ticket; the daily run clears it. */
function vpnhoodpartnerconfig_clearOpenedTicketIp(array $vars): void
{
    $ticketId = (int) ($vars['ticketid'] ?? 0);
    try {
        if ($ticketId > 0 && vpnhoodpartnerconfig_clearsTicketIps()) {
            \WHMCS\Database\Capsule::table('tbltickets')->where('id', $ticketId)->update(['ipaddress' => '']);
        }
    } catch (\Throwable $e) {
        logActivity("VpnHood Partner Connector: the IP address of ticket #$ticketId could not be cleared; the daily "
            . 'cron tries again: ' . $e->getMessage());
    }
}

/** DailyCronJob: no ticket and no rating keeps an IP. */
function vpnhoodpartnerconfig_clearStoredTicketIps(): void
{
    try {
        if (!vpnhoodpartnerconfig_clearsTicketIps()) {
            return;
        }
        \WHMCS\Database\Capsule::table('tbltickets')->where('ipaddress', '!=', '')->update(['ipaddress' => '']);
        \WHMCS\Database\Capsule::table('tblticketfeedback')->where('ip', '!=', '')->update(['ip' => '']);
    } catch (\Throwable $e) {
        logActivity('VpnHood Partner Connector: the IP addresses stored on tickets could not be cleared: ' . $e->getMessage());
    }
}

add_hook('TicketOpen', 1, 'vpnhoodpartnerconfig_clearOpenedTicketIp');
add_hook('TicketOpenAdmin', 1, 'vpnhoodpartnerconfig_clearOpenedTicketIp');
add_hook('DailyCronJob', 1, 'vpnhoodpartnerconfig_clearStoredTicketIps');
