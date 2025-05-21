<?php

define('API_URL', 'https://manager.tcnct.com/api');
define('API_TOKEN', 'g4jpvNf9xRn9V4d5dXyA4D8S585Sy9LS');
define('TEMP_DIR', '/root/schemas/temp');
define('LOG_FILE', '/root/schemas/schema_deploy.log');
define('STATE_FILE', '/root/schemas/state.json');

function getServerIp() {
    $ip = trim(shell_exec("hostname -I | awk '{print $1}'"));
    if (empty($ip) || $ip === '127.0.0.1') {
        $ip = trim(shell_exec("curl -s ifconfig.me"));
    }
    if (empty($ip) || $ip === '127.0.0.1') {
        $ip = trim(shell_exec("ip route get 1 | awk '{print $7;exit}'"));
    }
    return $ip;
}

$serverIp = getServerIp();

if (!file_exists(dirname(STATE_FILE))) {
    mkdir(dirname(STATE_FILE), 0755, true);
}
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
}

function log_message($message) {
    $date = date('Y-m-d H:i:s');
    $logMessage = "[$date] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
    echo $logMessage;
}

function handle_error($message) {
    log_message("ERROR: $message");
    exit(1);
}

function load_state() {
    if (file_exists(STATE_FILE)) {
        $content = file_get_contents(STATE_FILE);
        return json_decode($content, true) ?? [];
    }
    return [];
}

function save_state($state) {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

function get_schemas() {
    global $serverIp;
    $url = API_URL . '/schemas/by-server?' . http_build_query(['server_address' => $serverIp]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Token: ' . API_TOKEN,
            'Accept: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        handle_error("Failed to fetch schemas (HTTP $httpCode)");
    }

    $data = json_decode($response, true);
    if ($data === null) {
        handle_error("Invalid JSON from API");
    }

    return $data;
}

function create_schema_user($schemaName) {
    $transliterated = transliterate($schemaName);
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
        log_message("User exists: $userName");
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
        handle_error("Failed to create user $userName");
    }

    log_message("User created: $userName");
    return $userName;
}

function transliterate($text) {
    $cyrillic = array(
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ё' => 'e',
        'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm',
        'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u',
        'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '',
        'ы' => 'y', 'ь' => '', 'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D', 'Е' => 'E', 'Ё' => 'E',
        'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I', 'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M',
        'Н' => 'N', 'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T', 'У' => 'U',
        'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch', 'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '',
        'Ы' => 'Y', 'Ь' => '', 'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya'
    );

    $transliterated = strtr($text, $cyrillic);

    if (function_exists('transliterator_transliterate')) {
        if (preg_match('/[^\x20-\x7E]/', $transliterated)) {
            $fallback = transliterator_transliterate('Any-Latin; Latin-ASCII', $text);
            if ($fallback) {
                $transliterated = $fallback;
            }
        }
    }

    return $transliterated;
}

/**
 * Создает домен для указанного пользователя или переносит его от другого пользователя
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь, которому должен принадлежать домен
 * @return string Имя домена
 */
/**
 * Создает домен для указанного пользователя или переносит его от другого пользователя
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь, которому должен принадлежать домен
 * @return string Имя домена
 */
