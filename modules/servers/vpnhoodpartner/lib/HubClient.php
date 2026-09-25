<?php

namespace WHMCS\Module\Server\VpnHoodPartner;

require_once __DIR__ . '/HubApiException.php';

use Exception;
use WHMCS\Database\Capsule;

/**
 * HTTP client for the upstream VpnHood Partner Hub API.
 *
 * Modeled on the vpnhoodstore AsyncApiClientFactory cURL pattern, but instead of
 * talking to the VpnHood access server it talks to the partner's upstream WHMCS
 * (where the "VpnHood! Partner Hub" addon is installed). Authentication is the
 * partner's API key + secret, sent as headers over HTTPS.
 */
class HubClient
{
    private string $endpoint;
    private string $apiKey;
    private string $apiSecret;
    private bool $insecure;

    private const API_PATH = '/modules/addons/vpnhoodpartnerhub/api.php';

    /** tblconfiguration row: what the configured Hub last said it supports (X-Vpnhood-Hub-Features). */
    private const FEATURES_SETTING = 'VpnHoodPartnerHubFeatures';

    /** The Hub makes a repeated keyed order return the first one instead of charging again. */
    public const FEATURE_IDEMPOTENCY = 'idempotency-v1';

    /**
     * @param bool $secure   When the base URL has no scheme, choose https (true) or http (false).
     * @param bool $insecure DEV ONLY: skip TLS certificate verification (self-signed/loopback Hub).
     */
    public function __construct(string $baseUrl, string $apiKey, string $apiSecret, bool $secure = true, bool $insecure = false)
    {
        $base = rtrim(trim($baseUrl), '/');
        // Accept either a bare host or a full URL; normalize to a scheme.
        if (!preg_match('#^https?://#i', $base)) {
            $base = ($secure ? 'https://' : 'http://') . $base;
        }
        $this->endpoint = $base . self::API_PATH;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->insecure = $insecure;
    }

    /**
     * Build a HubClient from the global connection settings stored by the
     * `vpnhoodpartnerconfig` addon (System Settings → Addon Modules).
     *
     * Dev/testing: enable "Skip TLS Verification" in that addon to accept
     * self-signed / loopback Hub certificates. Never use this in production.
     *
     * @throws Exception when the Hub URL has not been configured.
     */
    public static function fromConfig(): HubClient
    {
        $settings = \WHMCS\Database\Capsule::table('tbladdonmodules')
            ->where('module', 'vpnhoodpartnerconfig')
            ->pluck('value', 'setting');

        $url = trim((string) ($settings['HubUrl'] ?? ''));
        if ($url === '') {
            throw new Exception(
                'VpnHood Partner Hub is not configured. Set the Hub URL, API Key and API Secret in '
                . 'System Settings → Addon Modules → VpnHood! Partner Connector Configuration.'
            );
        }

        $insecure = in_array(
            strtolower((string) ($settings['SkipTlsVerify'] ?? '')),
            ['on', 'yes', '1'],
            true
        );

        return new HubClient(
            $url,
            (string) ($settings['ApiKey'] ?? ''),
            self::decryptSetting((string) ($settings['ApiSecret'] ?? '')),
            true,
            $insecure
        );
    }

    /**
     * Decrypt a WHMCS "password"-type addon setting, tolerating plaintext storage.
     *
     * Depending on the WHMCS version, addon password fields may be stored encrypted OR in
     * plaintext. DecryptPassword does not fail on a value that was never encrypted — it
     * reports success and returns BINARY GARBAGE. Sent as an HTTP header, those control
     * bytes make the whole request malformed, which Cloudflare-fronted Hubs reject with a
     * bare "400 (empty body)" that never reaches the Hub's PHP. So the decrypted value is
     * only trusted when it is printable ASCII (a real key/secret always is); anything else
     * means the stored value was already plaintext — use it as-is.
     */
    private static function decryptSetting(string $value): string
    {
        if ($value === '') {
            return '';
        }
        try {
            $result = localAPI('DecryptPassword', ['password2' => $value]);
            $plain = (string) ($result['password'] ?? '');
            if ($plain !== '' && !preg_match('/[^\x20-\x7E]/', $plain)) {
                return $plain;
            }
        } catch (\Throwable $e) {
            // Ignore and fall back to the raw value.
        }
        return $value;
    }

