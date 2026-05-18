<?php
/**
 * Splynx API Client for ticket event system.
 * Supports GET, POST, and raw file downloads.
 */
class SplynxApiClient
{
    private $apiUrl;
    private $apiKey;
    private $apiSecret;
    private $requestTimeout = 30;
    private $connectTimeout = 10;

    public function __construct($apiUrl, $apiKey, $apiSecret)
    {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * GET request to the Splynx API.
     */
    public function get($endpoint, $params = [])
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        $qs = http_build_query($params);
        if ($qs) $url .= '?' . $qs;
        return $this->request($url, 'GET');
    }

    /**
     * POST request to the Splynx API.
     */
    public function post($endpoint, $data = [])
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        return $this->request($url, 'POST', $data);
    }

    /**
     * PUT request to the Splynx API.
     */
    public function put($endpoint, $data = [])
    {
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        return $this->request($url, 'PUT', $data);
    }

    /**
     * Download a file from a URL using API authentication.
     * Returns raw file content or false on failure.
     */
    public function downloadFile($url)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            error_log("File download failed (HTTP $httpCode): $curlError — URL: $url");
            return false;
        }

        return $response;
    }

    private function request($url, $method = 'GET', $data = null)
    {
        $headers = [
            'Authorization: Basic ' . base64_encode($this->apiKey . ':' . $this->apiSecret),
            'Accept: application/json',
        ];

        if ($method === 'POST') {
            $headers[] = 'Content-Type: application/json';
        }

        if ($method === 'PUT') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => $this->requestTimeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            error_log("API request failed ($method $url): $curlError");
            return null;
        }

        if ($httpCode >= 200 && $httpCode < 300) {
            $decoded = json_decode($response, true);
            return $decoded !== null ? $decoded : true;
        }

        if ($httpCode === 404) {
            return [];
        }

        error_log("API error ($method $url): HTTP $httpCode — " . substr($response, 0, 300));
        return null;
    }
}