function create_domain($domain, $user) {
    $originalDomain = $domain;
    $isWwwDomain = (strpos($domain, 'www.') === 0);
    if ($isWwwDomain) {
        $domain = substr($domain, 4);
    }

    // Шаг 1: Проверяем, существует ли домен уже у нужного пользователя
    exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
    if ($returnVar === 0) {
        log_message("Domain already exists for user $user: $domain");

        // Добавляем настройки запрета доступа к существующему домену
        add_nginx_restrictions($domain, $user);

        return $domain;
    }

    // Шаг 2: Проверяем, принадлежит ли домен другому пользователю
    log_message("Checking for domain ownership: $domain");
    exec("/usr/local/hestia/bin/v-search-domain-owner $domain 2>/dev/null", $output, $returnVar);

    if ($returnVar === 0 && !empty($output)) {
        $existingUser = trim(implode("\n", $output));

        if (!empty($existingUser) && $existingUser !== $user) {
            log_message("Domain exists with different owner: $existingUser");

            // Шаг 3: Домен существует у другого пользователя - переносим
            $domain = transfer_domain($domain, $existingUser, $user);

            // Добавляем настройки запрета доступа к перенесенному домену
            add_nginx_restrictions($domain, $user);

            return $domain;
        }
    }

    // Шаг 4: Домен не существует - создаем для указанного пользователя
    log_message("Domain does not exist - creating new: $domain");

    // Шаг 4.1: Удаляем все возможные остатки домена в системе
    cleanup_domain_traces($domain);

    // Шаг 4.2: Создаем домен у пользователя
    log_message("Adding domain to user $user: $domain");
    $output = [];
    exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);

    if ($returnVar === 0) {
        log_message("Domain created successfully: $domain");

        // Устанавливаем шаблон прокси
        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only", $output, $returnVar);
        if ($returnVar !== 0) {
            log_message("Warning: Failed to apply proxy template for $domain");
        }

        // Добавляем настройки запрета доступа к новому домену
        add_nginx_restrictions($domain, $user);

        return $domain;
    }

    // Шаг 5: Стандартный метод не сработал - используем альтернативные подходы
    log_message("Standard domain creation failed: " . implode("\n", $output));

    // Шаг 5.1: Альтернативный метод создания с указанием дополнительных параметров
    log_message("Trying alternative domain creation method");
    exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 'default' 'no' '' '' '' '' '' '' 'tc-nginx-only' 2>&1", $outputAlt, $returnVarAlt);

    if ($returnVarAlt === 0) {
        log_message("Alternative domain creation successful");

        // Добавляем настройки запрета доступа к домену, созданному альтернативным методом
        add_nginx_restrictions($domain, $user);

        return $domain;
    }

    // Шаг 5.2: Альтернативный метод не сработал - используем низкоуровневый подход
    log_message("Alternative method failed: " . implode("\n", $outputAlt));
    log_message("Using low-level approach");

    $domain = create_domain_low_level($domain, $user);

    // Добавляем настройки запрета доступа к домену, созданному низкоуровневым методом
    add_nginx_restrictions($domain, $user);

    return $domain;
}

/**
 * Добавляет ограничения доступа в Nginx конфигурацию домена
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь
 * @return bool Успешность операции
 */
/**
 * Добавляет ограничения доступа в Nginx конфигурацию домена
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь
 * @return bool Успешность операции
 */
/**
 * Добавляет ограничения доступа и настройки подмены в Nginx конфигурацию домена
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь
 * @return bool Успешность операции
 */
function add_nginx_restrictions($domain, $user) {
    log_message("Adding Nginx access restrictions and filters for $domain");

    $nginxConfPath = "/home/$user/conf/web/$domain/nginx.conf";

    // Создаем директорию конфигурации, если она не существует
    $confDir = dirname($nginxConfPath);
    if (!is_dir($confDir)) {
        mkdir($confDir, 0755, true);
        exec("chown $user:$user $confDir");
    }

    // Формируем содержимое для добавления в конфигурацию
    $configContent = "";

    // Добавляем директивы подмены
    $configContent .= "# Замена переменных в шаблонах\n";
    $configContent .= "sub_filter '%domain%' '\$host';\n";
    $configContent .= "sub_filter_once off;\n";
    $configContent .= "sub_filter_types text/html text/css;\n\n";

    // Добавляем правила блокировки
    $configContent .= "# Запрет прямого доступа к static/*.html\n";
    $configContent .= "location ~ ^/static/.*\\.html$ {\n    deny all;\n}\n\n";
    $configContent .= "# Запрет прямого доступа к redirects.json\n";
    $configContent .= "location = /redirects.json {\n    deny all;\n}\n";

    // Если файл конфигурации существует
    if (file_exists($nginxConfPath)) {
        $nginxConf = file_get_contents($nginxConfPath);

        // Проверяем наличие существующих директив
        $hasSubFilter = (strpos($nginxConf, "sub_filter '%domain%'") !== false);
        $hasStaticRule = (strpos($nginxConf, "location ~ ^/static/.*") !== false);
        $hasRedirectsRule = (strpos($nginxConf, "location = /redirects.json") !== false);

        if ($hasSubFilter && $hasStaticRule && $hasRedirectsRule) {
            log_message("All required Nginx settings already exist for $domain");
            return true;
        }

        // Подготавливаем контент для добавления
        $contentToAdd = "";

        if (!$hasSubFilter) {
            $contentToAdd .= "# Замена переменных в шаблонах\n";
            $contentToAdd .= "sub_filter '%domain%' '\$host';\n";
            $contentToAdd .= "sub_filter_once off;\n";
            $contentToAdd .= "sub_filter_types text/html text/css;\n\n";
        }

        if (!$hasStaticRule) {
            $contentToAdd .= "# Запрет прямого доступа к static/*.html\n";
            $contentToAdd .= "location ~ ^/static/.*\\.html$ {\n    deny all;\n}\n\n";
        }

        if (!$hasRedirectsRule) {
            $contentToAdd .= "# Запрет прямого доступа к redirects.json\n";
            $contentToAdd .= "location = /redirects.json {\n    deny all;\n}\n";
        }

        if (!empty($contentToAdd)) {
            // Добавляем настройки в файл
            file_put_contents($nginxConfPath, $nginxConf . "\n" . $contentToAdd);
            log_message("Added missing Nginx settings to $domain");
        }
    } else {
        // Создаем новый файл с требуемыми настройками
        file_put_contents($nginxConfPath, $configContent);
        log_message("Created new Nginx configuration file for $domain");
    }

    // Устанавливаем права на файл
    exec("chown $user:$user $nginxConfPath");
    exec("chmod 644 $nginxConfPath");

    // Проверяем синтаксис Nginx перед перезагрузкой
    exec("nginx -t 2>&1", $testOutput, $testReturnVar);
    if ($testReturnVar !== 0) {
        log_message("Error in Nginx configuration: " . implode("\n", $testOutput));

        // В случае ошибки, создаем упрощенную конфигурацию без комментариев
        $simpleConfig = "sub_filter '%domain%' '\$host';\n";
        $simpleConfig .= "sub_filter_once off;\n";
        $simpleConfig .= "sub_filter_types text/html text/css;\n\n";
        $simpleConfig .= "location ~ ^/static/.*\\.html$ {\n    deny all;\n}\n\n";
        $simpleConfig .= "location = /redirects.json {\n    deny all;\n}\n";

        file_put_contents($nginxConfPath, $simpleConfig);
        exec("chown $user:$user $nginxConfPath");
        exec("chmod 644 $nginxConfPath");

        log_message("Created simplified Nginx configuration for $domain after error");
    }

    // Перезагружаем Nginx
    exec("/usr/local/hestia/bin/v-restart-service 'nginx' 'quiet'", $output, $returnVar);
    if ($returnVar === 0) {
        log_message("Nginx restarted successfully after updating configuration for $domain");
        return true;
    } else {
        log_message("Warning: Failed to restart Nginx after updating configuration for $domain");
        return false;
    }
}

