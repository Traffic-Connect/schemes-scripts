<?php

require_once __DIR__ . '/Logger.php';

class DomainManager
{
    /**
     * Create domain for user or transfer from another user
     */
    public static function createDomain($domain, $user)
    {
        $originalDomain = $domain;
        $isWwwDomain = (strpos($domain, 'www.') === 0);
        if ($isWwwDomain) {
            $domain = substr($domain, 4);
        }

        exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("Domain already exists for user $user: $domain");
            return $domain;
        }

        Logger::log("Checking for domain ownership: $domain");
        exec("/usr/local/hestia/bin/v-search-domain-owner $domain 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $existingUser = trim(implode("\n", $output));

            // Проверяем, что это действительно имя пользователя, а не сообщение об ошибке
            if (!empty($existingUser) &&
                $existingUser !== $user &&
                !strpos($existingUser, 'Error:') &&
                !strpos($existingUser, 'doesn\'t exist') &&
                strlen($existingUser) < 100) { // Имя пользователя не должно быть очень длинным

                Logger::log("Domain exists with different owner: $existingUser");
                $domain = self::transferDomain($domain, $existingUser, $user);
                return $domain;
            }
        }

        Logger::log("Domain does not exist - creating new: $domain");

        Logger::log("Adding domain to user $user: $domain");
        $output = [];
        exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Domain created successfully: $domain");

            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to apply proxy template for $domain");
            }

            return $domain;
        }

        Logger::log("Standard domain creation failed: " . implode("\n", $output));

        Logger::log("Trying alternative domain creation method");
        exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only' 2>&1", $outputAlt, $returnVarAlt);

        if ($returnVarAlt === 0) {
            Logger::log("Alternative domain creation successful");
            return $domain;
        }

        Logger::log("Alternative method failed: " . implode("\n", $outputAlt));
        Logger::log("Using low-level approach");

        $domain = self::createDomainLowLevel($domain, $user);

        return $domain;
    }

    /**
     * Transfer domain from one user to another with proper waiting
     */
    private static function transferDomain($domain, $currentUser, $targetUser)
    {
        Logger::log("Transferring domain $domain from $currentUser to $targetUser");

        $timestamp = time();
        $tempDir = "/tmp/domain_transfer_$timestamp";
        mkdir($tempDir, 0755, true);

        // Backup content and settings
        $webRoot = "/home/$currentUser/web/$domain/public_html";
        if (is_dir($webRoot)) {
            exec("cp -a $webRoot $tempDir/public_html");
            Logger::log("Content copied to temporary directory");
        } else {
            mkdir("$tempDir/public_html", 0755, true);
            Logger::log("Created empty public_html directory");
        }

        // Get domain settings before deletion (only proxy template needed)
        $proxyTemplate = 'tc-nginx-only';

        exec("/usr/local/hestia/bin/v-list-web-domain $currentUser $domain json", $domainInfo, $returnVar);
        if ($returnVar === 0) {
            $domainInfoJson = implode("\n", $domainInfo);
            $domainSettings = json_decode($domainInfoJson, true);

            if (!empty($domainSettings[$domain])) {
                $domainSettings = $domainSettings[$domain];
                $proxyTemplate = $domainSettings['PROXY'] ?? 'tc-nginx-only';
                Logger::log("Retrieved domain settings: PROXY=$proxyTemplate");
            }
        }

        // Delete domain from current user with --yes flag and wait for completion
        Logger::log("Deleting domain from current user with --yes flag");
        self::deleteDomainCompletely($domain, $currentUser);

        // Create domain for target user
        Logger::log("Creating domain for target user");
        exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::log("Standard domain creation failed: " . implode("\n", $output));

            Logger::log("Trying alternative domain creation method");
            exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 'default' 'no' '' '' '' '' '' '' '$proxyTemplate' 2>&1", $outputAlt, $returnVarAlt);

            if ($returnVarAlt !== 0) {
                Logger::log("Alternative method failed: " . implode("\n", $outputAlt));
                Logger::log("Using low-level approach");
                self::createDomainLowLevel($domain, $targetUser, $proxyTemplate);
            }
        }

        // Verify domain was created
        exec("/usr/local/hestia/bin/v-list-web-domain $targetUser $domain 2>/dev/null", $checkOutput, $checkReturnVar);
        if ($checkReturnVar !== 0) {
            Logger::log("ERROR: Domain not created for target user after all attempts");
            exec("rm -rf $tempDir");
            return $domain;
        }

        Logger::log("Domain successfully created for target user");

        // Restore content
        $newWebRoot = "/home/$targetUser/web/$domain/public_html";
        exec("rm -rf $newWebRoot/* 2>/dev/null");
        exec("rm -rf $newWebRoot/.[!.]* 2>/dev/null");

        if (is_dir("$tempDir/public_html")) {
            Logger::log("Restoring website content");
            exec("cp -a $tempDir/public_html/* $newWebRoot/ 2>/dev/null");
            exec("cp -a $tempDir/public_html/.[!.]* $newWebRoot/ 2>/dev/null");
        }

        // Set proper permissions
        exec("chown -R $targetUser:$targetUser /home/$targetUser/web/$domain");
        exec("find $newWebRoot -type d -exec chmod 755 {} \\;");
        exec("find $newWebRoot -type f -exec chmod 644 {} \\;");

        // Set proxy template
        if (!empty($proxyTemplate)) {
            Logger::log("Setting proxy template: $proxyTemplate");
            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $targetUser $domain $proxyTemplate 2>&1", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to set proxy template: " . implode("\n", $output));
            }
        }

        // Cleanup temp directory
        exec("rm -rf $tempDir");

        // Rebuild web domains to ensure proper nginx config
        Logger::log("Rebuilding web domains for target user");
        exec("/usr/local/hestia/bin/v-rebuild-web-domains $targetUser 2>&1", $output, $returnVar);

        Logger::log("Domain transfer completed: $domain");
        return $domain;
    }

    /**
     * Delete domain completely from user and wait for completion
     */
    private static function deleteDomainCompletely($domain, $user)
    {
        Logger::log("Starting complete domain deletion for $domain from user $user");

        // Delete with --yes flag to avoid prompts and wait
        $deleteCommands = [
            "/usr/local/hestia/bin/v-delete-web-domain $user $domain yes",
            "/usr/local/hestia/bin/v-delete-dns-domain $user $domain yes",
            "/usr/local/hestia/bin/v-delete-mail-domain $user $domain yes"
        ];

        foreach ($deleteCommands as $cmd) {
            Logger::log("Executing: $cmd");
            exec($cmd . " 2>&1", $cmdOutput, $cmdReturnVar);
            if ($cmdReturnVar !== 0) {
                Logger::log("Command completed with warnings: $cmd");
                if (!empty($cmdOutput)) {
                    Logger::log("Output: " . implode("\n", $cmdOutput));
                }
            } else {
                Logger::log("Command executed successfully: $cmd");
            }

            // Wait between commands
            sleep(2);
        }

        // Wait for domain to be completely removed
        Logger::log("Waiting for domain to be completely removed...");
        $maxWaitTime = 30; // Maximum wait time in seconds
        $waitInterval = 2; // Check every 2 seconds
        $waitedTime = 0;

        while ($waitedTime < $maxWaitTime) {
            exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $checkOutput, $checkReturnVar);

            if ($checkReturnVar !== 0) {
                Logger::log("Domain successfully removed from user $user after {$waitedTime}s");
                break;
            }

            Logger::log("Domain still exists, waiting... ({$waitedTime}s/{$maxWaitTime}s)");
            sleep($waitInterval);
            $waitedTime += $waitInterval;
        }

        if ($waitedTime >= $maxWaitTime) {
            Logger::log("Warning: Domain may still exist after maximum wait time");
        }

        // Rebuild user configuration
        Logger::log("Rebuilding user configuration for $user");
        exec("/usr/local/hestia/bin/v-rebuild-user $user yes 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("User configuration rebuilt successfully");
        } else {
            Logger::log("Warning: User rebuild completed with warnings: " . implode("\n", $output));
        }

        // Wait a bit more for nginx configs to be updated
        sleep(3);

        // Verify nginx configuration and reload if needed
        Logger::log("Testing nginx configuration");
        exec("nginx -t 2>&1", $nginxTest, $nginxTestReturn);

        if ($nginxTestReturn === 0) {
            Logger::log("Nginx configuration is valid, reloading");
            exec("systemctl reload nginx 2>&1", $reloadOutput, $reloadReturn);
            if ($reloadReturn === 0) {
                Logger::log("Nginx reloaded successfully");
            } else {
                Logger::log("Warning: Nginx reload failed: " . implode("\n", $reloadOutput));
            }
        } else {
            Logger::log("Warning: Nginx configuration test failed: " . implode("\n", $nginxTest));
        }

        Logger::log("Domain deletion process completed for $domain");
    }

    /**
     * Create domain using low-level approach
     */
    private static function createDomainLowLevel($domain, $user, $proxyTemplate = 'tc-nginx-only')
    {
        Logger::log("Creating domain using low-level approach: $domain for $user");

        $webRoot = "/home/$user/web/$domain";
        $publicHtml = "$webRoot/public_html";

        if (!is_dir($webRoot)) {
            mkdir($webRoot, 0755, true);
        }

        if (!is_dir($publicHtml)) {
            mkdir($publicHtml, 0755, true);
        }

        exec("chown -R $user:$user $webRoot");

        exec("hostname -I | awk '{print $1}'", $ipAddress);
        $ip = trim($ipAddress[0] ?? '');
        if (empty($ip)) {
            exec("curl -s ifconfig.me", $ipAddress);
            $ip = trim($ipAddress[0] ?? '127.0.0.1');
        }

        $webConfFile = "/usr/local/hestia/data/users/$user/web.conf";

        if (file_exists($webConfFile)) {
            $currentDate = date("Y-m-d H:i:s");

            $domainEntry = "WEB_DOMAIN='$domain' IP='$ip' IP6='' WEB_TPL='default' BACKEND='php-fpm' PROXY='$proxyTemplate' PROXY_EXT='html,htm,php' SSL='no' SSL_HOME='same' STATS='' STATS_AUTH='' STATS_USER='' U_DISK='0' U_BANDWIDTH='0' SUSPENDED='no' TIME='$currentDate' DATE='$currentDate'\n";

            file_put_contents($webConfFile, $domainEntry, FILE_APPEND);
            Logger::log("Manually added domain entry to web.conf");

            $confDir = "/home/$user/conf/web/$domain";
            if (!is_dir($confDir)) {
                mkdir($confDir, 0755, true);
                exec("chown $user:$user $confDir");
            }

            exec("/usr/local/hestia/bin/v-rebuild-web-domains $user");

            exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);

            if ($returnVar === 0) {
                Logger::log("Low-level domain addition successful");
            } else {
                Logger::log("Warning: Low-level domain addition might have issues");

                Logger::log("Restarting services");
                exec("/usr/local/hestia/bin/v-restart-service 'nginx'");
                exec("/usr/local/hestia/bin/v-restart-service 'apache2'");
                exec("/usr/local/hestia/bin/v-restart-service 'hestia'");

                exec("/usr/local/hestia/bin/v-rebuild-user $user 'yes'");
            }
        } else {
            Logger::log("Error: web.conf not found for user $user");
        }

        return $domain;
    }
}