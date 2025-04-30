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

function create_domain($domain, $user) {
    $originalDomain = $domain;
    $isWwwDomain = (strpos($domain, 'www.') === 0);
    if ($isWwwDomain) {
        $domain = substr($domain, 4);
    }

    exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
    if ($returnVar === 0) {
        log_message("Domain exists: $domain");
        return $domain;
    }

    exec("/usr/local/hestia/bin/v-search-domain-owner $domain 2>/dev/null", $output, $returnVar);

    if ($returnVar === 0 && !empty($output)) {
        $existingUser = trim(implode("\n", $output));

        if (!empty($existingUser) && $existingUser !== $user) {
            log_message("Domain owner change: $domain");
            exec("/usr/local/hestia/bin/v-delete-web-domain $existingUser $domain", $output, $returnVar);
            if ($returnVar !== 0) {
                exec("/usr/local/hestia/bin/v-delete-dns-domain $existingUser $domain 2>/dev/null", $output, $returnVar);
                exec("/usr/local/hestia/bin/v-delete-mail-domain $existingUser $domain 2>/dev/null", $output, $returnVar);
                exec("/usr/local/hestia/bin/v-delete-web-domain $existingUser $domain 2>/dev/null", $output, $returnVar);
            }
            sleep(1);
        }
    }

    $output = [];
    exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);

    if ($returnVar !== 0) {
        log_message("Retry domain creation: $domain");
        sleep(2);
        $output = [];
        exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);
    }

    if ($returnVar === 0) {
        exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only", $output, $returnVar);
        log_message("Domain created: $domain");
    } else {
        log_message("Failed to create: $domain");
    }

    return $domain;
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