/**
 * Переносит домен от одного пользователя к другому в Hestia
 *
 * @param string $domain Доменное имя
 * @param string $currentUser Текущий владелец домена
 * @param string $targetUser Целевой пользователь для переноса
 * @return string Имя домена
 */
function transfer_domain($domain, $currentUser, $targetUser) {
    log_message("Transferring domain $domain from $currentUser to $targetUser");

    // Шаг 1: Сохраняем содержимое домена
    $timestamp = time();
    $tempDir = "/tmp/domain_transfer_$timestamp";
    mkdir($tempDir, 0755, true);

    $webRoot = "/home/$currentUser/web/$domain/public_html";
    if (is_dir($webRoot)) {
        exec("cp -a $webRoot $tempDir/public_html");
        log_message("Content copied to temporary directory");
    } else {
        mkdir("$tempDir/public_html", 0755, true);
        log_message("Created empty public_html directory");
    }

    // Шаг 2: Получаем текущие настройки домена
    $proxyTemplate = 'tc-nginx-only'; // значение по умолчанию
    $ssl = 'no'; // значение по умолчанию

    exec("/usr/local/hestia/bin/v-list-web-domain $currentUser $domain json", $domainInfo, $returnVar);
    if ($returnVar === 0) {
        $domainInfoJson = implode("\n", $domainInfo);
        $domainSettings = json_decode($domainInfoJson, true);

        if (!empty($domainSettings[$domain])) {
            $domainSettings = $domainSettings[$domain];
            $proxyTemplate = $domainSettings['PROXY'] ?? 'tc-nginx-only';
            $ssl = $domainSettings['SSL'] ?? 'no';
            log_message("Retrieved domain settings: PROXY=$proxyTemplate, SSL=$ssl");
        } else {
            log_message("Failed to parse domain settings JSON, using defaults");
        }
    } else {
        log_message("Failed to get domain settings, using defaults");
    }

    // Шаг 3: Сохраняем SSL-сертификаты, если они есть
    $sslDir = "/home/$currentUser/conf/web/$domain/ssl";
    $hasSsl = false;

    if ($ssl === 'yes' && is_dir($sslDir)) {
        exec("mkdir -p $tempDir/ssl");
        exec("cp -a $sslDir/* $tempDir/ssl/ 2>/dev/null");
        $hasSsl = true;
        log_message("SSL certificates copied");
    }

    // Шаг 4: Удаляем домен у текущего пользователя
    log_message("Suspending domain for current user");
    exec("/usr/local/hestia/bin/v-suspend-web-domain $currentUser $domain 2>/dev/null");
    sleep(2);

    log_message("Deleting domain from current user");
    $deleteCommands = [
        "/usr/local/hestia/bin/v-delete-web-domain $currentUser $domain --force",
        "/usr/local/hestia/bin/v-delete-dns-domain $currentUser $domain --force",
        "/usr/local/hestia/bin/v-delete-mail-domain $currentUser $domain --force"
    ];

    foreach ($deleteCommands as $cmd) {
        exec($cmd . " 2>&1", $cmdOutput, $cmdReturnVar);
        if ($cmdReturnVar !== 0) {
            log_message("Command warning: $cmd");
            log_message("Output: " . implode("\n", $cmdOutput));
        }
        sleep(1);
    }

    // Шаг 5: Перестраиваем конфигурацию пользователя
    exec("/usr/local/hestia/bin/v-rebuild-user $currentUser 'yes' 2>/dev/null");

    // Шаг 6: Очищаем все следы домена в системе
    cleanup_domain_traces($domain);

    // Шаг 7: Создаем домен у целевого пользователя стандартным методом
    log_message("Creating domain for target user");
    exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 2>&1", $output, $returnVar);

    if ($returnVar !== 0) {
        log_message("Standard domain creation failed: " . implode("\n", $output));

        // Шаг 7.1: Пробуем альтернативный метод с указанием шаблона
        log_message("Trying alternative domain creation method");
        exec("/usr/local/hestia/bin/v-add-web-domain $targetUser $domain 'default' 'no' '' '' '' '' '' '' '$proxyTemplate' 2>&1", $outputAlt, $returnVarAlt);

        if ($returnVarAlt !== 0) {
            log_message("Alternative method failed: " . implode("\n", $outputAlt));

            // Шаг 7.2: Используем низкоуровневый подход
            log_message("Using low-level approach");
            create_domain_low_level($domain, $targetUser, $proxyTemplate, $ssl);
        }
    }

    // Шаг 8: Проверяем, что домен создан у целевого пользователя
    exec("/usr/local/hestia/bin/v-list-web-domain $targetUser $domain 2>/dev/null", $checkOutput, $checkReturnVar);
    if ($checkReturnVar !== 0) {
        log_message("ERROR: Domain not created for target user after all attempts");
        exec("rm -rf $tempDir");
        return $domain; // Возвращаем домен в любом случае
    }

    log_message("Domain successfully created for target user");

    // Шаг 9: Восстанавливаем содержимое сайта
    $newWebRoot = "/home/$targetUser/web/$domain/public_html";

    exec("rm -rf $newWebRoot/* 2>/dev/null");
    exec("rm -rf $newWebRoot/.[!.]* 2>/dev/null");

    if (is_dir("$tempDir/public_html")) {
        log_message("Restoring website content");
        exec("cp -a $tempDir/public_html/* $newWebRoot/ 2>/dev/null");
        exec("cp -a $tempDir/public_html/.[!.]* $newWebRoot/ 2>/dev/null");
    }

    // Шаг 10: Устанавливаем права
    exec("chown -R $targetUser:$targetUser /home/$targetUser/web/$domain");
    exec("find $newWebRoot -type d -exec chmod 755 {} \\;");
    exec("find $newWebRoot -type f -exec chmod 644 {} \\;");

    // Шаг 11: Устанавливаем шаблон прокси
    if (!empty($proxyTemplate)) {
        log_message("Setting proxy template: $proxyTemplate");
        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $targetUser $domain $proxyTemplate 2>&1", $output, $returnVar);
        if ($returnVar !== 0) {
            log_message("Warning: Failed to set proxy template: " . implode("\n", $output));
        }
    }

    // Шаг 12: Восстанавливаем SSL-сертификаты, если они были
    if ($hasSsl) {
        $newSslDir = "/home/$targetUser/conf/web/$domain/ssl";
        if (!is_dir($newSslDir)) {
            mkdir($newSslDir, 0755, true);
        }

        exec("cp -a $tempDir/ssl/* $newSslDir/ 2>/dev/null");
        exec("chown -R $targetUser:$targetUser $newSslDir");

        log_message("Enabling SSL for domain");
        exec("/usr/local/hestia/bin/v-add-web-domain-ssl $targetUser $domain 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            log_message("Warning: Failed to enable SSL: " . implode("\n", $output));
        }
    }

    // Шаг 13: Очистка
    exec("rm -rf $tempDir");

    // Шаг 14: Перестраиваем веб-конфигурацию пользователя для применения всех изменений
    exec("/usr/local/hestia/bin/v-rebuild-web-domains $targetUser 2>&1", $output, $returnVar);

    log_message("Domain transfer completed: $domain");
    return $domain;
}

