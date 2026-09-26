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
 * VpnHood! Partner Connector Configuration (Hub URL, API key, API secret). The
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
use WHMCS\Module\Server\VpnHoodPartner\HubApiException;
use WHMCS\Module\Server\VpnHoodPartner\HubClient;

function vpnhoodpartner_MetaData(): array
{
    return [
        'DisplayName'   => 'VpnHood! Partner Connector',
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
                'FriendlyName' => 'VpnHood! Partner Connector',
                'Type'         => 'none',
                'Description'  => "<div class='alert alert-danger' style='margin-bottom:0;'>Could not load upstream"
                    . ' products: ' . htmlspecialchars($e->getMessage())
                    . '. Check <b>System Settings → Addon Modules → VpnHood! Partner Connector Configuration</b>.</div>',
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
 *
 * Every Create of a service sends the same idempotency key, so pressing Create again — after a
 * timeout, or concurrently — returns the order the Hub already placed instead of buying a second
 * one. That holds only against a Hub that advertises idempotency-v1; an older Hub ignores the
 * key and buys again, and the error messages say which of the two this is.
 */
function vpnhoodpartner_CreateAccount(array $params): string
{
    $hub = null;
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
        $serviceId = (int) $params['serviceid'];

        $request = [
            'downstreamRef'     => (string) $params['configoption1'],
            // The cycle the customer chose; the Hub validates it against the upstream
            // product and rejects an unsupported cycle (purchase-time enforcement).
            'billingCycle'      => (string) ($params['model']->billingcycle ?? ''),
            'quantity'          => 1,
            'customerReference' => (string) $serviceId,
            'idempotencyKey'    => vpnhoodpartner_idempotencyKey($params),
        ];

        // Set on the Module tab after the Hub asked to reconcile (see AdminServicesTabFields).
        $linkOrderId = vpnhoodpartner_property($serviceId, 'hubLinkOrderId');
        if ($linkOrderId !== '') {
            $data = $hub->call('linkOrder', $request + ['upstreamOrderId' => (int) $linkOrderId]);
        } else {
            if (vpnhoodpartner_property($serviceId, 'hubConfirmNewPurchase') === 'yes') {
                $request['confirmNewPurchase'] = true;
            }
            $data = $hub->call('order', $request);
        }

        if (empty($data['keys'][0])) {
            throw new Exception('Upstream order returned no key.');
        }
        $key = $data['keys'][0];

        // Persist what later steps need: the upstream ORDER id (required by every lifecycle
        // relay), the delivered access code (client-area display, and the exact-match side of
        // claim-by-code — the IAP module searches this property), and the upstream token id.
        // The token id is never sent anywhere; it is kept because it is the one handle that is
        // unambiguous across both installs, so a support exchange can name a key without
        // trading id numbers that exist on both sides for different records.
        $params['model']->serviceProperties->save([
            'upstreamOrderId' => $key['upstreamOrderId'] ?? '',
            'accessCode'      => $key['accessCode'] ?? '',
            'accessTokenId'   => $key['accessTokenId'] ?? '',
        ]);
        vpnhoodpartner_clearProperties($params, ['hubReconcile', 'hubLinkOrderId', 'hubConfirmNewPurchase']);

        // The FIRST key a client buys becomes their default at purchase time
        // (lifecycle §8) — parity with the hub's vpnhoodstore behaviour.
        $clientHasDefault = Capsule::table('tblhosting as h')
            ->join('tblcustomfieldsvalues as v', 'v.relid', '=', 'h.id')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('h.userid', (int) $params['userid'])
            ->whereIn('h.domainstatus', ['Pending', 'Active', 'Suspended'])
            ->where('f.type', 'product')
            ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = 'isdefaultkey'")
            ->where('v.value', 'yes')
            ->exists();
        if (!$clientHasDefault) {
            $params['model']->serviceProperties->save(['isDefaultKey' => 'yes']);
        }

        return 'success';
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        if ($e instanceof HubApiException) {
            vpnhoodpartner_rememberReconcile($params, $e);
        }
        return 'VpnHood Partner Error: ' . vpnhoodpartner_createErrorMessage($e, $hub);
    }
}

/**
 * This service's idempotency key: created once and saved BEFORE any order is sent, under a
 * lock on the service, so every Create of it — concurrent ones included — sends the same key.
 * If it cannot be saved, nothing is ordered.
 *
 * @throws Exception
 */
function vpnhoodpartner_idempotencyKey(array $params): string
{
    $serviceId = (int) $params['serviceid'];
    return vpnhoodpartner_withServiceLock($serviceId, function () use ($params, $serviceId): string {
        $key = vpnhoodpartner_property($serviceId, 'idempotencyKey');
        if ($key === '') {
            $params['model']->serviceProperties->save(['idempotencyKey' => bin2hex(random_bytes(16))]);
            $key = vpnhoodpartner_property($serviceId, 'idempotencyKey');
            if ($key === '') {
                throw new Exception('Could not save the idempotency key of this service; nothing was ordered.');
            }
        }
        return $key;
    });
}

/**
 * A new key for the next Create: after the Hub reported the old one spent (its order was
 * terminated upstream), or when the admin chose to buy instead of linking.
 */
function vpnhoodpartner_rotateIdempotencyKey(array $params): void
{
    $serviceId = (int) $params['serviceid'];
    vpnhoodpartner_withServiceLock($serviceId, function () use ($params): string {
        $params['model']->serviceProperties->save(['idempotencyKey' => bin2hex(random_bytes(16))]);
        return '';
    });
}

/**
 * Run $work holding a named lock on this service, in this WHMCS's own database.
 *
 * @throws Exception when another Create of the service holds it for 10 s
 */
function vpnhoodpartner_withServiceLock(int $serviceId, callable $work): string
{
    $lock = 'vhpartner-' . md5(Capsule::connection()->getDatabaseName() . "\0service\0" . $serviceId);
    $acquired = Capsule::connection()->selectOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])->acquired;
    if ((int) $acquired !== 1) {
        throw new Exception('Another Create of this service is still running; try again in a moment.');
    }
    try {
        return $work();
    } finally {
        Capsule::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
    }
}

