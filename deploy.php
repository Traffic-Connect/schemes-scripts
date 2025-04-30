<?php

// Configuration
define('API_URL', 'https://manager.tcnct.com/api');
define('API_TOKEN', 'g4jpvNf9xRn9V4d5dXyA4D8S585Sy9LS');
define('TEMP_DIR', '/root/schemas/temp');
define('LOG_FILE', '/root/schemas/schema_deploy.log');
define('STATE_FILE', '/root/schemas/state.json');

// Get server IP (external IP address)
function getServerIp()
{
    // Method 1: Using hostname -I command
    $ip = trim(shell_exec("hostname -I | awk '{print $1}'"));

    // Method 2: If method 1 fails, try getting external IP
    if (empty($ip) || $ip === '127.0.0.1') {
        $ip = trim(shell_exec("curl -s ifconfig.me"));
    }

    // Method 3: Alternative method using ip command
    if (empty($ip) || $ip === '127.0.0.1') {
        $ip = trim(shell_exec("ip route get 1 | awk '{print $7;exit}'"));
    }

    return $ip;
}

$serverIp = getServerIp();

// Create necessary directories
if (!file_exists(dirname(STATE_FILE))) {
    mkdir(dirname(STATE_FILE), 0755, true);
}
if (!file_exists(TEMP_DIR)) {
    mkdir(TEMP_DIR, 0755, true);
}

/**
 * Logging function
 */
function log_message($message)
{
    $date = date('Y-m-d H:i:s');
    $logMessage = "[$date] $message" . PHP_EOL;
    file_put_contents(LOG_FILE, $logMessage, FILE_APPEND);
    echo $logMessage;
}

/**
 * Error handling
 */
function handle_error($message)
{
    log_message("ERROR: $message");
    exit(1);
}
/**
 * Load previous state
 */
function load_state() {
    if (file_exists(STATE_FILE)) {
        $content = file_get_contents(STATE_FILE);
        return json_decode($content, true) ?? [];
    }
    return [];
}

/**
 * Save current state
 */
function save_state($state) {
    file_put_contents(STATE_FILE, json_encode($state, JSON_PRETTY_PRINT));
}

/**
 * Get schemas for this server
 */
function get_schemas() {
    global $serverIp;

    log_message("Fetching schemas for server IP: $serverIp");

    // Debug: Show what IP we're using
    if ($serverIp === '127.0.0.1' || empty($serverIp)) {
        log_message("WARNING: Got localhost IP or empty IP, this may cause issues");
    }

    $url = API_URL . '/schemas/by-server?' . http_build_query(['server_address' => $serverIp]);

    log_message("API URL: $url");

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-API-Token: ' . API_TOKEN,
            'Accept: application/json'
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_VERBOSE => true,
        CURLOPT_HEADER => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    curl_close($ch);

    log_message("HTTP Status: $httpCode");
    if ($httpCode !== 200) {
        log_message("ERROR: HTTP request failed with status $httpCode");
        log_message("Response body: $body");
        handle_error("Failed to fetch schemas");
    }

    $data = json_decode($body, true);
    if ($data === null) {
        log_message("ERROR: Invalid JSON received");
        log_message("Raw response: $body");
        handle_error("Invalid JSON data received from API");
    }

    return $data;
}

/**
 * Create schema user in HestiaCP
 */