/**
 * Создает домен низкоуровневым методом с прямым изменением конфигурационных файлов
 *
 * @param string $domain Доменное имя
 * @param string $user Пользователь
 * @param string $proxyTemplate Шаблон прокси (по умолчанию tc-nginx-only)
 * @param string $ssl Флаг SSL (по умолчанию no)
 * @return string Имя домена
 */
function create_domain_low_level($domain, $user, $proxyTemplate = 'tc-nginx-only', $ssl = 'no') {
    log_message("Creating domain using low-level approach: $domain for $user");

    // Шаг 1: Подготавливаем директории
    $webRoot = "/home/$user/web/$domain";
    $publicHtml = "$webRoot/public_html";

    if (!is_dir($webRoot)) {
        mkdir($webRoot, 0755, true);
    }

    if (!is_dir($publicHtml)) {
        mkdir($publicHtml, 0755, true);
    }

    // Шаг 2: Устанавливаем права
    exec("chown -R $user:$user $webRoot");

    // Шаг 3: Получаем IP сервера
    exec("hostname -I | awk '{print $1}'", $ipAddress);
    $ip = trim($ipAddress[0] ?? '');
    if (empty($ip)) {
        exec("curl -s ifconfig.me", $ipAddress);
        $ip = trim($ipAddress[0] ?? '127.0.0.1');
    }

    // Шаг 4: Добавляем запись в web.conf
    $webConfFile = "/usr/local/hestia/data/users/$user/web.conf";

    if (file_exists($webConfFile)) {
        $currentDate = date("Y-m-d H:i:s");

        // Полная запись домена
        $domainEntry = "WEB_DOMAIN='$domain' IP='$ip' IP6='' WEB_TPL='default' BACKEND='php-fpm' PROXY='$proxyTemplate' PROXY_EXT='html,htm,php' SSL='$ssl' SSL_HOME='same' STATS='' STATS_AUTH='' STATS_USER='' U_DISK='0' U_BANDWIDTH='0' SUSPENDED='no' TIME='$currentDate' DATE='$currentDate'\n";

        file_put_contents($webConfFile, $domainEntry, FILE_APPEND);
        log_message("Manually added domain entry to web.conf");

        // Шаг 5: Создаем конфигурационные директории
        $confDir = "/home/$user/conf/web/$domain";
        if (!is_dir($confDir)) {
            mkdir($confDir, 0755, true);
            exec("chown $user:$user $confDir");
        }

        // Шаг 6: Перестраиваем конфигурацию пользователя
        exec("/usr/local/hestia/bin/v-rebuild-web-domains $user");

        // Шаг 7: Проверяем, что домен создан
        exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);

        if ($returnVar === 0) {
            log_message("Low-level domain addition successful");
        } else {
            log_message("Warning: Low-level domain addition might have issues");

            // Шаг 8: Пробуем перезапустить все сервисы
            log_message("Restarting all services");
            exec("/usr/local/hestia/bin/v-restart-service 'nginx'");
            exec("/usr/local/hestia/bin/v-restart-service 'apache2'");
            exec("/usr/local/hestia/bin/v-restart-service 'hestia'");

            // Шаг 9: Еще раз перестраиваем конфигурацию пользователя
            exec("/usr/local/hestia/bin/v-rebuild-user $user 'yes'");
        }
    } else {
        log_message("Error: web.conf not found for user $user");
    }

    return $domain;
}