/** Empty the given properties — only those that hold a value, so no field is created just to be blank. */
function vpnhoodpartner_clearProperties(array $params, array $names): void
{
    $clear = [];
    foreach ($names as $name) {
        if (vpnhoodpartner_property((int) $params['serviceid'], $name) !== '') {
            $clear[$name] = '';
        }
    }
    if ($clear !== []) {
        $params['model']->serviceProperties->save($clear);
    }
}

/** A service property, read fresh from the database (WHMCS stores them as product custom fields). */
function vpnhoodpartner_property(int $serviceId, string $name): string
{
    return (string) Capsule::table('tblcustomfieldsvalues as v')
        ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
        ->where('v.relid', $serviceId)
        ->where('f.type', 'product')
        ->whereRaw("LOWER(SUBSTRING_INDEX(f.fieldname, '|', 1)) = ?", [strtolower($name)])
        ->value('v.value');
}

/**
 * Keep what the Module tab needs to resolve a Create the Hub refused on purpose: orders of an
 * older connector that may be this very purchase (reconcile), or an order already terminated
 * upstream (key spent).
 */
function vpnhoodpartner_rememberReconcile(array $params, HubApiException $e): void
{
    $code = $e->getErrorCode();
    if ($code !== 'reconcile' && $code !== 'key_spent') {
        return;
    }
    try {
        $params['model']->serviceProperties->save(['hubReconcile' => json_encode([
            'code'    => $code,
            'at'      => date('Y-m-d H:i'),
            'details' => $e->getDetails(),
        ])]);
    } catch (Throwable $ignored) {
        // The message still names the orders.
    }
}

/**
 * What the admin reads after a failed Create. Whether pressing Create again is safe depends on
 * the Hub: only one that advertises idempotency-v1 returns the first order to a repeat — an
 * older Hub charges again.
 */