function create_schema_user($schemaName) {
    // Convert Cyrillic or other non-ASCII characters to transliterated version if possible
    $transliterated = transliterate($schemaName);

    // Convert transliterated name to a valid username, always replacing spaces with underscores
    $userName = strtolower(preg_replace('/\s+/', '_', $transliterated));
    // Remove any remaining non-alphanumeric characters except underscores
    $userName = preg_replace('/[^a-z0-9_]/', '', $userName);

    // If resulting username is empty or too short (could happen with only non-Latin chars)
    if (strlen($userName) < 2) {
        // Use a hash of the original name instead
        $userName = 'schema_' . substr(md5($schemaName), 0, 10);
    } else {
        $userName = "schema_$userName";
    }

    // Ensure username is not too long (max 32 chars for Linux)
    if (strlen($userName) > 32) {
        $userName = substr($userName, 0, 32);
    }

    // Remove multiple consecutive underscores
    $userName = preg_replace('/_+/', '_', $userName);

    log_message("Checking user: $userName for schema: $schemaName");

    // Check if user already exists
    exec("/usr/local/hestia/bin/v-list-user $userName", $output, $returnVar);
    if ($returnVar === 0) {
        log_message("User $userName already exists");
        return $userName;
    }
    // Generate a random password
    $password = bin2hex(random_bytes(8));

    // Create a safe display name (for email and command)
    $displayName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $transliterated);
    $displayName = preg_replace('/_+/', '_', $displayName); // Remove multiple consecutive underscores

    // Create a valid email address
    $email = "schema@" . md5($schemaName) . ".com";

    // Escape single quotes in the display name for the shell command
    $escapedDisplayName = str_replace("'", "'\\''", $displayName);

    $cmd = "/usr/local/hestia/bin/v-add-user $userName $password $email default Schema '$escapedDisplayName'";

    log_message("Executing: $cmd");
    exec($cmd, $output, $returnVar);

    if ($returnVar !== 0) {
        log_message("Error output: " . implode("\n", $output));
        handle_error("Failed to create user $userName");
    }

    log_message("User $userName created successfully");
    return $userName;
}

/**
 * Transliterate non-Latin characters to Latin equivalents
 */
