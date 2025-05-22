<?php

require_once 'Logger.php';

class RedirectsManager
{
    /**
     * Prepare redirects data for site
     */
    public static function prepareRedirectsData($site, $allDomains, $isWwwDomain = false)
    {
        $domain = $site['domain'];
        $redirectsData = [
            'redirects' => []
        ];

        if ($isWwwDomain) {
            $redirectsData['mirror'] = "www";
        } else if (strpos($domain, 'www.') !== 0) {
            $wwwDomain = "www.$domain";
            if (in_array($wwwDomain, $allDomains)) {
                $redirectsData['mirror'] = "www";
            }
        }

        if (!empty($site['redirects'])) {
            foreach ($site['redirects'] as $redirect) {
                $fromUrl = $redirect['from_url'];
                $toUrl = $redirect['to_url'];
                $redirectsData['redirects'][$fromUrl] = $toUrl;
            }
        }

        return $redirectsData;
    }

    /**
     * Check if redirects need update
     */
    public static function needsUpdate($webRoot, $redirectsData)
    {
        $redirectsJsonPath = "$webRoot/redirects.json";

        if (!file_exists($redirectsJsonPath)) {
            return true;
        }

        $existingContent = file_get_contents($redirectsJsonPath);
        $existingData = json_decode($existingContent, true);

        if ($existingData === null) {
            return true;
        }

        $existingMirror = $existingData['mirror'] ?? null;
        $newMirror = $redirectsData['mirror'] ?? null;

        if ($existingMirror !== $newMirror) {
            return true;
        }

        $existingRedirects = $existingData['redirects'] ?? [];
        $newRedirects = $redirectsData['redirects'] ?? [];

        if (count($existingRedirects) !== count($newRedirects)) {
            return true;
        }

        foreach ($newRedirects as $fromUrl => $toUrl) {
            if (!isset($existingRedirects[$fromUrl]) || $existingRedirects[$fromUrl] !== $toUrl) {
                return true;
            }
        }

        foreach ($existingRedirects as $fromUrl => $toUrl) {
            if (!isset($newRedirects[$fromUrl])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Update redirects file
     */
    public static function updateRedirects($hestiaDomain, $schemaUser, $redirectsData)
    {
        $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

        if (!is_dir($webRoot)) {
            mkdir($webRoot, 0755, true);
        }

        if (self::needsUpdate($webRoot, $redirectsData)) {
            $redirectsJson = json_encode($redirectsData, JSON_PRETTY_PRINT);
            $result = file_put_contents("$webRoot/redirects.json", $redirectsJson);

            if ($result === false) {
                Logger::log("Failed to write redirects: $hestiaDomain");
                return false;
            }

            exec("chown $schemaUser:$schemaUser $webRoot/redirects.json");
            exec("chmod 644 $webRoot/redirects.json");

            Logger::log("Redirects updated: $hestiaDomain");
            return true;
        }

        return false;
    }

    /**
     * Check if redirects changed since last deployment
     */
    public static function hasChanged($previousState, $schemaName, $domain, $site, $allDomains, $isWwwDomain)
    {
        $stateKey = $schemaName . '_' . $domain . '_redirects';
        $currentRedirectsData = self::prepareRedirectsData($site, $allDomains, $isWwwDomain);

        if (!isset($previousState[$stateKey])) {
            return true;
        }

        $previousRedirectsData = $previousState[$stateKey];

        if (isset($currentRedirectsData['mirror']) !== isset($previousRedirectsData['mirror']) ||
            (isset($currentRedirectsData['mirror']) && isset($previousRedirectsData['mirror']) &&
                $currentRedirectsData['mirror'] !== $previousRedirectsData['mirror'])) {
            return true;
        }

        $currentCount = count($currentRedirectsData['redirects'] ?? []);
        $previousCount = count($previousRedirectsData['redirects'] ?? []);

        if ($currentCount !== $previousCount) {
            return true;
        }

        foreach ($currentRedirectsData['redirects'] as $fromUrl => $toUrl) {
            if (!isset($previousRedirectsData['redirects'][$fromUrl]) ||
                $previousRedirectsData['redirects'][$fromUrl] !== $toUrl) {
                return true;
            }
        }

        foreach ($previousRedirectsData['redirects'] as $fromUrl => $toUrl) {
            if (!isset($currentRedirectsData['redirects'][$fromUrl])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store redirects state for tracking changes
     */
    public static function storeState(&$state, $schemaName, $domain, $redirectsData)
    {
        $stateKey = $schemaName . '_' . $domain . '_redirects';
        $state[$stateKey] = $redirectsData;
    }
}