function vpnhoodpartner_createErrorMessage(Exception $e, ?HubClient $hub): string
{
    $message = $e->getMessage();
    if (!$e instanceof HubApiException || $hub === null) {
        return $message;
    }
    $code = $e->getErrorCode();
    $orderId = (int) ($e->getDetails()['upstreamOrderId'] ?? 0);
    $order = $orderId > 0 ? "order #{$orderId}" : 'this order';

    if (!$e->hubAnswered() || $e->getHttpStatus() >= 500 || in_array($code, ['in_progress', 'initializing', 'not_delivered'], true)) {
        return $hub->supports(HubClient::FEATURE_IDEMPOTENCY)
            ? $message . ' — The Hub may have completed this order; pressing Create again returns it without charging twice.'
            : $message . ' — The order may have completed upstream. Do not press Create again: check your VpnHood'
                . ' account first — a repeat buys a second key.';
    }
    switch ($code) {
        case 'not_provisioned':
        case 'needs_reconciliation':
            return $message . " — VpnHood support is finishing {$order}; do not press Create, a repeat is refused until then.";
        case 'reconcile':
            return $message . ' — Resolve it on this service\'s Module tab: link the right order, or order a new key.';
        case 'key_spent':
            return $message . ' — To buy a replacement key, tick "Order a new key" on this service\'s Module tab.';
        default:
            return $message;
    }
}

/**
 * Relay a renewal upstream.
 *
 * The Hub no longer accepts a nextDueDate override: upstream renewal settles the
 * outstanding upstream renewal invoice from the partner's credit, and WHMCS derives the
 * new term from that. If the upstream has not generated its renewal invoice yet, the Hub
 * answers 409 and this fails loudly rather than reporting a renewal that did not happen.
 */
function vpnhoodpartner_Renew(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'renew');
}

function vpnhoodpartner_SuspendAccount(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'suspend', [
        'suspendReason' => (string) ($params['suspendreason'] ?? ''),
    ]);
}

function vpnhoodpartner_UnsuspendAccount(array $params): string
{
    return vpnhoodpartner_relayLifecycle($params, 'unsuspend');
}

/**
 * After a terminate, the next Create of this service is a new purchase: it gets a new key, so
 * the Hub does not answer it with the terminated order.
 */
function vpnhoodpartner_TerminateAccount(array $params): string
{
    $result = vpnhoodpartner_relayLifecycle($params, 'terminate');
    if ($result === 'success') {
        try {
            vpnhoodpartner_clearProperties($params, ['idempotencyKey', 'hubReconcile', 'hubLinkOrderId', 'hubConfirmNewPurchase']);
        } catch (Throwable $e) {
            logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        }
    }
    return $result;
}

/** The admin-only Refund button on the service page (docs/DEVELOPMENT.md, "Refund"). */
function vpnhoodpartner_AdminCustomButtonArray(): array
{
    return ['Refund' => 'Refund'];
}

/**
 * Refund this key upstream: inside the Hub's refund window, the Hub ends the key and returns
 * what the order cost to your VpnHood credit; a renewed key is never refundable. The service is
 * then finished here without a second Hub call. If that step fails, pressing Refund again
 * finishes it: the Hub answers a repeated refund without paying twice.
 */
function vpnhoodpartner_Refund(array $params): string
{
    try {
        $upstreamOrderId = vpnhoodpartner_upstreamOrderId($params);
        $data = HubClient::fromConfig()->call('refund', ['upstreamOrderId' => $upstreamOrderId]);
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        // A Hub that predates refunds answers the action as unknown; nothing happened upstream.
        $unsupported = $e instanceof HubApiException && $e->getHttpStatus() === 404 && stripos($e->getMessage(), 'Unknown action') !== false;
        return 'VpnHood Partner Error: ' . ($unsupported
            ? 'your VpnHood Partner Hub does not offer refunds yet; nothing was changed. Ask VpnHood support to refund this order.'
            : $e->getMessage());
    }

    $amount = (string) ($data['amount'] ?? '');
    try {
        if ((string) $params['model']->domainstatus !== 'Terminated') {
            $result = localAPI('UpdateClientProduct', [
                'serviceid'       => (int) $params['serviceid'],
                'status'          => 'Terminated',
                'terminationdate' => date('Y-m-d'),
            ]);
            if (($result['result'] ?? '') !== 'success') {
                throw new Exception((string) ($result['message'] ?? 'UpdateClientProduct failed'));
            }
        }
        vpnhoodpartner_clearProperties($params, ['idempotencyKey', 'hubReconcile', 'hubLinkOrderId', 'hubConfirmNewPurchase']);
    } catch (Throwable $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return "VpnHood Partner Error: VpnHood refunded order #{$upstreamOrderId} ({$amount} returned to your credit), but"
            . ' marking this service Terminated failed: ' . $e->getMessage() . '. Press Refund again to finish.';
    }
    logActivity("VpnHood Partner: order #{$upstreamOrderId} refunded; its key has ended and {$amount} was returned to your"
        . ' VpnHood credit - Service ID: ' . (int) $params['serviceid'], (int) $params['userid']);
    return 'success';
}

