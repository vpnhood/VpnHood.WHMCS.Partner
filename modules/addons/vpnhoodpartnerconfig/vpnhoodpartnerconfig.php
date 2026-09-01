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
 *
 * The addon page (Addons → VpnHood Partner Connector Configuration) adds a
 * **product sync**: it lists every product the provider mapped to this partner
 * (Hub `getProducts`) and creates a local WHMCS product for the ones that do not
 * exist yet, already wired to the connector module. See vpnhoodpartnerconfig_output().
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;
use WHMCS\Module\Server\VpnHoodPartner\HubClient;

/** The server module this addon configures — and the module synced products are assigned to. */
const VPNHOODPARTNERCONFIG_SERVER_MODULE = 'vpnhoodpartner';

/** Recurring billing cycle length (months) → its column in tblpricing. */
const VPNHOODPARTNERCONFIG_CYCLE_COLUMNS = [
    1  => 'monthly',
    3  => 'quarterly',
    6  => 'semiannually',
    12 => 'annually',
    24 => 'biennially',
    36 => 'triennially',
];

function vpnhoodpartnerconfig_config()
{
    return [
        'name'        => 'VpnHood Partner Connector Configuration',
        'description' => 'Connection to your provider\'s VpnHood! Partner Hub. '
            . 'The VpnHood Partner Connector server module reads these settings.',
        'version'     => '1.2.0',
        'author'      => 'VpnHood!',

        'fields' => [

            'HubUrl' => [
                'FriendlyName' => 'Hub URL',
                'Type'         => 'text',
                'Size'         => '60',
                'Description'  => 'Your provider\'s WHMCS base URL.',
                // Pre-filled so the common case needs no typing. WHMCS applies a Default
                // when the setting does not exist yet, i.e. on a fresh activation; it never
                // overwrites a value a partner has already saved. HubClient trims any
                // trailing slash, so the display form here is safe as-is.
                'Default'      => 'https://account.vpnhood.com/',
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

/**
 * WHMCS upgrade routine — the migration point for a new release.
 *
 * WHMCS records this addon's version in `tbladdonmodules` and calls this function
 * when the version in _config() is NEWER (PHP version_compare) than the recorded
 * one — i.e. once, after a partner extracts a release zip over their install. It is
 * the only hook that runs on an upgrade, so anything a release must fix up belongs
 * here. Settings and the partner's products/services are untouched by an upgrade.
 *
 * Two rules, because a partner can jump several versions at once and WHMCS offers
 * no rollback:
 *   1. Guard every step with version_compare against $from. Never assume the
 *      install is coming from the immediately previous release.
 *   2. Make every step idempotent — if an upgrade dies half way, the next admin
 *      page load re-runs it from the same $from.
 *
 * Note that extracting the zip only ever ADDS and OVERWRITES files: a module file
 * dropped in a release stays on disk until something deletes it. When a release
 * removes a file, unlink it here (guarded as above) or partners keep dead code.
 *
 * @param array $vars 'version' — the version currently recorded for this install
 */
function vpnhoodpartnerconfig_upgrade($vars)
{
    $from = isset($vars['version']) ? (string) $vars['version'] : '0.0.0';

    // if (version_compare($from, '1.1.0', '<')) { ... }

    unset($from); // nothing to migrate yet — 1.0.0 is the first public release
}

// ---------------------------------------------------------------- CSRF helpers

/**
 * Per-admin-session CSRF token for this addon's state-changing POST form.
 * Stored in the WHMCS admin session; compared in constant time on POST.
 */
function vpnhoodpartnerconfig_csrfToken(): string
{
    if (empty($_SESSION['vpnhoodpartnerconfig_csrf'])) {
        $_SESSION['vpnhoodpartnerconfig_csrf'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['vpnhoodpartnerconfig_csrf'];
}

/** Hidden token field to embed in every POST form. */
function vpnhoodpartnerconfig_csrfField(): string
{
    return '<input type="hidden" name="token" value="'
        . htmlspecialchars(vpnhoodpartnerconfig_csrfToken()) . '">';
}

/**
 * Reject a POST whose CSRF token is missing or does not match the session token.
 *
 * @throws RuntimeException
 */
function vpnhoodpartnerconfig_assertCsrf(): void
{
    $token = $_POST['token'] ?? '';
    $session = (string) ($_SESSION['vpnhoodpartnerconfig_csrf'] ?? '');
    if (!is_string($token) || $token === '' || $session === '' || !hash_equals($session, $token)) {
        throw new RuntimeException('Invalid or expired security token. Please reload the page and try again.');
    }
}

// ------------------------------------------------------------------ Hub access

/**
 * Load the connector's Hub client. It ships in the SERVER module, which WHMCS only
 * autoloads while provisioning — so the addon requires it explicitly.
 *
 * @throws RuntimeException when the server module is not installed alongside this addon.
 */
function vpnhoodpartnerconfig_loadHubClient(): void
{
    if (class_exists(HubClient::class)) {
        return;
    }
    $path = __DIR__ . '/../../servers/' . VPNHOODPARTNERCONFIG_SERVER_MODULE . '/lib/HubClient.php';
    if (!is_file($path)) {
        throw new RuntimeException(
            'The VpnHood Partner Connector server module is missing. Upload'
            . ' modules/servers/' . VPNHOODPARTNERCONFIG_SERVER_MODULE . '/ alongside this addon.'
        );
    }
    require_once $path;
}

// ------------------------------------------------------- local product lookups

/**
 * Every local product already wired to the connector, keyed by its upstream ref
 * (`configoption1`). This is the same key `_CreateAccount` sends to the Hub, so it
 * is exactly what decides whether a Hub product "exists here" yet.
 *
 * @return array<string, object> ref => tblproducts row
 */
function vpnhoodpartnerconfig_localProductsByRef(): array
{
    $rows = Capsule::table('tblproducts')
        ->where('servertype', VPNHOODPARTNERCONFIG_SERVER_MODULE)
        ->get(['id', 'gid', 'name', 'paytype', 'hidden', 'configoption1']);

    $byRef = [];
    foreach ($rows as $row) {
        $ref = trim((string) $row->configoption1);
        if ($ref !== '') {
            $byRef[$ref] = $row;
        }
    }
    return $byRef;
}

/** Product groups to offer as the destination for created products. */
function vpnhoodpartnerconfig_productGroups(): array
{
    return Capsule::table('tblproductgroups')->orderBy('name')->get(['id', 'name'])->all();
}

/**
 * True when every billing cycle enabled on a product still costs 0 — i.e. the
 * product was created by sync and nobody has set a selling price yet. Such a
 * product must not be un-hidden, so the page flags it.
 */
function vpnhoodpartnerconfig_needsPricing(int $productId): bool
{
    $row = Capsule::table('tblpricing')
        ->where('type', 'product')->where('relid', $productId)
        ->orderBy('currency')->first();
    if (!$row) {
        return true;
    }
    foreach (VPNHOODPARTNERCONFIG_CYCLE_COLUMNS as $column) {
        // WHMCS uses -1 for "cycle disabled"; only enabled cycles count.
        if (isset($row->$column) && (float) $row->$column > 0) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- product sync

/** Normalize a WHMCS "Payment Type" to one of free|onetime|recurring. */
function vpnhoodpartnerconfig_normalizePayType(string $type): string
{
    $type = strtolower(trim($type));
    return in_array($type, ['free', 'onetime', 'recurring'], true) ? $type : 'recurring';
}

/** Human label for a WHMCS "Payment Type". */
function vpnhoodpartnerconfig_payTypeLabel(string $type): string
{
    $labels = ['free' => 'Free', 'onetime' => 'One Time', 'recurring' => 'Recurring'];
    return $labels[vpnhoodpartnerconfig_normalizePayType($type)];
}

/**
 * Display name for an upstream product. The Hub reports `null` for a mapping whose
 * upstream product was deleted, so fall back to the ref — and use this everywhere,
 * so the product that gets created is named exactly what the page listed.
 */
function vpnhoodpartnerconfig_upstreamName(array $product, string $ref): string
{
    return trim((string) ($product['name'] ?? '')) ?: $ref;
}

/** Human label for a billing cycle length in months. */
function vpnhoodpartnerconfig_cycleLabel(int $months): string
{
    $labels = [
        1 => 'Monthly', 3 => 'Quarterly', 6 => 'Semi-Annually',
        12 => 'Annually', 24 => 'Biennially', 36 => 'Triennially',
    ];
    return $labels[$months] ?? ($months . ' mo');
}

/**
 * Build the AddProduct `pricing` payload for an upstream product.
 *
 * Prices are all 0: the Hub reports what a product IS (name, payment type, cycles),
 * never what the partner should charge for it — that is the partner's own retail
 * decision. What matters here is WHICH cycles exist: WHMCS treats a tblpricing
 * column of -1 as "cycle disabled", and passing 0 is what ENABLES a cycle (verified
 * on WHMCS 9.0.3). So this enables exactly the upstream's cycles at 0.00 and the
 * product is created hidden until the admin prices it.
 *
 * One-time and free products have no cycles at all — WHMCS keeps their single price
 * in the `monthly` column.
 */
function vpnhoodpartnerconfig_pricingPayload(array $upstream): array
{
    $payType = vpnhoodpartnerconfig_normalizePayType((string) ($upstream['paymentType'] ?? ''));

    $cycles = ['monthly' => 0];
    if ($payType === 'recurring') {
        $months = array_map('intval', $upstream['availableCycles'] ?? []);
        if (!$months && isset($upstream['billingCycleMonths'])) {
            $months = [(int) $upstream['billingCycleMonths']];
        }
        $cycles = [];
        foreach ($months as $m) {
            if (isset(VPNHOODPARTNERCONFIG_CYCLE_COLUMNS[$m])) {
                $cycles[VPNHOODPARTNERCONFIG_CYCLE_COLUMNS[$m]] = 0;
            }
        }
        // A recurring product the Hub reports with no usable cycle would otherwise be
        // created with every cycle disabled and could never be ordered.
        if (!$cycles) {
            $cycles = ['monthly' => 0];
        }
    }

    // AddProduct only writes the currencies it is given; without this a multi-currency
    // WHMCS gets a product priced in currency 1 only.
    $pricing = [];
    foreach (Capsule::table('tblcurrencies')->pluck('id') as $currencyId) {
        $pricing[(int) $currencyId] = $cycles;
    }
    return $pricing ?: [1 => $cycles];
}

/**
 * Create one local product for an upstream Hub product, already wired to the connector.
 *
 * Deliberately NOT copied from upstream:
 *  - price (see vpnhoodpartnerconfig_pricingPayload) — created at 0.00 and hidden.
 *  - "Allow Multiple Quantities" — the connector stores one upstream order id +
 *    access code per service and rejects quantity > 1, so mirroring the upstream's
 *    setting would produce a product whose orders always fail. (AddProduct ignores
 *    `allowqty` anyway on WHMCS 9.0.3.)
 *
 * @return int the new product id
 * @throws RuntimeException
 */
function vpnhoodpartnerconfig_createProduct(array $upstream, int $groupId): int
{
    $ref = (string) $upstream['downstreamRef'];
    $name = vpnhoodpartnerconfig_upstreamName($upstream, $ref);

    $result = localAPI('AddProduct', [
        'type'          => 'other',
        'gid'           => $groupId,
        'name'          => $name,
        'paytype'       => vpnhoodpartnerconfig_normalizePayType((string) ($upstream['paymentType'] ?? '')),
        // Hidden on purpose: the product has no selling price yet.
        'hidden'        => true,
        // Provision as soon as the customer's invoice is paid.
        'autosetup'     => 'payment',
        'module'        => VPNHOODPARTNERCONFIG_SERVER_MODULE,
        'configoption1' => $ref,
        'pricing'       => vpnhoodpartnerconfig_pricingPayload($upstream),
    ]);

    if (($result['result'] ?? '') !== 'success' || empty($result['pid'])) {
        throw new RuntimeException(
            'WHMCS refused to create "' . $name . '": ' . ($result['message'] ?? 'unknown error')
        );
    }
    return (int) $result['pid'];
}

/**
 * Create the selected upstream products that have no local product yet.
 *
 * Create-only by design: it never edits or deletes an existing product (WHMCS 9 has
 * no DeleteProduct API either), so re-running it is safe and can only ever add.
 *
 * @param array<int, array> $upstreamProducts products from the Hub, keyed by ref
 * @param string[]          $selectedRefs     refs the admin ticked
 * @return array{created: array<int, string>, skipped: int, errors: string[]}
 */
function vpnhoodpartnerconfig_syncProducts(array $upstreamProducts, array $selectedRefs, int $groupId): array
{
    if ($groupId <= 0) {
        throw new RuntimeException('Please choose the product group to create the products in.');
    }
    if (!Capsule::table('tblproductgroups')->where('id', $groupId)->exists()) {
        throw new RuntimeException('That product group no longer exists.');
    }

    $existing = vpnhoodpartnerconfig_localProductsByRef();
    $created = [];
    $skipped = 0;
    $errors = [];

    foreach ($selectedRefs as $ref) {
        $ref = (string) $ref;
        if (!isset($upstreamProducts[$ref])) {
            // Not offered to this partner — never create a product for a ref the Hub
            // did not just report, whatever the form posted.
            $errors[] = 'Unknown upstream product "' . $ref . '" — reload the page and retry.';
            continue;
        }
        if (isset($existing[$ref])) {
            $skipped++;
            continue;
        }
        try {
            $pid = vpnhoodpartnerconfig_createProduct($upstreamProducts[$ref], $groupId);
            $created[$pid] = vpnhoodpartnerconfig_upstreamName($upstreamProducts[$ref], $ref);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors];
}

// ------------------------------------------------------------------ admin page

/**
 * Admin area output: connection summary + upstream product sync.
 */
function vpnhoodpartnerconfig_output($vars)
{
    $modulelink = $vars['modulelink'] ?? 'addonmodules.php?module=vpnhoodpartnerconfig';
    $notices = [];

    // Load the upstream catalogue first: it is both the page's content and the
    // authority for what a POST is allowed to create.
    $upstreamProducts = [];
    $balanceLine = '';
    $hubError = '';
    try {
        vpnhoodpartnerconfig_loadHubClient();
        $hub = HubClient::fromConfig();
        foreach ($hub->call('getProducts')['products'] ?? [] as $product) {
            $ref = trim((string) ($product['downstreamRef'] ?? ''));
            if ($ref !== '') {
                $upstreamProducts[$ref] = $product;
            }
        }
        try {
            $balance = $hub->call('getBalance');
            $balanceLine = 'Credit balance: <b>' . htmlspecialchars(
                number_format((float) ($balance['balance'] ?? 0), 2) . ' ' . ($balance['currency'] ?? '')
            ) . '</b>';
        } catch (Throwable $e) {
            // A catalogue without a balance is still a usable page.
            $balanceLine = '';
        }
    } catch (Throwable $e) {
        $hubError = $e->getMessage();
    }

    // -- Handle the sync POST ------------------------------------------------
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'sync') {
        try {
            vpnhoodpartnerconfig_assertCsrf();
            if ($hubError !== '') {
                throw new RuntimeException($hubError);
            }
            $refs = array_map('strval', array_filter((array) ($_POST['refs'] ?? []), 'is_scalar'));
            if (!$refs) {
                throw new RuntimeException('No products were selected.');
            }
            $result = vpnhoodpartnerconfig_syncProducts($upstreamProducts, $refs, (int) ($_POST['gid'] ?? 0));

            if ($result['created']) {
                $links = [];
                foreach ($result['created'] as $pid => $name) {
                    $links[] = '<a href="configproducts.php?action=edit&id=' . (int) $pid . '">'
                        . htmlspecialchars($name) . '</a>';
                }
                $notices[] = ['success', 'Created ' . count($result['created']) . ' product(s): '
                    . implode(', ', $links)
                    . '. They are <b>hidden and priced 0.00</b> — set your selling price on each'
                    . ' product\'s <b>Pricing</b> tab, then un-hide it.'];
            }
            if ($result['skipped'] > 0) {
                $notices[] = ['info', $result['skipped'] . ' product(s) already existed and were left untouched.'];
            }
            if ($result['errors']) {
                $notices[] = ['danger', 'Some products could not be created:<br>'
                    . htmlspecialchars(implode("\n", $result['errors']))];
            }
        } catch (Throwable $e) {
            $notices[] = ['danger', 'Sync failed: ' . htmlspecialchars($e->getMessage())];
        }
    }

    foreach ($notices as [$type, $html]) {
        echo '<div class="alert alert-' . $type . '">' . nl2br($html) . '</div>';
    }

    echo '<p>These settings are used by the <strong>VpnHood Partner Connector</strong> server module to reach'
        . ' your provider\'s VpnHood! Partner Hub. Configure the <strong>Hub URL</strong>, <strong>API Key</strong>'
        . ' and <strong>API Secret</strong> under <b>System Settings → Addon Modules</b>, then use the sync'
        . ' below to create the matching products.</p>';

    // Before the Hub-error early return: the version is exactly what you want to
    // quote to support when the Hub is unreachable.
    echo vpnhoodpartnerconfig_versionLine();

    if ($hubError !== '') {
        echo '<div class="alert alert-danger">Could not reach the VpnHood! Partner Hub: '
            . htmlspecialchars($hubError) . '</div>';
        return;
    }

    if ($balanceLine !== '') {
        echo '<p class="text-muted">' . $balanceLine . '</p>';
    }

    vpnhoodpartnerconfig_renderSyncForm($modulelink, $upstreamProducts);
}

/**
 * Installed versions of both halves of the connector.
 *
 * WHMCS shows a version for addon modules on its own (from _config), but has no
 * equivalent display for provisioning modules — so the connector's own version
 * would otherwise appear nowhere in the admin UI. Read it back from its
 * whmcs.json, which scripts/set-version.sh keeps in step with the repo tag.
 */
function vpnhoodpartnerconfig_versionLine(): string
{
    $config    = vpnhoodpartnerconfig_config();
    $addon     = (string) ($config['version'] ?? '');
    $connector = '';

    $manifest = dirname(__DIR__, 2) . '/servers/vpnhoodpartner/whmcs.json';
    if (is_readable($manifest)) {
        // These manifests are sometimes written with a UTF-8 BOM.
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', (string) file_get_contents($manifest));
        $json = json_decode($raw, true);
        if (is_array($json) && isset($json['version'])) {
            $connector = (string) $json['version'];
        }
    }

    $parts = [];
    if ($connector !== '') {
        $parts[] = 'connector module <code>' . htmlspecialchars($connector, ENT_QUOTES) . '</code>';
    }
    $parts[] = 'this addon <code>'
        . htmlspecialchars($addon !== '' ? $addon : 'unversioned', ENT_QUOTES) . '</code>';

    $line = '<p class="text-muted">Installed versions: ' . implode(' &middot; ', $parts) . '</p>';

    // Both halves ship in one zip carrying one version, and are only ever tested
    // together. Different numbers mean the last release was extracted over only part
    // of the install — worth saying out loud, since nothing else would report it.
    if ($connector !== '' && $addon !== '' && $connector !== $addon) {
        $line .= '<div class="alert alert-warning">The connector module (<code>'
            . htmlspecialchars($connector, ENT_QUOTES) . '</code>) and this addon (<code>'
            . htmlspecialchars($addon, ENT_QUOTES) . '</code>) are at different versions. '
            . 'They ship together in a single zip — re-extract the latest release at the root '
            . 'of your WHMCS installation so both halves are updated.</div>';
    }

    return $line;
}

/**
 * Render the upstream catalogue with a per-product "exists here?" status and a
 * one-click create for the missing ones.
 */
function vpnhoodpartnerconfig_renderSyncForm(string $modulelink, array $upstreamProducts): void
{
    echo '<h3>Upstream Products</h3>';

    if (!$upstreamProducts) {
        echo '<div class="alert alert-info">Your provider has not mapped any products to your account yet.</div>';
        return;
    }

    $existing = vpnhoodpartnerconfig_localProductsByRef();
    $missing = array_diff_key($upstreamProducts, $existing);

    echo '<form method="post" action="' . htmlspecialchars($modulelink) . '">';
    echo vpnhoodpartnerconfig_csrfField();
    echo '<input type="hidden" name="do" value="sync">';

    echo '<table class="table table-striped"><thead><tr>'
        . '<th style="width:2em"></th><th>Upstream Product</th><th>Payment Type</th>'
        . '<th>Billing Cycles</th><th>In Your WHMCS</th></tr></thead><tbody>';

    foreach ($upstreamProducts as $ref => $product) {
        $payType = vpnhoodpartnerconfig_normalizePayType((string) ($product['paymentType'] ?? ''));

        // Cycles only exist for recurring products; a one-time price lives in the
        // "monthly" column and must not render as a phantom Monthly cycle.
        $cyclesText = '—';
        if ($payType === 'recurring') {
            $months = array_map('intval', $product['availableCycles'] ?? []);
            if (!$months && isset($product['billingCycleMonths'])) {
                $months = [(int) $product['billingCycleMonths']];
            }
            $labels = array_map('vpnhoodpartnerconfig_cycleLabel', $months);
            $cyclesText = $labels ? implode(', ', $labels) : '—';
        }

        if (isset($existing[$ref])) {
            $local = $existing[$ref];
            $status = '<a href="configproducts.php?action=edit&id=' . (int) $local->id . '">#'
                . (int) $local->id . ' ' . htmlspecialchars($local->name) . '</a>';
            if (vpnhoodpartnerconfig_normalizePayType((string) $local->paytype) !== $payType) {
                $status .= ' <span class="label label-danger">Payment Type mismatch</span>';
            }
            if (vpnhoodpartnerconfig_needsPricing((int) $local->id)) {
                $status .= ' <span class="label label-warning">Needs pricing</span>';
            }
            if ((int) $local->hidden === 1) {
                $status .= ' <span class="label label-default">Hidden</span>';
            }
            $checkbox = '';
        } else {
            $status = '<span class="label label-info">Not created yet</span>';
            $checkbox = '<input type="checkbox" name="refs[]" value="' . htmlspecialchars($ref) . '" checked>';
        }

        echo '<tr>'
            . '<td>' . $checkbox . '</td>'
            . '<td>' . htmlspecialchars(vpnhoodpartnerconfig_upstreamName($product, (string) $ref))
            . ' <span class="text-muted">(ref ' . htmlspecialchars($ref) . ')</span></td>'
            . '<td>' . htmlspecialchars(vpnhoodpartnerconfig_payTypeLabel($payType)) . '</td>'
            . '<td>' . htmlspecialchars($cyclesText) . '</td>'
            . '<td>' . $status . '</td>'
            . '</tr>';
    }
    echo '</tbody></table>';

    if (!$missing) {
        echo '<div class="alert alert-success">Every product your provider offers already exists in your'
            . ' WHMCS. Nothing to create.</div>';
        echo '</form>';
        return;
    }

    echo '<div class="form-inline" style="margin-bottom:10px">';
    echo '<label style="margin-right:6px">Create in product group</label>';
    echo '<select name="gid" class="form-control" required>';
    echo '<option value="">— Select a group —</option>';
    foreach (vpnhoodpartnerconfig_productGroups() as $group) {
        echo '<option value="' . (int) $group->id . '">' . htmlspecialchars($group->name) . '</option>';
    }
    echo '</select> ';
    echo '<button type="submit" class="btn btn-primary">Create ' . count($missing) . ' Missing Product(s)</button>';
    echo '</div>';

    echo '<p class="text-muted">New products are created <b>hidden</b>, assigned to the'
        . ' <b>VpnHood Partner Connector</b> module with the correct Upstream Product already selected,'
        . ' and priced <b>0.00</b> on the cycles the upstream offers — your provider never dictates your'
        . ' retail price. Set your price on each product\'s <b>Pricing</b> tab, then un-hide it.'
        . ' Existing products are never modified or removed.</p>';

    echo '</form>';
}
