<?php

namespace WHMCS\Module\Server\VpnHoodPartner;

use Exception;

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

    private const API_PATH = '/modules/addons/vpnhoodpartnerhub/api.php';

    public function __construct(string $baseUrl, string $apiKey, string $apiSecret, bool $secure = true)
    {
        $base = rtrim(trim($baseUrl), '/');
        // Accept either a bare host or a full URL; normalize to a scheme.
        if (!preg_match('#^https?://#i', $base)) {
            $base = ($secure ? 'https://' : 'http://') . $base;
        }
        $this->endpoint = $base . self::API_PATH;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * Build a HubClient from WHMCS server module $params.
     */
    public static function fromParams(array $params): HubClient
    {
        $host = $params['serverhostname'] ?: ($params['serverip'] ?? '');
        $secure = !empty($params['serversecure']);
        return new HubClient(
            (string) $host,
            (string) ($params['serverusername'] ?? ''),
            (string) ($params['serverpassword'] ?? ''),
            $secure
        );
    }

    /**
     * Call an API action and return the decoded "data" payload.
     *
     * @throws Exception on transport or API error.
     */
    public function call(string $action, array $params = []): array
    {
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Connection to VpnHood Partner Hub failed: ' . $err);
        }
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception('Invalid response from Hub (HTTP ' . $httpCode . ').');
        }

        if (empty($decoded['success'])) {
            $message = $decoded['error'] ?? ('Hub returned HTTP ' . $httpCode);
            throw new Exception($message);
        }

        return $decoded['data'] ?? [];
    }
}
