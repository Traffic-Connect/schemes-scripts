<?php

require_once __DIR__ . '/Logger.php';

class SimpleDomainTransfer
{
    /**
     * Transfer domain to target user
     */
    public static function transferDomain($domain, $targetUser)
    {
        Logger::log("Starting domain transfer: $domain to $targetUser");

        // Находим текущего владельца
        $currentUser = self::findDomainOwner($domain);
        if (!$currentUser) {
            Logger::log("Domain owner not found: $domain");
            return false;
        }

        if ($currentUser === $targetUser) {
            Logger::log("Domain already belongs to target user");
            return true;
        }

        Logger::log("Found domain owner: $currentUser");

        // Создаем бэкап контента
        $backupDir = self::backupDomainContent($domain, $currentUser);

        // Удаляем домен у старого пользователя
        self::deleteDomain($domain, $currentUser);

        // Создаем домен у нового пользователя
        self::createDomain($domain, $targetUser);

        // Восстанавливаем контент
        if ($backupDir) {
            self::restoreContent($domain, $targetUser, $backupDir);
            self::cleanup($backupDir);
        }

        Logger::log("Domain transfer completed: $domain");
        return true;
    }

    /**
     * Find domain owner
     */
    private static function findDomainOwner($domain)
    {
        $escapedDomain = escapeshellarg($domain);
        exec("/usr/local/hestia/bin/v-search-domain-owner $escapedDomain 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0 && !empty($output)) {
            $owner = trim(implode("\n", $output));
            // Проверяем что это не ошибка
            if (!empty($owner) && !strpos($owner, 'Error:')) {
                return $owner;
            }
        }

        return null;
    }

    /**
     * Backup domain content
     */
    private static function backupDomainContent($domain, $currentUser)
    {
        $webRoot = "/home/$currentUser/web/$domain/public_html";

        if (!is_dir($webRoot)) {
            return null;
        }

        $backupDir = "/tmp/domain_backup_" . time();
        mkdir($backupDir, 0755, true);

        exec("cp -a " . escapeshellarg($webRoot) . " " . escapeshellarg("$backupDir/public_html"));
        Logger::log("Content backed up to: $backupDir");

        return $backupDir;
    }

    /**
     * Delete domain from user
     */
    private static function deleteDomain($domain, $user)
    {
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        Logger::log("Deleting domain $domain from user $user");

        exec("/usr/local/hestia/bin/v-delete-web-domain $escapedUser $escapedDomain --force 2>/dev/null");
        exec("/usr/local/hestia/bin/v-delete-dns-domain $escapedUser $escapedDomain --force 2>/dev/null");
        exec("/usr/local/hestia/bin/v-delete-mail-domain $escapedUser $escapedDomain --force 2>/dev/null");

        sleep(2);
    }

    /**
     * Create domain for user
     */
    private static function createDomain($domain, $user)
    {
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        Logger::log("Creating domain $domain for user $user");

        // Пробуем создать стандартным способом
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedUser $escapedDomain 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Domain created successfully");
            // Устанавливаем proxy template
            exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $escapedUser $escapedDomain tc-nginx-only 2>/dev/null");
            return true;
        }

        Logger::log("Standard creation failed, using manual method");
        return self::createDomainManually($domain, $user);
    }

    /**
     * Create domain manually
     */
    private static function createDomainManually($domain, $user)
    {
        // Создаем папки
        $webRoot = "/home/$user/web/$domain";
        $directories = [
            "$webRoot/public_html",
            "$webRoot/document_errors",
            "$webRoot/cgi-bin",
            "/home/$user/conf/web/$domain"
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }

        // Получаем IP
        exec("hostname -I | awk '{print $1}'", $ipOutput);
        $ip = trim($ipOutput[0] ?? '127.0.0.1');

        // Добавляем в web.conf
        $webConfFile = "/usr/local/hestia/data/users/$user/web.conf";
        if (file_exists($webConfFile)) {
            $content = file_get_contents($webConfFile);
            if (strpos($content, "WEB_DOMAIN='$domain'") === false) {
                $date = date("Y-m-d H:i:s");
                $entry = "WEB_DOMAIN='$domain' IP='$ip' IP6='' WEB_TPL='default' BACKEND='php-fpm' PROXY='tc-nginx-only' PROXY_EXT='html,htm,php' SSL='no' SSL_HOME='same' STATS='' STATS_AUTH='' STATS_USER='' U_DISK='0' U_BANDWIDTH='0' SUSPENDED='no' TIME='$date' DATE='$date'\n";

                file_put_contents($webConfFile, $entry, FILE_APPEND);
                Logger::log("Added domain to web.conf");
            }
        }

        // Устанавливаем права
        $escapedUser = escapeshellarg($user);
        $escapedWebRoot = escapeshellarg($webRoot);
        exec("chown -R $escapedUser:$escapedUser $escapedWebRoot");

        // Перестраиваем конфиги
        exec("/usr/local/hestia/bin/v-rebuild-web-domains $escapedUser 2>/dev/null");

        Logger::log("Domain created manually");
        return true;
    }

    /**
     * Restore content
     */
    private static function restoreContent($domain, $user, $backupDir)
    {
        $webRoot = "/home/$user/web/$domain/public_html";

        if (is_dir("$backupDir/public_html") && is_dir($webRoot)) {
            Logger::log("Restoring content");

            // Очищаем папку
            exec("rm -rf " . escapeshellarg("$webRoot/*") . " 2>/dev/null");
            exec("rm -rf " . escapeshellarg("$webRoot/.[!.]*") . " 2>/dev/null");

            // Копируем контент
            exec("cp -a " . escapeshellarg("$backupDir/public_html/*") . " " . escapeshellarg($webRoot) . "/ 2>/dev/null");
            exec("cp -a " . escapeshellarg("$backupDir/public_html/.[!.]*") . " " . escapeshellarg($webRoot) . "/ 2>/dev/null");

            // Устанавливаем права
            $escapedUser = escapeshellarg($user);
            $escapedWebRoot = escapeshellarg($webRoot);
            exec("chown -R $escapedUser:$escapedUser $escapedWebRoot");

            Logger::log("Content restored");
        }
    }

    /**
     * Cleanup backup
     */
    private static function cleanup($backupDir)
    {
        if ($backupDir && is_dir($backupDir)) {
            exec("rm -rf " . escapeshellarg($backupDir));
            Logger::log("Cleanup completed");
        }
    }
}

