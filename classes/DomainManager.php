<?php

class DomainManager
{
    /**
     * Create domain or transfer if exists, always set tc-nginx-only
     */
    public static function createDomain($domain, $user)
    {
        // Убираем www если есть
        $originalDomain = $domain;
        if (strpos($domain, 'www.') === 0) {
            $domain = substr($domain, 4);
        }

        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        // Проверяем есть ли домен у нашего пользователя
        exec("/usr/local/hestia/bin/v-list-web-domain $escapedUser $escapedDomain 2>/dev/null", $output, $returnVar);
        if ($returnVar === 0) {
            Logger::log("Domain already exists for user $user: $domain");
            // Устанавливаем proxy template на всякий случай
            self::setProxyTemplate($domain, $user);
            return $domain;
        }

        // Ищем владельца домена
        $currentOwner = self::findDomainOwner($domain);

        if ($currentOwner && $currentOwner !== $user) {
            Logger::log("Domain exists with owner: $currentOwner, transferring to $user");
            self::transferDomain($domain, $currentOwner, $user);
        } else {
            Logger::log("Domain does not exist, creating new: $domain");
            self::createNewDomain($domain, $user);
        }

        // Обязательно устанавливаем proxy template
        self::setProxyTemplate($domain, $user);

        return $domain;
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
     * Transfer domain from one user to another
     */
    private static function transferDomain($domain, $fromUser, $toUser)
    {
        Logger::log("Transferring $domain from $fromUser to $toUser");

        $escapedFromUser = escapeshellarg($fromUser);
        $escapedToUser = escapeshellarg($toUser);
        $escapedDomain = escapeshellarg($domain);

        // Бэкапим контент
        $backupDir = "/tmp/transfer_" . time();
        mkdir($backupDir, 0755, true);

        $oldWebRoot = "/home/$fromUser/web/$domain/public_html";
        if (is_dir($oldWebRoot)) {
            exec("cp -a " . escapeshellarg($oldWebRoot) . " " . escapeshellarg("$backupDir/public_html"));
            Logger::log("Content backed up");
        }

        // Удаляем у старого пользователя
        exec("/usr/local/hestia/bin/v-delete-web-domain $escapedFromUser $escapedDomain --force 2>/dev/null");
        sleep(1);

        // Создаем у нового пользователя
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedToUser $escapedDomain 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            Logger::log("Standard creation failed, using manual method");
            self::createDomainManually($domain, $toUser);
        }

        // Восстанавливаем контент
        $newWebRoot = "/home/$toUser/web/$domain/public_html";
        if (is_dir("$backupDir/public_html") && is_dir($newWebRoot)) {
            exec("rm -rf " . escapeshellarg("$newWebRoot/*") . " 2>/dev/null");
            exec("cp -a " . escapeshellarg("$backupDir/public_html/*") . " " . escapeshellarg($newWebRoot) . "/ 2>/dev/null");
            exec("chown -R $escapedToUser:$escapedToUser " . escapeshellarg($newWebRoot));
            Logger::log("Content restored");
        }

        // Убираем бэкап
        exec("rm -rf " . escapeshellarg($backupDir));

        Logger::log("Domain transfer completed: $domain");
    }

    /**
     * Create new domain
     */
    private static function createNewDomain($domain, $user)
    {
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        // Пробуем создать стандартным способом
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedUser $escapedDomain 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Domain created successfully: $domain");
            return;
        }

        Logger::log("Standard creation failed, using manual method");
        self::createDomainManually($domain, $user);
    }

    /**
     * Create domain manually
     */
    private static function createDomainManually($domain, $user)
    {
        Logger::log("Creating domain manually: $domain for $user");

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

        Logger::log("Domain created manually: $domain");
    }

    /**
     * Set tc-nginx-only proxy template
     */
    private static function setProxyTemplate($domain, $user)
    {
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        Logger::log("Setting tc-nginx-only proxy template for: $domain");

        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $escapedUser $escapedDomain tc-nginx-only 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Proxy template set successfully for: $domain");
        } else {
            Logger::log("Warning: Failed to set proxy template for: $domain");
        }
    }
}