    /**
     * Call an API action and return the decoded "data" payload.
     *
     * @throws HubApiException on transport or API error (credentials: Exception).
     */
    public function call(string $action, array $params = []): array
    {
        // A non-printable byte in a credential corrupts the whole HTTP request; a
        // Cloudflare-fronted Hub then answers a bare "400 (empty body)" that is almost
        // impossible to trace. Fail here with an actionable message instead.
        foreach (['X-Vpnhood-Key' => $this->apiKey, 'X-Vpnhood-Secret' => $this->apiSecret] as $name => $credential) {
            if (preg_match('/[^\x20-\x7E]/', $credential)) {
                throw new Exception(
                    $name . ' contains non-printable characters; re-save the API credentials in '
                    . 'System Settings → Addon Modules → VpnHood! Partner Connector Configuration.'
                );
            }
        }

        $payload = json_encode(array_merge(['action' => $action], $params));

        $ch = curl_init($this->endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Vpnhood-Key: ' . $this->apiKey,
            'X-Vpnhood-Secret: ' . $this->apiSecret,
        ]);
        // Identify the client. PHP cURL sends no User-Agent by default, and Cloudflare/WAFs
        // in front of the Hub commonly reject an empty User-Agent with a bare 400/403 before
        // the request ever reaches the Hub's PHP.
        curl_setopt($ch, CURLOPT_USERAGENT, 'VpnHoodPartnerConnector/1.0 (+WHMCS)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        if ($this->insecure) {
            // DEV ONLY: accept self-signed / loopback Hub certificates.
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        $headers = [];
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        });

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new HubApiException('Connection to VpnHood Partner Hub failed: ' . $err, $httpCode, false);
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            // Not the JSON envelope we expect — surface a snippet of the actual body so the
            // cause is visible (an HTML error page, a WAF/proxy block, a PHP error, or empty
            // body all otherwise look identical from a bare status code).
            throw new HubApiException(
                'Invalid response from Hub (HTTP ' . $httpCode . '): ' . self::responseSnippet($response),
                $httpCode,
                false
            );
        }

        // The Hub itself answered, success or error, so its answer says what it supports — and a
        // Hub that no longer sends the header (rolled back, or another Hub at the same address)
        // loses what an earlier one advertised. A proxy page or a timeout never gets here.
        $answered = array_key_exists('success', $decoded);
        if ($answered) {
            $this->recordFeatures($headers['x-vpnhood-hub-features'] ?? '');
        }

        if (empty($decoded['success'])) {
            $message = $decoded['error'] ?? ('Hub returned HTTP ' . $httpCode);
            throw new HubApiException(
                (string) $message,
                $httpCode,
                $answered,
                (string) ($decoded['code'] ?? ''),
                is_array($decoded['details'] ?? null) ? $decoded['details'] : []
            );
        }

        return $decoded['data'] ?? [];
    }

    /**
     * Whether the configured Hub last said it supports $feature. Unknown counts as no: never
     * answered yet, or the endpoint or API key changed since (the cache is keyed on both).
     */
    public function supports(string $feature): bool
    {
        $stored = $this->storedFeatures();
        return $stored !== null && in_array($feature, $stored, true);
    }

    /** @return string[]|null null when nothing is known about the configured Hub */
    private function storedFeatures(): ?array
    {
        try {
            $value = Capsule::table('tblconfiguration')->where('setting', self::FEATURES_SETTING)->value('value');
        } catch (\Throwable $e) {
            return null;
        }
        $stored = json_decode((string) $value, true);
        if (!is_array($stored) || ($stored['for'] ?? '') !== $this->fingerprint()) {
            return null;
        }
        return array_values(array_map('strval', (array) ($stored['features'] ?? [])));
    }

    private function recordFeatures(string $header): void
    {
        $features = array_values(array_filter(array_map('trim', explode(',', $header)), 'strlen'));
        if ($this->storedFeatures() === $features) {
            return;
        }
        try {
            \WHMCS\Config\Setting::setValue(self::FEATURES_SETTING, json_encode([
                'for'      => $this->fingerprint(),
                'features' => $features,
                'at'       => gmdate('Y-m-d H:i:s'),
            ]));
        } catch (\Throwable $e) {
            // A cache: a call never fails over it.
        }
    }

    /** Which Hub, as whom: a changed endpoint or API key invalidates what is known. */
    private function fingerprint(): string
    {
        return hash('sha256', $this->endpoint . "\0" . $this->apiKey);
    }

    /** A short, single-line, tag-stripped snippet of a raw response body for error messages. */
    private static function responseSnippet(string $response): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags($response)));
        if ($text === '') {
            return '(empty body)';
        }
        return mb_strlen($text) > 200 ? mb_substr($text, 0, 200) . '…' : $text;
    }
}
