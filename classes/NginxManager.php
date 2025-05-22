<?php

require_once __DIR__ . '/Logger.php';

class NginxManager
{
    /**
     * Find Nginx configuration file for domain
     */
    private static function findNginxConfig($domain, $user)
    {
        $possible_paths = [
            "/home/$user/conf/web/$domain/nginx.conf",
            "/home/$user/conf/web/$domain/nginx.ssl.conf",
        ];

        $found_configs = [];

        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $found_configs[] = $path;
                Logger::log("Found Nginx config: $path");
            }
        }

        return $found_configs;
    }

    /**
     * Check if restrictions already exist in config
     */
    private static function hasRestrictions($content)
    {
        $static_restriction = "location ~ ^/static/.*\.html\$ {";
        $redirects_restriction = "location = /redirects.json {";

        return (strpos($content, $static_restriction) !== false &&
            strpos($content, $redirects_restriction) !== false);
    }

    /**
     * Add restrictions to server block
     */
    private static function addRestrictionsToConfig($configPath, $domain)
    {
        $content = file_get_contents($configPath);

        if (self::hasRestrictions($content)) {
            Logger::log("Restrictions already exist in: $configPath");
            return true;
        }

        // Find server block and add restrictions before closing brace
        $pattern = '/(\s*server\s*\{[^}]*?)(\s*\})/s';

        if (preg_match($pattern, $content, $matches)) {
            $serverBlock = $matches[1];
            $closingBrace = $matches[2];

            // Prepare restrictions
            $restrictions = "\n    # Security restrictions for static files and redirects\n";
            $restrictions .= "    location ~ ^/static/.*\.html\$ {\n";
            $restrictions .= "        deny all;\n";
            $restrictions .= "    }\n";
            $restrictions .= "    location = /redirects.json {\n";
            $restrictions .= "        deny all;\n";
            $restrictions .= "    }\n";

            // Replace server block with updated one
            $newServerBlock = $serverBlock . $restrictions;
            $newContent = str_replace($matches[0], $newServerBlock . $closingBrace, $content);

            if (file_put_contents($configPath, $newContent)) {
                Logger::log("Added restrictions to: $configPath");
                return true;
            } else {
                Logger::log("Failed to write config: $configPath");
                return false;
            }
        } else {
            Logger::log("Could not find server block in: $configPath");
            return false;
        }
    }

    /**
     * Add access restrictions to Nginx configuration
     */
    public static function addRestrictions($domain, $user)
    {
        $configFiles = self::findNginxConfig($domain, $user);

        if (empty($configFiles)) {
            Logger::log("No Nginx configuration found for domain: $domain");

            // Log available files for debugging
            if (is_dir("/home/$user/conf/web/$domain/")) {
                $files = scandir("/home/$user/conf/web/$domain/");
                Logger::log("Files in domain config dir: " . implode(", ", array_diff($files, ['.', '..'])));
            }

            // Try to rebuild domain config and search again
            exec("/usr/local/hestia/bin/v-rebuild-web-domain $user $domain 2>&1", $output, $returnVar);
            if ($returnVar === 0) {
                Logger::log("Rebuilt domain config, searching again...");
                $configFiles = self::findNginxConfig($domain, $user);
            }

            if (empty($configFiles)) {
                return false;
            }
        }

        $success = false;

        foreach ($configFiles as $configPath) {
            if (self::addRestrictionsToConfig($configPath, $domain)) {
                $success = true;
            }
        }

        if ($success) {
            // Test nginx configuration
            exec("nginx -t 2>&1", $testOutput, $testReturn);
            if ($testReturn === 0) {
                // Reload nginx to apply changes
                exec("/usr/local/hestia/bin/v-restart-web 2>&1", $output, $returnVar);
                if ($returnVar !== 0) {
                    Logger::log("Warning: Failed to restart web services");
                } else {
                    Logger::log("Nginx reloaded successfully for domain: $domain");
                }
            } else {
                Logger::log("Nginx configuration test failed: " . implode("\n", $testOutput));
                return false;
            }
        }

        return $success;
    }
}