function transliterate($text) {
    // Cyrillic transliteration map
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

    // Try transliteration using the map
    $transliterated = strtr($text, $cyrillic);

    // If PHP's transliteration is available (PHP >= 5.4), use it as a fallback
    if (function_exists('transliterator_transliterate')) {
        // If input still has non-ASCII characters, use PHP's transliterator
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
 * Create domain in HestiaCP
 */
function create_domain($domain, $user) {
    // If domain starts with www, use the non-www version for Hestia
    $originalDomain = $domain;
    $isWwwDomain = (strpos($domain, 'www.') === 0);
    if ($isWwwDomain) {
        $domain = substr($domain, 4); // Remove www. prefix for Hestia
        log_message("Domain $originalDomain starts with www, will create $domain in Hestia instead");
    }

    log_message("Creating domain: $domain for user: $user");

    // Check if domain already exists for this user
    $output = [];
    exec("/usr/local/hestia/bin/v-list-web-domain $user $domain 2>/dev/null", $output, $returnVar);
    if ($returnVar === 0) {
        log_message("Domain $domain already exists for user $user");
        return $domain; // Return the actual domain that was created in Hestia
    }

    // Check if domain exists for another user
    $output = [];
    exec("/usr/local/hestia/bin/v-search-domain-owner $domain 2>/dev/null", $output, $returnVar);

    if ($returnVar === 0 && !empty($output)) {
        $existingUser = trim(implode("\n", $output));

        if (!empty($existingUser) && $existingUser !== $user) {
            log_message("Domain $domain exists for another user: $existingUser. Deleting old domain.");

            // Instead of renaming (which can fail due to length restrictions), just delete the old domain
            exec("/usr/local/hestia/bin/v-delete-web-domain $existingUser $domain", $output, $returnVar);
            if ($returnVar !== 0) {
                log_message("WARNING: Failed to delete domain $domain from user $existingUser");
                // Try forcing delete by removing DNS and mail as well
                exec("/usr/local/hestia/bin/v-delete-dns-domain $existingUser $domain", $output, $returnVar);
                exec("/usr/local/hestia/bin/v-delete-mail-domain $existingUser $domain", $output, $returnVar);
                exec("/usr/local/hestia/bin/v-delete-web-domain $existingUser $domain", $output, $returnVar);

                if ($returnVar !== 0) {
                    handle_error("Failed to delete domain $domain from user $existingUser");
                }
            }
            log_message("Deleted domain $domain from user $existingUser");
        }
    }

    // Create domain
    log_message("Executing: v-add-web-domain $user $domain");
    $output = [];
    exec("/usr/local/hestia/bin/v-add-web-domain $user $domain 2>&1", $output, $returnVar);
    if ($returnVar !== 0) {
        log_message("Error output: " . implode("\n", $output));
        handle_error("Failed to create domain $domain");
    }

    // Set proxy template
    log_message("Setting proxy template tc-nginx-only for domain $domain");
    exec("/usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only", $output, $returnVar);
    if ($returnVar !== 0) {
        log_message("WARNING: Failed to set proxy template for $domain");
    } else {
        log_message("Proxy template tc-nginx-only set successfully for $domain");
    }

    // Create SSL certificate - disabled for now to avoid hanging
    log_message("Skipping Let's Encrypt SSL for now to avoid hanging");

    log_message("Domain $domain created successfully for user $user");

    return $domain; // Return the actual domain that was created in Hestia
}

/**
 * Check if a domain has www subdomain
 */
function has_www_domain($domains, $domain) {
    // If this is already a www domain, return false
    if (strpos($domain, 'www.') === 0) {
        return false;
    }

    // Check if www. version of the domain exists
    $wwwDomain = "www.$domain";
    return in_array($wwwDomain, $domains);
}

/**
 * Prepares redirect data for a site
 */
function prepare_redirects_data($site, $allDomains, $isWwwDomain = false) {
    $domain = $site['domain'];
    $redirectsData = [
        'redirects' => []
    ];

    // Add www mirror for domains with www prefix or those that have www versions
    if ($isWwwDomain) {
        // This was originally a www domain in the API, add mirror
        log_message("Setting www mirror for domain that came with www prefix: $domain");
        $redirectsData['mirror'] = "www";
    } else if (strpos($domain, 'www.') !== 0) {
        // Not a www domain, check if there's a www version in the domains list
        $wwwDomain = "www.$domain";
        if (in_array($wwwDomain, $allDomains)) {
            log_message("Found www version for $domain, adding www mirror to redirects.json");
            $redirectsData['mirror'] = "www";
        }
    }

    // Process redirects - make them relative
    if (!empty($site['redirects'])) {
        foreach ($site['redirects'] as $redirect) {
            // Store redirects as relative paths
            $fromUrl = $redirect['from_url'];
            $toUrl = $redirect['to_url'];
            $redirectsData['redirects'][$fromUrl] = $toUrl;
        }
        log_message("Added " . count($site['redirects']) . " relative redirects from API");
    }

    return $redirectsData;
}

/**
 * Check if redirects need to be updated by comparing with existing redirects.json
 * Returns true if update is needed, false otherwise
 */
function redirects_need_update($webRoot, $redirectsData) {
    $redirectsJsonPath = "$webRoot/redirects.json";

    // If redirects.json doesn't exist, update is needed
    if (!file_exists($redirectsJsonPath)) {
        log_message("redirects.json doesn't exist, update needed");
        return true;
    }

    // Read existing redirects.json
    $existingContent = file_get_contents($redirectsJsonPath);
    $existingData = json_decode($existingContent, true);

    // If existing data is invalid, update is needed
    if ($existingData === null) {
        log_message("Existing redirects.json is invalid, update needed");
        return true;
    }

    // Compare mirror settings
    $existingMirror = $existingData['mirror'] ?? null;
    $newMirror = $redirectsData['mirror'] ?? null;

    if ($existingMirror !== $newMirror) {
        log_message("Mirror setting changed (old: $existingMirror, new: $newMirror), update needed");
        return true;
    }

    // Compare redirects
    $existingRedirects = $existingData['redirects'] ?? [];
    $newRedirects = $redirectsData['redirects'] ?? [];

    // Check if the number of redirects is different
    if (count($existingRedirects) !== count($newRedirects)) {
        log_message("Number of redirects changed (old: " . count($existingRedirects) .
            ", new: " . count($newRedirects) . "), update needed");
        return true;
    }

    // Check if any redirect has changed
    foreach ($newRedirects as $fromUrl => $toUrl) {
        if (!isset($existingRedirects[$fromUrl]) || $existingRedirects[$fromUrl] !== $toUrl) {
            log_message("Redirect rule changed for '$fromUrl', update needed");
            return true;
        }
    }

    // Check if any old redirect is missing in the new set
    foreach ($existingRedirects as $fromUrl => $toUrl) {
        if (!isset($newRedirects[$fromUrl])) {
            log_message("Redirect rule removed for '$fromUrl', update needed");
            return true;
        }
    }

    log_message("No changes detected in redirects.json, no update needed");
    return false;
}

/**
 * Update redirects.json file
 */
function update_redirects($hestiaDomain, $schemaUser, $redirectsData) {
    $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

    // Ensure the web root directory exists
    if (!is_dir($webRoot)) {
        log_message("Web root directory doesn't exist for $hestiaDomain, creating it");
        mkdir($webRoot, 0755, true);
    }

    // Check if redirects need to be updated
    if (redirects_need_update($webRoot, $redirectsData)) {
        // Write the new redirects.json file
        $redirectsJson = json_encode($redirectsData, JSON_PRETTY_PRINT);
        $result = file_put_contents("$webRoot/redirects.json", $redirectsJson);

        if ($result === false) {
            log_message("WARNING: Failed to write redirects.json for $hestiaDomain");
            return false;
        }

        // Set proper permissions
        exec("chown $schemaUser:$schemaUser $webRoot/redirects.json");
        exec("chmod 644 $webRoot/redirects.json");

        log_message("Updated redirects.json for $hestiaDomain with " . count($redirectsData['redirects']) . " redirects" .
            (isset($redirectsData['mirror']) ? " and mirror: " . $redirectsData['mirror'] : ""));
        return true;
    }

    return false;
}

/**
 * Check if a site needs deployment
 */
function needs_deployment($domain, $user) {
    $webRoot = "/home/$user/web/$domain/public_html";

    // Если директория не существует, требуется развертывание
    if (!is_dir($webRoot)) {
        log_message("No public_html directory: $domain");
        return true;
    }

    // Проверка наличия index.html или index.php
    $hasIndexFile = file_exists("$webRoot/index.html") || file_exists("$webRoot/index.php");
    if (!$hasIndexFile) {
        log_message("No index file found: $domain");
        return true;
    }

    // Проверка на заглушку "Site is being updated"
    if (file_exists("$webRoot/index.html")) {
        $content = file_get_contents("$webRoot/index.html");
        if (strpos($content, "Site is being updated") !== false) {
            log_message("Placeholder page found: $domain");
            return true;
        }
    }

    // Проверка количества файлов в директории
    $files = scandir($webRoot);
    $contentCount = count($files) - 2; // Вычитаем . и ..

    // Если только 1 файл (возможно, только index.html) и этот файл меньше 1KB,
    // вероятно, это заглушка или пустой сайт
    if ($contentCount <= 1 && file_exists("$webRoot/index.html")) {
        $fileSize = filesize("$webRoot/index.html");
        if ($fileSize < 1024) { // Меньше 1KB
            log_message("Only one small file found: $domain");
            return true;
        }
    }

    // Проверка наличия критических файлов или директорий, которые должны присутствовать
    // на нормальном сайте (например, css, js, images)
    $expectedDirs = ['css', 'js', 'images', 'img', 'assets'];
    $foundExpectedDirs = false;

    foreach ($expectedDirs as $dir) {
        if (is_dir("$webRoot/$dir")) {
            $foundExpectedDirs = true;
            break;
        }
    }

    if (!$foundExpectedDirs && $contentCount < 5) {
        // Если нет ожидаемых директорий и мало файлов, вероятно, сайт неполный
        log_message("Possibly incomplete site (few files, no assets): $domain");
        return true;
    }

    // Сайт выглядит нормально, развертывание не требуется
    return false;
}

/**
 * Download and extract ZIP
 */
function deploy_zip($domain, $zipUrl, $user, $redirectsData) {
    $webRoot = "/home/$user/web/$domain/public_html";

    log_message("Deploying ZIP for domain: $domain (user: $user)");
    log_message("ZIP URL: $zipUrl");

    // Always clear public_html directory before doing anything
    if (is_dir($webRoot)) {
        log_message("Clearing public_html directory for $domain");
        exec("rm -rf $webRoot/*");
        exec("rm -rf $webRoot/.[!.]*"); // Remove hidden files too
    } else {
        // Create web root if not exists
        log_message("Creating web root directory for $domain");
        mkdir($webRoot, 0755, true);
    }

    // Download ZIP
    $zipFile = TEMP_DIR . "/schema.zip";
    $ch = curl_init($zipUrl);
    $fp = fopen($zipFile, 'w');
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 300); // 5 minutes timeout
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    // Check for download errors
    if (!$success || !file_exists($zipFile) || $httpCode != 200) {
        log_message("WARNING: Failed to download ZIP from $zipUrl (HTTP code: $httpCode)");

        // Create an empty index.html file so the site isn't completely empty
        file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");

        // Still create redirects.json even if ZIP download failed
        update_redirects($domain, $user, $redirectsData);

        // Set proper permissions
        exec("chown -R $user:$user $webRoot");

        if (file_exists($zipFile)) {
            unlink($zipFile);
        }

        return; // Exit the function without error to continue with other domains
    }

    // Extract ZIP
    $zip = new ZipArchive;
    if ($zip->open($zipFile) === TRUE) {
        $result = $zip->extractTo($webRoot);
        $zip->close();

        if (!$result) {
            log_message("WARNING: Failed to extract ZIP file for $domain");
            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
        }
    } else {
        log_message("WARNING: Failed to open ZIP file for $domain");
        file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
    }

    // Write redirects.json file
    update_redirects($domain, $user, $redirectsData);

    // Set proper permissions
    exec("chown -R $user:$user $webRoot");
    exec("find $webRoot -type d -exec chmod 755 {} \;");
    exec("find $webRoot -type f -exec chmod 644 {} \;");

    // Clean up
    unlink($zipFile);

    log_message("ZIP deployed successfully for $domain (user: $user)");
}

/**
 * Get domains for a user from HestiaCP
 */
function get_user_domains($user) {
    exec("/usr/local/hestia/bin/v-list-web-domains $user json", $output, $returnVar);
    if ($returnVar === 0) {
        $outputStr = implode("\n", $output);
        $data = json_decode($outputStr, true);
        return array_keys($data);
    }
    return [];
}

/**
 * Compare redirects in state to current redirects
 */
function redirects_changed($previousState, $schemaName, $domain, $site, $allDomains, $isWwwDomain) {
    $stateKey = $schemaName . '_' . $domain . '_redirects';

    // Get current redirects data
    $currentRedirectsData = prepare_redirects_data($site, $allDomains, $isWwwDomain);

    // If no previous state, redirects have changed
    if (!isset($previousState[$stateKey])) {
        log_message("No previous redirects state for $domain, considering changed");
        return true;
    }

    $previousRedirectsData = $previousState[$stateKey];

    // Compare mirror settings
    if (isset($currentRedirectsData['mirror']) !== isset($previousRedirectsData['mirror']) ||
        (isset($currentRedirectsData['mirror']) && isset($previousRedirectsData['mirror']) &&
            $currentRedirectsData['mirror'] !== $previousRedirectsData['mirror'])) {
        log_message("Mirror setting changed for $domain");
        return true;
    }

    // Compare redirects count
    $currentCount = count($currentRedirectsData['redirects'] ?? []);
    $previousCount = count($previousRedirectsData['redirects'] ?? []);

    if ($currentCount !== $previousCount) {
        log_message("Redirects count changed for $domain: $previousCount -> $currentCount");
        return true;
    }

    // Compare each redirect
    foreach ($currentRedirectsData['redirects'] as $fromUrl => $toUrl) {
        if (!isset($previousRedirectsData['redirects'][$fromUrl]) ||
            $previousRedirectsData['redirects'][$fromUrl] !== $toUrl) {
            log_message("Redirect rule changed for $domain: $fromUrl");
            return true;
        }
    }

    // Check for deleted redirects
    foreach ($previousRedirectsData['redirects'] as $fromUrl => $toUrl) {
        if (!isset($currentRedirectsData['redirects'][$fromUrl])) {
            log_message("Redirect rule removed for $domain: $fromUrl");
            return true;
        }
    }

    log_message("No changes in redirects for $domain");
    return false;
}

/**
 * Store current redirects state
 */
function store_redirects_state(&$state, $schemaName, $domain, $redirectsData) {
    $stateKey = $schemaName . '_' . $domain . '_redirects';
    $state[$stateKey] = $redirectsData;
}

/**
 * Main function
 */
function main() {
    log_message("Starting schema deployment process");
    log_message("Server IP: " . $GLOBALS['serverIp']);

    // Load previous state
    $previousState = load_state();

    // Get current schemas
    $schemas = get_schemas();

    if (empty($schemas)) {
        log_message("No schemas found for this server");
        exit(0);
    }

    log_message("Found " . count($schemas) . " schemas for this server");

    // Process each schema
    foreach ($schemas as $schema) {
        $schemaName = $schema['name'];
        $zipUrl = $schema['zip_url'] ?? null;
        $zipUploadedAt = $schema['zip_uploaded_at'] ?? null;

        log_message("Processing schema: $schemaName");

        if (empty($zipUrl)) {
            log_message("No ZIP file for schema: $schemaName");
            continue;
        }

        // Create or get schema user
        $schemaUser = create_schema_user($schemaName);

        // Check if ZIP was updated
        $previousZipDate = $previousState[$schemaName]['zip_uploaded_at'] ?? null;
        $shouldDeploy = empty($previousZipDate) || $previousZipDate !== $zipUploadedAt;

        if ($shouldDeploy) {
            log_message("ZIP file updated for schema: $schemaName (old: $previousZipDate, new: $zipUploadedAt)");
        } else {
            log_message("ZIP file not updated for schema: $schemaName");
        }

        // Get current domains for this schema from API
        $currentDomains = array_column($schema['sites'], 'domain');
        log_message("Schema has " . count($currentDomains) . " domains");

        // Get existing domains for this user from HestiaCP
        $existingDomains = get_user_domains($schemaUser);

        foreach ($existingDomains as $existingDomain) {
            // Convert any www domains from schema to their base domain for comparison
            $domainFound = false;
            foreach ($currentDomains as $schemaDomain) {
                $schemaBaseForm = (strpos($schemaDomain, 'www.') === 0) ? substr($schemaDomain, 4) : $schemaDomain;
                if ($existingDomain === $schemaBaseForm) {
                    $domainFound = true;
                    break;
                }
            }

            if (!$domainFound) {
                // Check if domain exists in another schema on this server
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
                    log_message("Removing domain $existingDomain from user $schemaUser (not in any schema on this server)");
                    exec("/usr/local/hestia/bin/v-delete-web-domain $schemaUser $existingDomain");
                } else {
                    log_message("Domain $existingDomain moved to another schema, will be deleted and recreated");
                }
            }
        }

        // Process each site in the schema
        foreach ($schema['sites'] as $site) {
            $originalDomain = $site['domain'];
            $isWwwDomain = (strpos($originalDomain, 'www.') === 0);

            // Create domain in Hestia (removing www. if needed)
            $hestiaDomain = create_domain($originalDomain, $schemaUser);

            log_message("Processing domain: $originalDomain (Hestia domain: $hestiaDomain)");

            // Prepare redirects data - always get fresh from API
            $redirectsData = prepare_redirects_data($site, $currentDomains, $isWwwDomain);

            // Check if redirects have changed since last run
            $redirectsChanged = redirects_changed($previousState, $schemaName, $hestiaDomain, $site, $currentDomains, $isWwwDomain);

            // Store current redirects in state
            store_redirects_state($previousState, $schemaName, $hestiaDomain, $redirectsData);

            // Check if this is a new domain that never had a deployment
            $domainStateKey = $schemaName . '_' . $hestiaDomain;
            $domainNeverDeployed = empty($previousState[$domainStateKey]);

            // Check if site needs deployment (no files or missing index file)
            $needsDeployment = needs_deployment($hestiaDomain, $schemaUser);

            // Always update redirects if they have changed
            if ($redirectsChanged) {
                log_message("Redirects have changed for $hestiaDomain, updating redirects.json");
                $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

                // Create web root if it doesn't exist
                if (!is_dir($webRoot)) {
                    mkdir($webRoot, 0755, true);
                    exec("chown $schemaUser:$schemaUser $webRoot");
                }

                // Update redirects.json
                update_redirects($hestiaDomain, $schemaUser, $redirectsData);
            }

            // Deploy ZIP if:
            // 1. The ZIP was updated OR
            // 2. This domain never had a deployment OR
            // 3. Site has no files and needs deployment
            if ($shouldDeploy || $domainNeverDeployed || $needsDeployment) {
                if ($domainNeverDeployed) {
                    log_message("Domain $hestiaDomain never had a deployment, forcing deployment");
                } else if ($needsDeployment) {
                    log_message("Domain $hestiaDomain needs deployment (missing files or incomplete), forcing deployment");
                }

                deploy_zip($hestiaDomain, $zipUrl, $schemaUser, $redirectsData);

                // Record that this domain has been deployed
                $previousState[$domainStateKey] = date('Y-m-d H:i:s');
            }
        }

        // Update state for this schema
        $previousState[$schemaName] = [
            'zip_uploaded_at' => $zipUploadedAt,
            'last_processed' => date('Y-m-d H:i:s')
        ];
    }

    // Save current state
    save_state($previousState);

    log_message("Schema deployment process completed");
}
// Run main function
main();