/**
 * Очищает все следы домена в системе
 *
 * @param string $domain Доменное имя
 */
function cleanup_domain_traces($domain) {
    log_message("Cleaning up all traces of domain: $domain");

    // Шаг 1: Удаляем все физические директории домена
    exec("find /home -name '$domain' -type d 2>/dev/null", $domainDirs);
    foreach ($domainDirs as $dir) {
        exec("rm -rf '$dir'");
        log_message("Removed directory: $dir");
    }

    // Шаг 2: Удаляем все конфигурационные файлы домена
    $escapedDomain = escapeshellarg($domain);

    // Шаг 2.1: Очистка web.conf
    exec("find /usr/local/hestia/data/users/*/web.conf -type f -exec grep -l $escapedDomain {} \\;", $webConfigs);
    foreach ($webConfigs as $configFile) {
        exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
        log_message("Cleaned domain from config: $configFile");
    }

    // Шаг 2.2: Очистка dns.conf
    exec("find /usr/local/hestia/data/users/*/dns.conf -type f -exec grep -l $escapedDomain {} \\;", $dnsConfigs);
    foreach ($dnsConfigs as $configFile) {
        exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
        log_message("Cleaned domain from config: $configFile");
    }

    // Шаг 2.3: Очистка mail.conf
    exec("find /usr/local/hestia/data/users/*/mail.conf -type f -exec grep -l $escapedDomain {} \\;", $mailConfigs);
    foreach ($mailConfigs as $configFile) {
        exec("grep -v $escapedDomain $configFile > $configFile.tmp && mv $configFile.tmp $configFile");
        log_message("Cleaned domain from config: $configFile");
    }

    // Шаг 3: Удаляем все конфигурационные файлы веб-сервера
    exec("find /etc/nginx/conf.d -name '*$domain*' -type f 2>/dev/null", $nginxConfigs);
    foreach ($nginxConfigs as $config) {
        exec("rm -f '$config'");
        log_message("Removed nginx config: $config");
    }

    exec("find /etc/apache2/sites-enabled -name '*$domain*' -type f 2>/dev/null", $apacheConfigs);
    foreach ($apacheConfigs as $config) {
        exec("rm -f '$config'");
        log_message("Removed apache config: $config");
    }

    // Шаг 4: Перезапуск сервисов для применения изменений
    exec("/usr/local/hestia/bin/v-restart-service 'nginx' 'quiet'");
    exec("/usr/local/hestia/bin/v-restart-service 'apache2' 'quiet'");

    log_message("Domain cleanup completed");
}

