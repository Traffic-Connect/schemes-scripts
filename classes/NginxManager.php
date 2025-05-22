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
            "/usr/local/hestia/data/users/$user/web/$domain/nginx.conf",
            "/usr/local/hestia/data/users/$user/web/$domain/nginx.ssl.conf",
            "/etc/nginx/conf.d/$domain.conf",
            "/etc/nginx/conf.d/domains/$domain.conf",
            "/etc/nginx/conf.d/domains/$domain.ssl.conf",
            "/etc/nginx/sites-available/$domain",
            "/etc/nginx/sites-enabled/$domain"
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
     * Check if server block contains the domain
     */
    private static function serverBlockContainsDomain($serverBlock, $domain)
    {
        // Look for server_name directive
        if (preg_match('/server_name\s+([^;]+);/i', $serverBlock, $matches)) {
            $serverNames = trim($matches[1]);
            $domains = preg_split('/\s+/', $serverNames);

            foreach ($domains as $serverDomain) {
                // Remove quotes if present
                $serverDomain = trim($serverDomain, '"\'');

                if ($serverDomain === $domain ||
                    $serverDomain === "www.$domain" ||
                    ($serverDomain === substr($domain, 4) && strpos($domain, 'www.') === 0)) {
                    return true;
                }
            }
        }

        return false;
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

        // Find all server blocks
        $pattern = '/(\s*server\s*\{(?:[^{}]*+\{[^{}]*+\})*[^{}]*+)\}/s';

        if (preg_match_all($pattern, $content, $matches, PREG_OFFSET_CAPTURE)) {
            $updated = false;
            $newContent = $content;
            $offset = 0;

            foreach ($matches[0] as $index => $match) {
                $serverBlock = $match[0];
                $position = $match[1] + $offset;

                // Check if this server block contains our domain
                if (self::serverBlockContainsDomain($serverBlock, $domain)) {
                    Logger::log("Found matching server block for domain: $domain in $configPath");

                    // Find the closing brace of this server block
                    $closingBracePos = strrpos($serverBlock, '}');

                    if ($closingBracePos !== false) {
                        // Prepare restrictions
                        $restrictions = "\n    # Security restrictions for static files and redirects\n";
                        $restrictions .= "    location ~ ^/static/.*\.html\$ {\n";
                        $restrictions .= "        deny all;\n";
                        $restrictions .= "    }\n";
                        $restrictions .= "    location = /redirects.json {\n";
                        $restrictions .= "        deny all;\n";
                        $restrictions .= "    }\n";

                        // Insert restrictions before closing brace
                        $updatedServerBlock = substr_replace($serverBlock, $restrictions, $closingBracePos, 0);

                        // Replace in the full content
                        $newContent = substr_replace($newContent, $updatedServerBlock, $position, strlen($serverBlock));

                        // Adjust offset for next replacements
                        $offset += strlen($restrictions);
                        $updated = true;

                        Logger::log("Added restrictions to server block for domain: $domain");
                    }
                } else {
                    Logger::log("Server block does not match domain: $domain in $configPath");
                }
            }

            if ($updated) {
                if (file_put_contents($configPath, $newContent)) {
                    Logger::log("Successfully updated config file: $configPath");
                    return true;
                } else {
                    Logger::log("Failed to write updated config: $configPath");
                    return false;
                }
            } else {
                Logger::log("No matching server block found for domain: $domain in $configPath");
                return false;
            }
        } else {
            Logger::log("Could not find any server blocks in: $configPath");
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