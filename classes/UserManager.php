<?php

require_once 'Logger.php';
require_once 'Transliterator.php';

class UserManager
{
    /**
     * Create or get schema user for Hestia CP
     */
    public static function createSchemaUser($schemaName)
    {
        $transliterated = Transliterator::transliterate($schemaName);
        $userName = strtolower(preg_replace('/\s+/', '_', $transliterated));
        $userName = preg_replace('/[^a-z0-9_]/', '', $userName);

        if (strlen($userName) < 2) {
            $userName = 'schema_' . substr(md5($schemaName), 0, 10);
        } else {
            $userName = "schema_$userName";
        }

        if (strlen($userName) > 32) {
            $userName = substr($userName, 0, 32);
        }

        $userName = preg_replace('/_+/', '_', $userName);

        exec("/usr/local/hestia/bin/v-list-user $userName", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("User exists: $userName");
            return $userName;
        }

        $password = bin2hex(random_bytes(8));
        $displayName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $transliterated);
        $displayName = preg_replace('/_+/', '_', $displayName);
        $email = "schema@" . md5($schemaName) . ".com";
        $escapedDisplayName = str_replace("'", "'\\''", $displayName);

        $cmd = "/usr/local/hestia/bin/v-add-user $userName $password $email default Schema '$escapedDisplayName'";
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::error("Failed to create user $userName");
        }

        Logger::log("User created: $userName");
        return $userName;
    }

    /**
     * Get list of web domains for user
     */
    public static function getUserDomains($user)
    {
        exec("/usr/local/hestia/bin/v-list-web-domains $user json", $output, $returnVar);
        if ($returnVar === 0) {
            $outputStr = implode("\n", $output);
            $data = json_decode($outputStr, true);
            return array_keys($data);
        }
        return [];
    }
}