function prepare_redirects_data($site, $allDomains, $isWwwDomain = false) {
    $domain = $site['domain'];
    $redirectsData = [
        'redirects' => []
    ];

    if ($isWwwDomain) {
        $redirectsData['mirror'] = "www";
    } else if (strpos($domain, 'www.') !== 0) {
        $wwwDomain = "www.$domain";
        if (in_array($wwwDomain, $allDomains)) {
            $redirectsData['mirror'] = "www";
        }
    }

    if (!empty($site['redirects'])) {
        foreach ($site['redirects'] as $redirect) {
            $fromUrl = $redirect['from_url'];
            $toUrl = $redirect['to_url'];
            $redirectsData['redirects'][$fromUrl] = $toUrl;
        }
    }

    return $redirectsData;
}

function redirects_need_update($webRoot, $redirectsData) {
    $redirectsJsonPath = "$webRoot/redirects.json";

    if (!file_exists($redirectsJsonPath)) {
        return true;
    }

    $existingContent = file_get_contents($redirectsJsonPath);
    $existingData = json_decode($existingContent, true);

    if ($existingData === null) {
        return true;
    }

    $existingMirror = $existingData['mirror'] ?? null;
    $newMirror = $redirectsData['mirror'] ?? null;

    if ($existingMirror !== $newMirror) {
        return true;
    }

    $existingRedirects = $existingData['redirects'] ?? [];
    $newRedirects = $redirectsData['redirects'] ?? [];

    if (count($existingRedirects) !== count($newRedirects)) {
        return true;
    }

    foreach ($newRedirects as $fromUrl => $toUrl) {
        if (!isset($existingRedirects[$fromUrl]) || $existingRedirects[$fromUrl] !== $toUrl) {
            return true;
        }
    }

    foreach ($existingRedirects as $fromUrl => $toUrl) {
        if (!isset($newRedirects[$fromUrl])) {
            return true;
        }
    }

    return false;
}

function update_redirects($hestiaDomain, $schemaUser, $redirectsData) {
    $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

    if (!is_dir($webRoot)) {
        mkdir($webRoot, 0755, true);
    }

    if (redirects_need_update($webRoot, $redirectsData)) {
        $redirectsJson = json_encode($redirectsData, JSON_PRETTY_PRINT);
        $result = file_put_contents("$webRoot/redirects.json", $redirectsJson);

        if ($result === false) {
            log_message("Failed to write redirects: $hestiaDomain");
            return false;
        }

        exec("chown $schemaUser:$schemaUser $webRoot/redirects.json");
        exec("chmod 644 $webRoot/redirects.json");

        log_message("Redirects updated: $hestiaDomain");
        return true;
    }

    return false;
}

function needs_deployment($domain, $user) {
    $webRoot = "/home/$user/web/$domain/public_html";

    if (!is_dir($webRoot)) {
        return true;
    }

    if (!file_exists("$webRoot/index.html") && !file_exists("$webRoot/index.php")) {
        return true;
    }

    if (file_exists("$webRoot/index.html")) {
        $content = file_get_contents("$webRoot/index.html");
        if (strpos($content, "Site is being updated") !== false) {
            return true;
        }
    }

    $files = scandir($webRoot);
    $contentCount = count($files) - 2;

    if ($contentCount <= 1 && file_exists("$webRoot/index.html")) {
        $fileSize = filesize("$webRoot/index.html");
        if ($fileSize < 1024) {
            return true;
        }
    }

    return false;
}