/**
 * Shared lifecycle relay to the upstream Hub.
 */
function vpnhoodpartner_relayLifecycle(array $params, string $action, array $extra = []): string
{
    try {
        $upstreamOrderId = vpnhoodpartner_upstreamOrderId($params);

        $hub = HubClient::fromConfig();
        $hub->call($action, array_merge(['upstreamOrderId' => $upstreamOrderId], array_filter($extra)));

        return 'success';
    } catch (Exception $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return 'VpnHood Partner Error: ' . $e->getMessage();
    }
}

/**
 * Read the stored upstream order id, or fail loudly if the service was never provisioned.
 *
 * @throws Exception
 */
function vpnhoodpartner_upstreamOrderId(array $params): string
{
    $upstreamOrderId = (string) $params['model']->serviceProperties->get('upstreamOrderId');
    if ($upstreamOrderId === '') {
        throw new Exception('Missing upstream order id; was the order provisioned?');
    }
    return $upstreamOrderId;
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

/**
 * Admin service page: name the ids VpnHood can act on.
 *
 * Every lifecycle call carries `upstreamOrderId` — VpnHood's ORDER id, returned when the
 * key was provisioned. This WHMCS, like theirs, addresses services by SERVICE id, and the
 * two are unrelated sequences that both hold the quoted number upstream for different
 * customers: quoting the wrong one points support at someone else's key (2026-09-01).
 * So the page shows the order id, and the token id beside it as the handle that cannot
 * collide. Read-only — the upstream order owns both values.
 */
function vpnhoodpartner_AdminServicesTabFields(array $params): array
{
    try {
        $serviceId = (int) $params['serviceid'];
        $upstreamOrderId = vpnhoodpartner_property($serviceId, 'upstreamOrderId');
        $accessTokenId   = vpnhoodpartner_property($serviceId, 'accessTokenId');
    }
    catch (Throwable $e) {
        return [];
    }

    $fields = [];
    if ($upstreamOrderId !== '') {
        $fields['VpnHood order id'] = '#' . htmlspecialchars($upstreamOrderId, ENT_QUOTES, 'UTF-8')
            . ' <em>(quote this to VpnHood — not the service id of this page)</em>';
    }
    if ($accessTokenId !== '') {
        $fields['VpnHood key id'] = htmlspecialchars($accessTokenId, ENT_QUOTES, 'UTF-8');
    }

    $reconcile = vpnhoodpartner_reconcileField($params);
    if ($reconcile !== '') {
        $fields['VpnHood reconcile'] = $reconcile;
    }

    return $fields;
}

/**
 * The reconcile panel, after the Hub refused a Create on purpose: it found orders an older
 * connector placed under this service's reference (possibly this purchase, if their response
 * was lost), or this service's order was terminated upstream. The admin links the right order
 * or asks for a new key here, saves, then presses Create. Offered only while the Hub
 * advertises idempotency-v1: an older Hub has no linkOrder, and would buy on any Create.
 */
function vpnhoodpartner_reconcileField(array $params): string
{
    $serviceId = (int) $params['serviceid'];
    $state = json_decode(vpnhoodpartner_property($serviceId, 'hubReconcile'), true);
    $pendingLink = vpnhoodpartner_property($serviceId, 'hubLinkOrderId');
    $pendingNew = vpnhoodpartner_property($serviceId, 'hubConfirmNewPurchase') === 'yes';
    if (!is_array($state) && $pendingLink === '' && !$pendingNew) {
        return '';
    }
    $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

    try {
        $supported = HubClient::fromConfig()->supports(HubClient::FEATURE_IDEMPOTENCY);
    } catch (Throwable $e) {
        $supported = false;
    }
    if (!$supported) {
        return '<em>The Hub no longer reports idempotency-v1, so linking is unavailable and any Create buys a new key.'
            . ' Check your VpnHood account before pressing Create.</em>';
    }

    $html = '';
    if ($pendingLink !== '') {
        $html .= '<div class="alert alert-info" style="margin-bottom:6px">Pending: link to VpnHood order <b>#' . $esc($pendingLink)
            . '</b>. Press <b>Create</b> to finish — it returns that order\'s key without charging.</div>';
    } elseif ($pendingNew) {
        $html .= '<div class="alert alert-warning" style="margin-bottom:6px">Pending: a <b>new</b> key. Press <b>Create</b> to buy it'
            . ' (charged to your VpnHood credit).</div>';
    }

    if (is_array($state) && ($state['code'] ?? '') === 'reconcile') {
        $rows = '';
        foreach ((array) ($state['details']['candidates'] ?? []) as $c) {
            $status = (string) ($c['status'] ?? '');
            $rows .= '<tr><td>#' . (int) ($c['upstreamOrderId'] ?? 0) . '</td><td>' . $esc($c['product'] ?? '') . '</td><td>'
                . $esc($c['billingCycle'] ?? '') . '</td><td>' . $esc($status)
                . ($status === 'Pending' ? ' <em>(being finished by VpnHood support; cannot be linked yet)</em>' : '')
                . '</td><td>' . $esc($c['placedAt'] ?? '') . '</td></tr>';
        }
        $html .= '<p>VpnHood already has order(s) under this service\'s reference, placed without a key (' . $esc($state['at'] ?? '')
            . '). If one of them is this service\'s key, link it; otherwise order a new key.</p>'
            . '<table class="table table-condensed" style="width:auto"><tr><th>Order</th><th>Product</th><th>Cycle</th>'
            . '<th>Status</th><th>Placed</th></tr>' . $rows . '</table>';
    } elseif (is_array($state) && ($state['code'] ?? '') === 'key_spent') {
        $html .= '<p>This service\'s VpnHood order #' . (int) ($state['details']['upstreamOrderId'] ?? 0) . ' is '
            . $esc($state['details']['status'] ?? 'terminated') . ' upstream (' . $esc($state['at'] ?? '')
            . '). Its key cannot be delivered again.</p>';
    }

    $html .= '<div class="form-inline">'
        . (is_array($state) && ($state['code'] ?? '') === 'reconcile'
            ? '<label style="font-weight:normal;margin-right:12px">Link to VpnHood order # <input type="text" name="vhLinkOrderId" size="8" class="form-control input-sm"></label>'
            : '')
        . '<label style="font-weight:normal"><input type="checkbox" name="vhOrderNewKey" value="1"> Order a new key instead (charged to your credit)</label>'
        . '</div><small class="text-muted">Then click <b>Save Changes</b>, and press <b>Create</b>.</small>';
    return $html;
}

/** Store the admin's reconcile choice from the Module tab; the next Create acts on it. */
function vpnhoodpartner_AdminServicesTabFieldsSave(array $params): void
{
    $link = trim((string) ($_POST['vhLinkOrderId'] ?? ''));
    $newKey = !empty($_POST['vhOrderNewKey']);
    try {
        if ($newKey) {
            // A new purchase must not replay the old key's order: new key, explicit confirmation.
            vpnhoodpartner_rotateIdempotencyKey($params);
            $params['model']->serviceProperties->save(['hubConfirmNewPurchase' => 'yes']);
            vpnhoodpartner_clearProperties($params, ['hubLinkOrderId']);
        } elseif ($link !== '' && ctype_digit($link)) {
            $params['model']->serviceProperties->save(['hubLinkOrderId' => $link]);
            vpnhoodpartner_clearProperties($params, ['hubConfirmNewPurchase']);
        }
    } catch (Throwable $e) {
        logModuleCall('vpnhoodpartner', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
    }
}
