<?php

require_once 'Logger.php';
require_once 'NginxManager.php';

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
            NginxManager::addRestrictions($domain, $user);
            return $domain;
        }

        Logger::log("Checking for domain ownership: $domain");
        exec("/usr/local/hestia/bin/v-search-domain-owner $domain 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $existingUser = trim(implode("\n", $output));

            if (!empty($existingUser) && $existingUser !== $user) {
                Logger::log("Domain exists with different owner: $existingUser");
                $domain = self::transferDomain($domain, $existingUser, $user);
                NginxManager::addRestrictions($domain, $user);
                return $domain;
            }
        }

        Logger::log("Domain does not exist - creating new: $domain");

        self::cleanupDomainTraces($domain);

        Logger::log("Adding domain to user $user: $domain");
        $output = [];
        exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Domain created successfully: $domain");

            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to apply proxy template for $domain");
            }

            NginxManager::addRestrictions($domain, $user);
            return $domain;
        }

        Logger::log("Standard domain creation failed: " . implode("\n", $output));

        Logger::log("Trying alternative domain creation method");
        exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only' 2>&1", $outputAlt, $returnVarAlt);

        if ($returnVarAlt === 0) {
            Logger::log("Alternative domain creation successful");
            NginxManager::addRestrictions($domain, $user);
            return $domain;
        }

        Logger::log("Alternative method failed: " . implode("\n", $outputAlt));
        Logger::log("Using low-level approach");

        $domain = self::createDomainLowLevel($domain, $user);
        NginxManager::addRestrictions($domain, $user);

        return $domain;
    }

    /**
     * Transfer domain from one user to another
     */
    private static function transferDomain($domain, $currentUser, $targetUser)
    {
        Logger::log("Transferring domain $domain from $currentUser to $targetUser");

        $timestamp = time();
        $tempDir = "/tmp/domain_transfer_$timestamp";
        mkdir($tempDir, 0755, true);

        $webRoot = "/home/$currentUser/web/$domain/public_html";
        if (is_dir($webRoot)) {
            exec("cp -a $webRoot $tempDir/public_html");
            Logger::log("Content copied to temporary directory");
        } else {
            mkdir("$tempDir/public_html", 0755, true);
            Logger::log("Created empty public_html directory");
        }

        $proxyTemplate = 'tc-nginx-only';
        $ssl = 'no';

        exec("/usr/local/hestia/bin/v-list-web-domain $currentUser $domain json", $domainInfo, $returnVar);
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
            exec("mkdir -p $tempDir/ssl");
            exec("cp -a $sslDir/* $tempDir/ssl/ 2>/dev/null");
            $hasSsl = true;
            Logger::log("SSL certificates copied");
        }

        Logger::log("Suspending domain for current user");
        exec("/usr/local/hestia/bin/v-suspend-web-domain $currentUser $domain 2>/dev/null");
        sleep(2);

        Logger::log("Deleting domain from current user");
        $deleteCommands = [
            "/usr/local/hestia/bin/v-delete-web-domain $currentUser $domain --force",
            "/usr/local/hestia/bin/v-delete-dns-domain $currentUser $domain --force",
            "/usr/local/hestia/bin/v-delete-mail-domain $currentUser $domain --force"
        ];

        foreach ($deleteCommands as $cmd) {
            exec($cmd . " 2>&1", $cmdOutput, $cmdReturnVar);
            if ($cmdReturnVar !== 0) {
                Logger::log("Command warning: $cmd");
                Logger::log("Output: " . implode("\n", $cmdOutput));
            }
            sleep(1);
        }

        exec("/usr/local/hestia/bin/v-rebuild-user $currentUser 'yes' 2>/dev/null");

        self::cleanupDomainTraces($domain);

        Logger::log("Creating domain for target user");
        exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::log("Standard domain creation failed: " . implode("\n", $output));

            Logger::log("Trying alternative domain creation method");
            exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 'default' 'no' '' '' '' '' '' '' '$proxyTemplate' 2>&1", $outputAlt, $returnVarAlt);

            if ($returnVarAlt !== 0) {
                Logger::log("Alternative method failed: " . implode("\n", $outputAlt));
                Logger::log("Using low-level approach");
                self::createDomainLowLevel($domain, $targetUser, $proxyTemplate, $ssl);
            }
        }

        exec("/usr/local/hestia/bin/v-list-web-domain $targetUser $domain 2>/dev/null", $checkOutput, $checkReturnVar);
        if ($checkReturnVar !== 0) {
            Logger::log("ERROR: Domain not created for target user after all attempts");
            exec("rm -rf $tempDir");
            return $domain;
        }

        Logger::log("Domain successfully created for target user");

        $newWebRoot = "/home/$targetUser/web/$domain/public_html";

        exec("rm -rf $newWebRoot/* 2>/dev/null");
        exec("rm -rf $newWebRoot/.[!.]* 2>/dev/null");

        if (is_dir("$tempDir/public_html")) {
            Logger::log("Restoring website content");
            exec("cp -a $tempDir/public_html/* $newWebRoot/ 2>/dev/null");
            exec("cp -a $tempDir/public_html/.[!.]* $newWebRoot/ 2>/dev/null");
        }

        exec("chown -R $targetUser:$targetUser /home/$targetUser/web/$domain");
        exec("find $newWebRoot -type d -exec chmod 755 {} \\;");
        exec("find $newWebRoot -type f -exec chmod 644 {} \\;");

        if (!empty($proxyTemplate)) {
            Logger::log("Setting proxy template: $proxyTemplate");
            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $targetUser $domain $proxyTemplate 2>&1", $output, $returnVar);
            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to set proxy template: " . implode("\n", $output));
            }
        }

        if ($hasSsl) {
            $newSslDir = "/home/$targetUser/conf/web/$domain/ssl";
            if (!is_dir($newSslDir)) {
                mkdir($newSslDir, 0755, true);
            }

            exec("cp -a $tempDir/ssl/* $newSslDir/ 2>/dev/null");
            exec("chown -R $targetUser:$targetUser $newSslDir");

            Logger::log("Enabling SSL for domain");
            exec("/usr/local/hestia/bin/v-add-web-domain-ssl $targetUser $domain 2>&1", $output, $returnVar);

            if ($returnVar !== 0) {
                Logger::log("Warning: Failed to enable SSL: " . implode("\n", $output));
            }
        }

        exec("rm -rf $tempDir");

        exec("/usr/local/hestia/bin/v-rebuild-web-domains $targetUser 2>&1", $output, $returnVar);

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

            $domainEntry = "WEB_DOMAIN='$domain' IP='$ip' IP6='' WEB_TPL='default' BACKEND='php-fpm' PROXY='$proxyTemplate' PROXY_EXT='html,htm,php' SSL='$ssl' SSL_HOME='same' STATS='' STATS_AUTH='' STATS_USER='' U_DISK='0' U_BANDWIDTH='0' SUSPENDED='no' TIME='$currentDate' DATE='$currentDate'\n";

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

                Logger::log("Restarting all services");
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

    /**
     * Cleanup all domain traces from system
     */
    private static function cleanupDomainTraces($domain)
    {
        Logger::log("Cleaning up all traces of domain: $domain");

        exec("find /home -name '$domain' -type d 2>/dev/null", $domainDirs);
        foreach ($domainDirs as $dir) {
            exec("rm -rf '$dir'");
            Logger::log("Removed directory: $dir");
        }

        $escapedDomain = escapeshellarg($domain);

        exec("find /usr/local/hestia/data/users/*/web.conf -type f -exec grep -l $escapedDomain {} \\;", $webConfigs);
        foreach ($webConfigs as $configFile) {
            exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
            Logger::log("Cleaned domain from config: $configFile");
        }

        exec("find /usr/local/hestia/data/users/*/dns.conf -type f -exec grep -l $escapedDomain {} \\;", $dnsConfigs);
        foreach ($dnsConfigs as $configFile) {
            exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
            Logger::log("Cleaned domain from config: $configFile");
        }

        exec("find /usr/local/hestia/data/users/*/mail.conf -type f -exec grep -l $escapedDomain {} \\;", $mailConfigs);
        foreach ($mailConfigs as $configFile) {
            exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
            Logger::log("Cleaned domain from config: $configFile");
        }

        exec("find /etc/nginx/conf.d -name '*$domain*' -type f 2>/dev/null", $nginxConfigs);
        foreach ($nginxConfigs as $config) {
            exec("rm -f '$config'");
            Logger::log("Removed nginx config: $config");
        }

        exec("find /etc/apache2/sites-enabled -name '*$domain*' -type f 2>/dev/null", $apacheConfigs);
        foreach ($apacheConfigs as $config) {
            exec("rm -f '$config'");
            Logger::log("Removed apache config: $config");
        }

        exec("/usr/local/hestia/bin/v-restart-service 'nginx' 'quiet'");
        exec("/usr/local/hestia/bin/v-restart-service 'apache2' 'quiet'");

        Logger::log("Domain cleanup completed");
    }
}