function deploy_zip($domain, $zipUrl, $user, $redirectsData) {
    $webRoot = "/home/$user/web/$domain/public_html";
    $backupDir = TEMP_DIR . "/$domain-backup-" . time();
    $hasBackup = false;

    log_message("Deploying: $domain");

    if (is_dir($webRoot)) {
        exec("cp -r $webRoot $backupDir");
        $hasBackup = is_dir($backupDir);

        exec("rm -rf $webRoot/*");
        exec("rm -rf $webRoot/.[!.]*");
    } else {
        mkdir($webRoot, 0755, true);
    }

    $zipFile = TEMP_DIR . "/$domain-" . time() . ".zip";
    $ch = curl_init($zipUrl);
    $fp = fopen($zipFile, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $fileSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD);
    curl_close($ch);
    fclose($fp);

    $downloadSuccess = ($success && file_exists($zipFile) && $httpCode == 200 && $fileSize > 0);

    if (!$downloadSuccess) {
        log_message("Download failed: $domain");

        if ($hasBackup) {
            exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
            exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
            log_message("Restored backup: $domain");
        } else {
            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
        }

        update_redirects($domain, $user, $redirectsData);
        exec("chown -R $user:$user $webRoot");

        if (file_exists($zipFile)) {
            unlink($zipFile);
        }
        if ($hasBackup) {
            exec("rm -rf $backupDir");
        }
        return;
    }

    $zip = new ZipArchive;
    $extractionSuccess = false;
    $openResult = $zip->open($zipFile);

    if ($openResult === TRUE) {
        $zipEntryCount = $zip->numFiles;

        if ($zipEntryCount > 0) {
            $extractResult = $zip->extractTo($webRoot);
            $zip->close();

            if ($extractResult) {
                $extractedFiles = scandir($webRoot);
                $extractedFileCount = count($extractedFiles) - 2;

                if ($extractedFileCount > 0) {
                    if (file_exists("$webRoot/index.html") || file_exists("$webRoot/index.php")) {
                        $extractionSuccess = true;
                        log_message("Extraction successful: $domain");
                    } else {
                        log_message("No index file: $domain");
                        if ($hasBackup) {
                            if (file_exists("$backupDir/index.html")) {
                                copy("$backupDir/index.html", "$webRoot/index.html");
                            } elseif (file_exists("$backupDir/index.php")) {
                                copy("$backupDir/index.php", "$webRoot/index.php");
                            } else {
                                file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
                            }
                        } else {
                            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
                        }
                    }
                } else {
                    log_message("Empty extraction: $domain");
                    if ($hasBackup) {
                        exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
                        exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
                    } else {
                        file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
                    }
                }
            } else {
                log_message("Extraction failed: $domain");
                if ($hasBackup) {
                    exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
                    exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
                } else {
                    file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
                }
            }
        } else {
            log_message("Empty ZIP: $domain");
            $zip->close();

            if ($hasBackup) {
                exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
                exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
            } else {
                file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
            }
        }
    } else {
        log_message("ZIP open failed: $domain");

        if ($hasBackup) {
            exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
            exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
        } else {
            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
        }
    }

    update_redirects($domain, $user, $redirectsData);

    exec("chown -R $user:$user $webRoot");
    exec("find $webRoot -type d -exec chmod 755 {} \\;");
    exec("find $webRoot -type f -exec chmod 644 {} \\;");

    if (file_exists($zipFile)) {
        unlink($zipFile);
    }
    if ($hasBackup) {
        exec("rm -rf $backupDir");
    }

    if ($extractionSuccess) {
        log_message("Deployment successful: $domain");
    } else {
        log_message("Deployment issues: $domain");
    }
}

function get_user_domains($user) {
    exec("/usr/local/hestia/bin/v-list-web-domains $user json", $output, $returnVar);
    if ($returnVar === 0) {
        $outputStr = implode("\n", $output);
        $data = json_decode($outputStr, true);
        return array_keys($data);
    }
    return [];
}

function redirects_changed($previousState, $schemaName, $domain, $site, $allDomains, $isWwwDomain) {
    $stateKey = $schemaName . '_' . $domain . '_redirects';
    $currentRedirectsData = prepare_redirects_data($site, $allDomains, $isWwwDomain);

    if (!isset($previousState[$stateKey])) {
        return true;
    }

    $previousRedirectsData = $previousState[$stateKey];

    if (isset($currentRedirectsData['mirror']) !== isset($previousRedirectsData['mirror']) ||
        (isset($currentRedirectsData['mirror']) && isset($previousRedirectsData['mirror']) &&
            $currentRedirectsData['mirror'] !== $previousRedirectsData['mirror'])) {
        return true;
    }

    $currentCount = count($currentRedirectsData['redirects'] ?? []);
    $previousCount = count($previousRedirectsData['redirects'] ?? []);

    if ($currentCount !== $previousCount) {
        return true;
    }

    foreach ($currentRedirectsData['redirects'] as $fromUrl => $toUrl) {
        if (!isset($previousRedirectsData['redirects'][$fromUrl]) ||
            $previousRedirectsData['redirects'][$fromUrl] !== $toUrl) {
            return true;
        }
    }

    foreach ($previousRedirectsData['redirects'] as $fromUrl => $toUrl) {
        if (!isset($currentRedirectsData['redirects'][$fromUrl])) {
            return true;
        }
    }

    return false;
}

