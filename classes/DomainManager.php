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

        // Устанавливаем права
        $escapedUser = escapeshellarg($user);
        $escapedWebRoot = escapeshellarg($webRoot);
        exec("chown -R $escapedUser:$escapedUser $escapedWebRoot");

        // НЕ добавляем записи в web.conf вручную!
        // Пробуем пересоздать домен через Hestia
        $escapedDomain = escapeshellarg($domain);

        // Пробуем альтернативный метод создания
        exec("/usr/local/hestia/bin/v-add-web-domain $escapedUser $escapedDomain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only' 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Alternative domain creation successful: $domain");
        } else {
            Logger::log("Alternative creation also failed: " . implode("\n", $output));
            // Перестраиваем пользователя полностью
            exec("/usr/local/hestia/bin/v-rebuild-user $escapedUser 'yes' 2>/dev/null");
        }

        Logger::log("Domain creation completed: $domain");
    }

    /**
     * Set tc-nginx-only proxy template
     */
    private static function setProxyTemplate($domain, $user)
    {
        $escapedUser = escapeshellarg($user);
        $escapedDomain = escapeshellarg($domain);

        Logger::log("Setting tc-nginx-only proxy template for: $domain");

        // Сначала чистим дублированные записи в web.conf
        self::cleanWebConf($domain, $user);

        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $escapedUser $escapedDomain tc-nginx-only 2>&1", $output, $returnVar);

        if ($returnVar === 0) {
            Logger::log("Proxy template set successfully for: $domain");
        } else {
            Logger::log("Warning: Failed to set proxy template for: $domain");
            Logger::log("Error output: " . implode("\n", $output));
        }
    }

    /**
     * Clean duplicated entries in web.conf
     */
    private static function cleanWebConf($domain, $user)
    {
        $webConfFile = "/usr/local/hestia/data/users/$user/web.conf";

        if (!file_exists($webConfFile)) {
            return;
        }

        $content = file_get_contents($webConfFile);
        $lines = explode("\n", $content);
        $cleanedLines = [];

        foreach ($lines as $line) {
            // Удаляем старые записи с WEB_DOMAIN='domain'
            if (strpos($line, "WEB_DOMAIN='$domain'") !== false) {
                Logger::log("Removing duplicate WEB_DOMAIN entry for: $domain");
                continue;
            }
            $cleanedLines[] = $line;
        }

        file_put_contents($webConfFile, implode("\n", $cleanedLines));
        Logger::log("Cleaned web.conf for domain: $domain");
    }
}