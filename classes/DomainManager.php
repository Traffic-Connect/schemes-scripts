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

        // Более точная проверка владельца домена
        $currentOwner = self::findDomainOwner($domain);

        if ($currentOwner && $currentOwner !== $user) {
            Logger::log("Domain exists with owner: $currentOwner, transferring to: $user");
            $domain = self::forceTransferDomain($domain, $currentOwner, $user);
            return $domain;
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
        Logger::log("ERROR: All domain creation methods failed");

        return $domain;
    }

    /**
     * Find actual domain owner by checking all users
     */
    private static function findDomainOwner($domain)
    {
        Logger::log("Searching for domain owner: $domain");

        // Получаем список всех пользователей
        $usersDir = '/usr/local/hestia/data/users';
        if (!is_dir($usersDir)) {
            Logger::log("Users directory not found: $usersDir");
            return null;
        }

        $users = scandir($usersDir);
        foreach ($users as $user) {
            if ($user === '.' || $user === '..') {
                continue;
            }

            // Проверяем, есть ли домен у этого пользователя
            exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
            if ($returnVar === 0) {
                Logger::log("Found domain owner: $user");
                return $user;
            }
        }

        Logger::log("No owner found for domain: $domain");
        return null;
    }

    /**
     * Force transfer domain from one user to another with aggressive cleanup
     */
    private static function forceTransferDomain($domain, $currentUser, $targetUser)
    {
        Logger::log("FORCE TRANSFER: $domain from $currentUser to $targetUser");

        $timestamp = time();
        $tempDir = "/tmp/domain_transfer_$timestamp";
        mkdir($tempDir, 0755, true);

        // Step 1: Backup everything
        self::backupDomainData($domain, $currentUser, $tempDir);

        // Step 2: Aggressive domain removal from current user
        self::aggressivelyRemoveDomain($domain, $currentUser);

        // Step 3: Wait and verify removal
        self::waitForDomainRemoval($domain, $currentUser);

        // Step 4: Clean up any remaining traces system-wide
        self::systemWideCleanup($domain);

        // Step 5: Create domain for target user
        self::createDomainForUser($domain, $targetUser);

        // Step 6: Restore content and settings
        self::restoreDomainData($domain, $targetUser, $tempDir);

        // Step 7: Final verification and cleanup
        self::verifyAndCleanup($domain, $targetUser, $tempDir);

        Logger::log("FORCE TRANSFER COMPLETED: $domain");
        return $domain;
    }

    /**
     * Backup domain data before transfer
     */
    private static function backupDomainData($domain, $user, $tempDir)
    {
        Logger::log("Backing up domain data: $domain");

        // Backup web content
        $webRoot = "/home/$user/web/$domain/public_html";
        if (is_dir($webRoot)) {
            exec("cp -a $webRoot $tempDir/public_html");
            Logger::log("Web content backed up");
        } else {
            mkdir("$tempDir/public_html", 0755, true);
            Logger::log("No web content to backup");
        }

        // Backup configuration
        $confDir = "/home/$user/conf/web/$domain";
        if (is_dir($confDir)) {
            exec("cp -a $confDir $tempDir/conf");
            Logger::log("Configuration backed up");
        }

        // Get and save domain settings
        exec("/usr/local/hestia/bin/v-list-web-domain $user $domain json 2>/dev/null", $domainInfo, $returnVar);
        if ($returnVar === 0) {
            $domainInfoJson = implode("\n", $domainInfo);
            file_put_contents("$tempDir/domain_settings.json", $domainInfoJson);
            Logger::log("Domain settings backed up");
        }
    }

    /**
     * Aggressively remove domain from user
     */
    private static function aggressivelyRemoveDomain($domain, $user)
    {
        Logger::log("Aggressively removing domain: $domain from user: $user");

        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        // Force delete with multiple attempts
        $deleteCommands = [
            "/usr/local/hestia/bin/v-delete-web-domain $escapedUser $escapedDomain yes",
            "/usr/local/hestia/bin/v-delete-dns-domain $escapedUser $escapedDomain yes",
            "/usr/local/hestia/bin/v-delete-mail-domain $escapedUser $escapedDomain yes"
        ];

        foreach ($deleteCommands as $cmd) {
            Logger::log("Executing: $cmd");
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                exec($cmd . " 2>&1", $output, $returnVar);
                Logger::log("Attempt $attempt: " . ($returnVar === 0 ? "SUCCESS" : "FAILED"));
                if (!empty($output)) {
                    Logger::log("Output: " . implode("\n", $output));
                }
                sleep(1);
            }
        }

        // Force rebuild user config
        Logger::log("Force rebuilding user config");
        exec("/usr/local/hestia/bin/v-rebuild-user $escapedUser yes 2>&1", $output, $returnVar);
        sleep(3);
    }

    /**
     * Wait for domain to be completely removed
     */
    private static function waitForDomainRemoval($domain, $user)
    {
        Logger::log("Waiting for complete domain removal: $domain");

        $maxAttempts = 20;
        $attempt = 0;

        while ($attempt < $maxAttempts) {
            $attempt++;

            exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $checkOutput, $checkReturnVar);

            if ($checkReturnVar !== 0) {
                Logger::log("Domain successfully removed after $attempt attempts");
                return true;
            }

            Logger::log("Domain still exists, attempt $attempt/$maxAttempts");
            sleep(2);
        }

        Logger::log("WARNING: Domain may still exist after $maxAttempts attempts");
        return false;
    }

    /**
     * System-wide cleanup of domain traces (based on your bash script)
     */
    private static function systemWideCleanup($domain)
    {
        Logger::log("System-wide cleanup for domain: $domain");

        // 1. Удаляем из всех конфигов пользователей Hestia
        exec("find /usr/local/hestia/data/users/ -name '*.conf' -exec sed -i '/$domain/d' {} \\;");
        Logger::log("Cleaned from Hestia user configs");

        // 2. Удаляем SSL сертификаты
        exec("rm -rf /etc/letsencrypt/live/$domain* 2>/dev/null");
        exec("rm -rf /etc/letsencrypt/archive/$domain* 2>/dev/null");
        exec("rm -rf /etc/letsencrypt/renewal/$domain* 2>/dev/null");
        exec("rm -rf /usr/local/hestia/ssl/$domain* 2>/dev/null");
        Logger::log("SSL certificates cleaned");

        // 3. Удаляем все веб-папки
        exec("find /home -name '*$domain*' -type d -exec rm -rf {} \\; 2>/dev/null");
        exec("find /var/www -name '*$domain*' -type d -exec rm -rf {} \\; 2>/dev/null");
        Logger::log("Web directories cleaned");

        // 4. Удаляем все конфиги Apache
        exec("find /etc/apache2 -name '*$domain*' -exec rm -f {} \\; 2>/dev/null");
        exec("find /etc/httpd -name '*$domain*' -exec rm -f {} \\; 2>/dev/null");
        Logger::log("Apache configs cleaned");

        // 5. Удаляем все конфиги Nginx
        exec("find /etc/nginx -name '*$domain*' -exec rm -f {} \\; 2>/dev/null");
        Logger::log("Nginx configs cleaned");

        // 6. Удаляем из /etc/hosts
        exec("sed -i '/$domain/d' /etc/hosts 2>/dev/null");
        Logger::log("Hosts file cleaned");

        // 7. Удаляем все логи
        exec("find /var/log -name '*$domain*' -exec rm -f {} \\; 2>/dev/null");
        Logger::log("Log files cleaned");

        // 8. Удаляем из cron если есть
        exec("find /var/spool/cron -name '*' -exec sed -i '/$domain/d' {} \\; 2>/dev/null");
        Logger::log("Cron entries cleaned");

        // 9. Очищаем конфиги от пустых строк
        exec("find /usr/local/hestia/data/users/ -name '*.conf' -exec sed -i '/^$/d' {} \\;");
        Logger::log("Empty lines cleaned from configs");

        // 10. Перезапускаем все сервисы
        exec("systemctl reload apache2 2>/dev/null");
        exec("systemctl reload nginx 2>/dev/null");
        exec("systemctl restart hestia 2>/dev/null");
        Logger::log("All services restarted");

        Logger::log("System-wide cleanup completed for: $domain");
        sleep(3);
    }

    /**
     * Create domain for target user (simplified, no low-level)
     */
    private static function createDomainForUser($domain, $user)
    {
        Logger::log("Creating domain for user: $user");

        // Try standard creation methods
        $methods = [
            "/usr/local/hestia/bin/v-add-web-domain $user $domain",
            "/usr/local/hestia/bin/v-add-web-domain $user $domain 'default' 'no'",
            "/usr/local/hestia/bin/v-add-web-domain $user $domain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only'"
        ];

        foreach ($methods as $method) {
            Logger::log("Trying: $method");
            exec($method . " 2>&1", $output, $returnVar);

            if ($returnVar === 0) {
                Logger::log("Domain created successfully with: $method");
                return true;
            } else {
                Logger::log("Method failed: " . implode("\n", $output));
            }
            sleep(1);
        }

        Logger::log("ERROR: All domain creation methods failed for: $domain");
        return false;
    }

    /**
     * Verify domain cleanup (based on your verification logic)
     */
    private static function verifyDomainCleanup($domain)
    {
        Logger::log("Verifying cleanup for domain: $domain");

        // Проверяем остатки в Hestia конфигах (исключаем history.log)
        exec("grep -r '$domain' /usr/local/hestia/data/users/ --exclude='history.log' 2>/dev/null", $hestiaRemains, $returnVar);
        if ($returnVar === 0 && !empty($hestiaRemains)) {
            Logger::log("WARNING: Found remains in Hestia configs");
            $count = count($hestiaRemains);
            Logger::log("  Found $count config entries (excluding history)");

            // Показываем только первые 3 для краткости
            $displayRemains = array_slice($hestiaRemains, 0, 3);
            foreach ($displayRemains as $remain) {
                Logger::log("  " . $remain);
            }

            if ($count > 3) {
                Logger::log("  ... and " . ($count - 3) . " more entries");
            }
        } else {
            Logger::log("✓ Clean in Hestia configs");
        }

        // Проверяем остатки в /etc (только первые 3)
        exec("find /etc -name '*$domain*' 2>/dev/null | head -3", $etcRemains);
        if (!empty($etcRemains)) {
            Logger::log("WARNING: Found remains in /etc");
            foreach ($etcRemains as $remain) {
                Logger::log("  Remain file: $remain");
            }
        } else {
            Logger::log("✓ Clean in /etc");
        }

        Logger::log("Cleanup verification completed");
    }

    /**
     * Restore domain data after transfer
     */
    private static function restoreDomainData($domain, $user, $tempDir)
    {
        Logger::log("Restoring domain data for: $domain");

        $newWebRoot = "/home/$user/web/$domain/public_html";

        // Ensure directory exists
        if (!is_dir($newWebRoot)) {
            mkdir($newWebRoot, 0755, true);
        }

        // Clean and restore content
        exec("rm -rf $newWebRoot/* 2>/dev/null");
        exec("rm -rf $newWebRoot/.[!.]* 2>/dev/null");

        if (is_dir("$tempDir/public_html")) {
            exec("cp -a $tempDir/public_html/* $newWebRoot/ 2>/dev/null");
            exec("cp -a $tempDir/public_html/.[!.]* $newWebRoot/ 2>/dev/null");
            Logger::log("Content restored");
        }

        // Set permissions
        exec("chown -R $user:$user /home/$user/web/$domain");
        exec("find $newWebRoot -type d -exec chmod 755 {} \\;");
        exec("find $newWebRoot -type f -exec chmod 644 {} \\;");

        // Set proxy template
        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only 2>&1", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("Proxy template set successfully");
        } else {
            Logger::log("Failed to set proxy template: " . implode("\n", $output));
        }
    }

    /**
     * Verify transfer and cleanup
     */
    private static function verifyAndCleanup($domain, $user, $tempDir)
    {
        Logger::log("Verifying transfer and cleaning up");

        // Verify domain exists for user
        exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("✓ Transfer verified: domain exists for user $user");
        } else {
            Logger::log("WARNING: Transfer verification failed - domain not found for user $user");
        }

        // Verify cleanup was successful
        self::verifyDomainCleanup($domain);

        // Rebuild configurations
        exec("/usr/local/hestia/bin/v-rebuild-web-domains $user 2>&1", $output, $returnVar);
        exec("/usr/local/hestia/bin/v-rebuild-user $user yes 2>&1", $output, $returnVar);
        Logger::log("User configurations rebuilt");

        // Test and reload nginx
        exec("nginx -t 2>&1", $nginxTest, $nginxTestReturn);
        if ($nginxTestReturn === 0) {
            exec("systemctl reload nginx 2>&1");
            Logger::log("✓ Nginx configuration validated and reloaded");
        } else {
            Logger::log("WARNING: Nginx configuration issues: " . implode("\n", $nginxTest));
        }

        // Cleanup temp directory
        exec("rm -rf $tempDir");
        Logger::log("Temporary files cleaned up");
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

}