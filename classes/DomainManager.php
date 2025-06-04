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

        // Экранируем параметры
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        exec("/usr/local/hestia/bin/v-list-web-domain $escapedUser $escapedDomain 2>/dev/null", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("Domain already exists for user $user: $domain");
            return $domain;
        }

        Logger::log("Checking for domain ownership: $domain");
        exec("/usr/local/hestia/bin/v-search-domain-owner $escapedDomain 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $existingUser = trim(implode("\n", $output));

            if (!empty($existingUser) && $existingUser !== $user) {
                Logger::log("Domain exists with different owner: $existingUser");
                $domain = self::transferDomain($domain, $existingUser, $user);
                return $domain;
            }
        }

        Logger::log("Domain does not exist - creating new: $domain");

        self::cleanupDomainTraces($domain);

        Logger::log("Adding domain to user $user: $domain");
        $output = [];
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedUser $escapedDomain 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Domain created successfully: $domain");

            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $escapedUser $escapedDomain tc-nginx-only", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to apply proxy template for $domain");
            }

            return $domain;
        }

        Logger::log("Standard domain creation failed: " . implode("\n", $output));

        Logger::log("Trying alternative domain creation method");
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedUser $escapedDomain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only' 2>&1", $outputAlt, $returnVarAlt);

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
     * Transfer domain from one user to another
     */
    private static function transferDomain($domain, $currentUser, $targetUser)
    {
        Logger::log("Transferring domain $domain from $currentUser to $targetUser");

        // Экранируем все параметры
        $escapedDomain = escapeshellarg($domain);
        $escapedCurrentUser = escapeshellarg($currentUser);
        $escapedTargetUser = escapeshellarg($targetUser);

        $timestamp = time();
        $tempDir = "/tmp/domain_transfer_$timestamp";
        mkdir($tempDir, 0755, true);

        $webRoot = "/home/$currentUser/web/$domain/public_html";
        if (is_dir($webRoot)) {
            exec("cp -a " . escapeshellarg($webRoot) . " " . escapeshellarg("$tempDir/public_html"));
            Logger::log("Content copied to temporary directory");
        } else {
            mkdir("$tempDir/public_html", 0755, true);
            Logger::log("Created empty public_html directory");
        }

        $proxyTemplate = 'tc-nginx-only';
        $ssl = 'no';

        exec("/usr/local/hestia/bin/v-list-web-domain $escapedCurrentUser $escapedDomain json", $domainInfo, $returnVar);
        if ($returnVar === 0) {
            $domainInfoJson = implode("\n", $domainInfo);
            $domainSettings = json_decode($domainInfoJson, true);

            if (!empty($domainSettings[$domain])) {
                $domainSettings = $domainSettings[$domain];
                $proxyTemplate = $domainSettings['PROXY'] ?? 'tc-nginx-only';
                $ssl = $domainSettings['SSL'] ?? 'no';
                Logger::log("Retrieved domain settings: PROXY=$proxyTemplate, SSL=$ssl");
            } else {
                Logger::log("Failed to parse domain settings JSON, using defaults");
            }
        } else {
            Logger::log("Failed to get domain settings, using defaults");
        }

        $sslDir = "/home/$currentUser/conf/web/$domain/ssl";
        $hasSsl = false;

        if ($ssl === 'yes' && is_dir($sslDir)) {
            exec("mkdir -p " . escapeshellarg("$tempDir/ssl"));
            exec("cp -a " . escapeshellarg("$sslDir/*") . " " . escapeshellarg("$tempDir/ssl/") . " 2>/dev/null");
            $hasSsl = true;
            Logger::log("SSL certificates copied");
        }

        Logger::log("Suspending domain for current user");
        exec("/usr/local/hestia/bin/v-suspend-web-domain $escapedCurrentUser $escapedDomain 2>/dev/null");
        sleep(2);

        Logger::log("Deleting domain from current user");
        $deleteCommands = [
            "/usr/local/hestia/bin/v-delete-web-domain $escapedCurrentUser $escapedDomain --force",
            "/usr/local/hestia/bin/v-delete-dns-domain $escapedCurrentUser $escapedDomain --force",
            "/usr/local/hestia/bin/v-delete-mail-domain $escapedCurrentUser $escapedDomain --force"
        ];

        foreach ($deleteCommands as $cmd) {
            exec($cmd . " 2>&1", $cmdOutput, $cmdReturnVar);
            if ($cmdReturnVar !== 0) {
                Logger::log("Command warning: $cmd");
                Logger::log("Output: " . implode("\n", $cmdOutput));
            }
            sleep(1);
        }

        exec("/usr/local/hestia/bin/v-rebuild-user $escapedCurrentUser 'yes' 2>/dev/null");

        self::cleanupDomainTraces($domain);

        Logger::log("Creating domain for target user");
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedTargetUser $escapedDomain 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::log("Standard domain creation failed: " . implode("\n", $output));

            Logger::log("Trying alternative domain creation method");
            $escapedProxyTemplate = escapeshellarg($proxyTemplate);
            exec("/usr/local/hestia/bin/v-add-web-domain $escapedTargetUser $escapedDomain 'default' 'no' '' '' '' '' '' '' $escapedProxyTemplate 2>&1", $outputAlt, $returnVarAlt);

            if ($returnVarAlt !== 0) {
                Logger::log("Alternative method failed: " . implode("\n", $outputAlt));
                Logger::log("Using low-level approach");
                self::createDomainLowLevel($domain, $targetUser, $proxyTemplate, $ssl);
            }
        }

        exec("/usr/local/hestia/bin/v-list-web-domain $escapedTargetUser $escapedDomain 2>/dev/null", $checkOutput, $checkReturnVar);
        if ($checkReturnVar !== 0) {
            Logger::log("ERROR: Domain not created for target user after all attempts");
            exec("rm -rf " . escapeshellarg($tempDir));
            return $domain;
        }

        Logger::log("Domain successfully created for target user");

        $newWebRoot = "/home/$targetUser/web/$domain/public_html";
        $escapedNewWebRoot = escapeshellarg($newWebRoot);

        exec("rm -rf $escapedNewWebRoot/* 2>/dev/null");
        exec("rm -rf $escapedNewWebRoot/.[!.]* 2>/dev/null");

        if (is_dir("$tempDir/public_html")) {
            Logger::log("Restoring website content");
            exec("cp -a " . escapeshellarg("$tempDir/public_html/*") . " $escapedNewWebRoot/ 2>/dev/null");
            exec("cp -a " . escapeshellarg("$tempDir/public_html/.[!.]*") . " $escapedNewWebRoot/ 2>/dev/null");
        }

        $escapedWebDomainPath = escapeshellarg("/home/$targetUser/web/$domain");
        exec("chown -R $escapedTargetUser:$escapedTargetUser $escapedWebDomainPath");
        exec("find $escapedNewWebRoot -type d -exec chmod 755 {} \\;");
        exec("find $escapedNewWebRoot -type f -exec chmod 644 {} \\;");

        if (!empty($proxyTemplate)) {
            Logger::log("Setting proxy template: $proxyTemplate");
            $escapedProxyTemplate = escapeshellarg($proxyTemplate);
            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $escapedTargetUser $escapedDomain $escapedProxyTemplate 2>&1", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to set proxy template: " . implode("\n", $output));
            }
        }

        if ($hasSsl) {
            $newSslDir = "/home/$targetUser/conf/web/$domain/ssl";
            if (!is_dir($newSslDir)) {
                mkdir($newSslDir, 0755, true);
            }

            $escapedNewSslDir = escapeshellarg($newSslDir);
            exec("cp -a " . escapeshellarg("$tempDir/ssl/*") . " $escapedNewSslDir/ 2>/dev/null");
            exec("chown -R $escapedTargetUser:$escapedTargetUser $escapedNewSslDir");

            Logger::log("Enabling SSL for domain");
            exec("/usr/local/hestia/bin/v-add-web-domain-ssl $escapedTargetUser $escapedDomain 2>&1", $output, $returnVar);

            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to enable SSL: " . implode("\n", $output));
            }
        }

        exec("rm -rf " . escapeshellarg($tempDir));

        exec("/usr/local/hestia/bin/v-rebuild-web-domains $escapedTargetUser 2>&1", $output, $returnVar);

        Logger::log("Domain transfer completed: $domain");
        return $domain;
    }

    /**
     * Create domain using low-level approach
     */
    private static function createDomainLowLevel($domain, $user, $proxyTemplate = 'tc-nginx-only', $ssl = 'no')
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

        $escapedUser = escapeshellarg($user);
        $escapedWebRoot = escapeshellarg($webRoot);
        exec("chown -R $escapedUser:$escapedUser $escapedWebRoot");

        exec("hostname -I | awk '{print $1}'", $ipAddress);
        $ip = trim($ipAddress[0] ?? '');
        if (empty($ip)) {
            exec("curl -s ifconfig.me", $ipAddress);
            $ip = trim($ipAddress[0] ?? '127.0.0.1');
        }

        $webConfFile = "/usr/local/hestia/data/users/$user/web.conf";

        if (file_exists($webConfFile)) {
            $currentDate = date("Y-m-d H:i:s");

            $domainEntry = "WEB_DOMAIN='$domain' IP='$ip' IP6='' WEB_TPL='default' BACKEND='php-fpm' PROXY='$proxyTemplate' PROXY_EXT='html,htm,php' SSL='$ssl' SSL_HOME='same' STATS='' STATS_AUTH='' STATS_USER='' U_DISK='0' U_BANDWIDTH='0' SUSPENDED='no' TIME='$currentDate' DATE='$currentDate'\n";

            file_put_contents($webConfFile, $domainEntry, FILE_APPEND);
            Logger::log("Manually added domain entry to web.conf");

            $confDir = "/home/$user/conf/web/$domain";
            if (!is_dir($confDir)) {
                mkdir($confDir, 0755, true);
                exec("chown $escapedUser:$escapedUser " . escapeshellarg($confDir));
            }

            exec("/usr/local/hestia/bin/v-rebuild-web-domains $escapedUser");

            $escapedDomain = escapeshellarg($domain);
            exec("/usr/local/hestia/bin/v-list-web-domain $escapedUser $escapedDomain 2>/dev/null", $output, $returnVar);

            if ($returnVar === 0) {
                Logger::log("Low-level domain addition successful");
            } else {
                Logger::log("Warning: Low-level domain addition might have issues");

                Logger::log("Restarting all services");
                exec("/usr/local/hestia/bin/v-restart-service 'nginx'");
                exec("/usr/local/hestia/bin/v-restart-service 'apache2'");
                exec("/usr/local/hestia/bin/v-restart-service 'hestia'");

                exec("/usr/local/hestia/bin/v-rebuild-user $escapedUser 'yes'");
            }
        } else {
            Logger::log("Error: web.conf not found for user $user");
        }

        return $domain;
    }

    /**
     * Cleanup all domain traces from system
     */
    private static function cleanupDomainTraces($domain)
    {
        Logger::log("Cleaning up all traces of domain: $domain");

        $escapedDomain = escapeshellarg($domain);

        exec("find /home -name $escapedDomain -type d 2>/dev/null", $domainDirs);
        foreach ($domainDirs as $dir) {
            if (!empty($dir)) {
                exec("rm -rf " . escapeshellarg($dir));
                Logger::log("Removed directory: $dir");
            }
        }

        exec("find /usr/local/hestia/data/users/*/web.conf -type f -exec grep -l $escapedDomain {} \\;", $webConfigs);
        foreach ($webConfigs as $configFile) {
            if (!empty($configFile) && file_exists($configFile)) {
                $escapedConfigFile = escapeshellarg($configFile);
                exec("grep -v $escapedDomain $escapedConfigFile > $escapedConfigFile.tmp && mv $escapedConfigFile.tmp $escapedConfigFile");
                Logger::log("Cleaned domain from config: $configFile");
            }
        }

        exec("find /usr/local/hestia/data/users/*/dns.conf -type f -exec grep -l $escapedDomain {} \\;", $dnsConfigs);
        foreach ($dnsConfigs as $configFile) {
            if (!empty($configFile) && file_exists($configFile)) {
                $escapedConfigFile = escapeshellarg($configFile);
                exec("grep -v $escapedDomain $escapedConfigFile > $escapedConfigFile.tmp && mv $escapedConfigFile.tmp $escapedConfigFile");
                Logger::log("Cleaned domain from config: $configFile");
            }
        }

        exec("find /usr/local/hestia/data/users/*/mail.conf -type f -exec grep -l $escapedDomain {} \\;", $mailConfigs);
        foreach ($mailConfigs as $configFile) {
            if (!empty($configFile) && file_exists($configFile)) {
                $escapedConfigFile = escapeshellarg($configFile);
                exec("grep -v $escapedDomain $escapedConfigFile > $escapedConfigFile.tmp && mv $escapedConfigFile.tmp $escapedConfigFile");
                Logger::log("Cleaned domain from config: $configFile");
            }
        }

        exec("find /etc/nginx/conf.d -name '*$domain*' -type f 2>/dev/null", $nginxConfigs);
        foreach ($nginxConfigs as $config) {
            if (!empty($config)) {
                exec("rm -f " . escapeshellarg($config));
                Logger::log("Removed nginx config: $config");
            }
        }

        exec("find /etc/apache2/sites-enabled -name '*$domain*' -type f 2>/dev/null", $apacheConfigs);
        foreach ($apacheConfigs as $config) {
            if (!empty($config)) {
                exec("rm -f " . escapeshellarg($config));
                Logger::log("Removed apache config: $config");
            }
        }

        exec("/usr/local/hestia/bin/v-restart-service 'nginx' 'quiet'");
        exec("/usr/local/hestia/bin/v-restart-service 'apache2' 'quiet'");

        Logger::log("Domain cleanup completed");
    }
}