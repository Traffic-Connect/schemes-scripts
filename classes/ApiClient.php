<?php

require_once 'config.php';
require_once 'Logger.php';

class ApiClient
{
    /**
     * Get server IP address
     */
    public static function getServerIp()
    {
        $ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
        if (empty($ip) || $ip === '127.0.0.1') {
            $ip = trim(shell_exec("curl -s ifconfig.me"));
        }
        if (empty($ip) || $ip === '127.0.0.1') {
            $ip = trim(shell_exec("ip route get 1 | awk '{print $7;exit}'"));
        }
        return $ip;
    }

    /**
     * Fetch schemas from API by server address
     */
    public static function getSchemas()
    {
        $serverIp = self::getServerIp();
        $url = Config::API_URL . '/schemas/by-server?' . http_build_query(['server_address' => $serverIp]);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-API-Token: ' . Config::API_TOKEN,
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => false
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error("Failed to fetch schemas (HTTP $httpCode)");
        }

        $data = json_decode($response, true);
        if ($data === null) {
            Logger::error("Invalid JSON from API");
        }

        return $data;
    }
}