function store_redirects_state(&$state, $schemaName, $domain, $redirectsData) {
    $stateKey = $schemaName . '_' . $domain . '_redirects';
    $state[$stateKey] = $redirectsData;
}

function main() {
    log_message("Starting deployment");

    $previousState = load_state();
    $schemas = get_schemas();

    if (empty($schemas)) {
        log_message("No schemas found");
        exit(0);
    }

    log_message("Found " . count($schemas) . " schemas");

    foreach ($schemas as $schema) {
        $schemaName = $schema['name'];
        $zipUrl = $schema['zip_url'] ?? null;
        $zipUploadedAt = $schema['zip_uploaded_at'] ?? null;

        log_message("Processing: $schemaName");

        if (empty($zipUrl)) {
            log_message("No ZIP: $schemaName");
            continue;
        }

        $schemaUser = create_schema_user($schemaName);

        $previousZipDate = $previousState[$schemaName]['zip_uploaded_at'] ?? null;
        $shouldDeploy = empty($previousZipDate) || $previousZipDate !== $zipUploadedAt;

        if ($shouldDeploy) {
            log_message("ZIP updated: $schemaName");
        }

        $currentDomains = array_column($schema['sites'], 'domain');
        $existingDomains = get_user_domains($schemaUser);

        foreach ($existingDomains as $existingDomain) {
            $domainFound = false;
            foreach ($currentDomains as $schemaDomain) {
                $schemaBaseForm = (strpos($schemaDomain, 'www.') === 0) ? substr($schemaDomain, 4) : $schemaDomain;
                if ($existingDomain === $schemaBaseForm) {
                    $domainFound = true;
                    break;
                }
            }

            if (!$domainFound) {
                $domainInOtherSchema = false;
                foreach ($schemas as $otherSchema) {
                    if ($otherSchema['name'] !== $schemaName) {
                        foreach (array_column($otherSchema['sites'], 'domain') as $otherSchemaDomain) {
                            $otherSchemaBaseForm = (strpos($otherSchemaDomain, 'www.') === 0) ?
                                substr($otherSchemaDomain, 4) : $otherSchemaDomain;
                            if ($existingDomain === $otherSchemaBaseForm) {
                                $domainInOtherSchema = true;
                                break 2;
                            }
                        }
                    }
                }

                if (!$domainInOtherSchema) {
                    log_message("Removing domain: $existingDomain");
                    exec("/usr/local/hestia/bin/v-delete-web-domain $schemaUser $existingDomain");
                }
            }
        }

        foreach ($schema['sites'] as $site) {
            $originalDomain = $site['domain'];
            $isWwwDomain = (strpos($originalDomain, 'www.') === 0);

            $hestiaDomain = create_domain($originalDomain, $schemaUser);

            $redirectsData = prepare_redirects_data($site, $currentDomains, $isWwwDomain);
            $redirectsChanged = redirects_changed($previousState, $schemaName, $hestiaDomain, $site, $currentDomains, $isWwwDomain);
            store_redirects_state($previousState, $schemaName, $hestiaDomain, $redirectsData);

            $domainStateKey = $schemaName . '_' . $hestiaDomain;
            $domainNeverDeployed = empty($previousState[$domainStateKey]);
            $needsDeployment = needs_deployment($hestiaDomain, $schemaUser);

            if ($redirectsChanged) {
                $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

                if (!is_dir($webRoot)) {
                    mkdir($webRoot, 0755, true);
                    exec("chown $schemaUser:$schemaUser $webRoot");
                }

                update_redirects($hestiaDomain, $schemaUser, $redirectsData);
            }

            if ($shouldDeploy || $domainNeverDeployed || $needsDeployment) {
                deploy_zip($hestiaDomain, $zipUrl, $schemaUser, $redirectsData);
                $previousState[$domainStateKey] = date('Y-m-d H:i:s');
            }
        }

        $previousState[$schemaName] = [
            'zip_uploaded_at' => $zipUploadedAt,
            'last_processed' => date('Y-m-d H:i:s')
        ];
    }

    save_state($previousState);
    log_message("Deployment completed");
}

main();