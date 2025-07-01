<?php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/Logger.php';
require_once __DIR__ . '/RedirectsManager.php';

class DeploymentManager
{
    /**
     * Check if domain needs deployment
     */
    public static function needsDeployment($domain, $user)
    {
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

    /**
     * Check if site is empty and needs automatic deployment
     * Logic: deploy if we DON'T have both index.php AND static directory
     */
    public static function needsAutomaticDeployment($domain, $user)
    {
        $webRoot = "/home/$user/web/$domain/public_html";

        if (!is_dir($webRoot)) {
            Logger::log("Directory doesn't exist, needs automatic deployment: $domain");
            return true;
        }

        // Проверяем наличие index.php (игнорируем index.html)
        $hasIndexPhp = file_exists("$webRoot/index.php");

        // Проверяем наличие директории static
        $hasStaticDir = is_dir("$webRoot/static");

        Logger::log("Checking $domain: hasIndexPhp=" . ($hasIndexPhp ? 'yes' : 'no') . ", hasStaticDir=" . ($hasStaticDir ? 'yes' : 'no'));

        // Если есть И index.php И папка static - НЕ нужен автоматический деплой
        if ($hasIndexPhp && $hasStaticDir) {
            Logger::log("Site has index.php and static directory, no automatic deployment needed: $domain");
            return false;
        }

        // Во всех остальных случаях - нужен автоматический деплой
        Logger::log("Missing index.php or static directory, needs automatic deployment: $domain");
        return true;
    }

    /**
     * Create PHP config file for the site
     */
    private static function createPhpConfig($webRoot, $originalDomain, $user)
    {
        $configPath = "$webRoot/config.php";

        // Формируем URL с https:// используя оригинальный домен (с www или без)
        $homeUrl = 'https://' . $originalDomain;

        $configContent = "<?php\nreturn array (\n  'home_url' => '$homeUrl',\n);\n";

        if (file_put_contents($configPath, $configContent) !== false) {
            exec("chown $user:$user $configPath");
            chmod($configPath, 0644);
            Logger::log("Created config.php for $originalDomain with URL: $homeUrl");
            return true;
        } else {
            Logger::log("Failed to create config.php for $originalDomain");
            return false;
        }
    }

    /**
     * Replace %domain% placeholder in all site files
     */
    private static function replaceDomainPlaceholder($webRoot, $domain)
    {
        Logger::log("Replacing domain placeholders in: $domain");

        $extensions = ['html', 'css', 'js', 'txt', 'xml'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($webRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $processedFiles = 0;
        $replacedFiles = 0;

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileExtension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

                if (in_array($fileExtension, $extensions)) {
                    $filePath = $file->getRealPath();
                    $processedFiles++;

                    $content = file_get_contents($filePath);

                    if ($content !== false && strpos($content, '%domain%') !== false) {
                        $newContent = str_replace('%domain%', $domain, $content);

                        if (file_put_contents($filePath, $newContent) !== false) {
                            $replacedFiles++;
                            Logger::log("Replaced domain in: " . $file->getFilename());
                        } else {
                            Logger::log("Failed to write file: " . $file->getFilename());
                        }
                    }
                }
            }
        }

        Logger::log("Domain replacement completed for $domain: processed $processedFiles files, replaced in $replacedFiles files");
    }

    /**
     * Download and place Google Search Console verification file
     */
    private static function downloadGoogleVerificationFile($webRoot, $gscFileUrl)
    {
        if (empty($gscFileUrl)) {
            return;
        }

        $gscFileName = basename(parse_url($gscFileUrl, PHP_URL_PATH));

        if (empty($gscFileName)) {
            Logger::log("Invalid GSC file URL: $gscFileUrl");
            return;
        }

        // Проверяем, существует ли уже файл в корне сайта
        $gscFilePath = "$webRoot/$gscFileName";
        if (file_exists($gscFilePath)) {
            Logger::log("GSC verification file already exists: $gscFileName");
            return;
        }

        Logger::log("Downloading GSC file: $gscFileName from $gscFileUrl");

        $ch = curl_init($gscFileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $gscContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($gscContent !== false && $httpCode == 200) {
            if (file_put_contents($gscFilePath, $gscContent) !== false) {
                chmod($gscFilePath, 0644);
                Logger::log("GSC verification file downloaded and placed: $gscFileName");
            } else {
                Logger::log("Failed to save GSC file: $gscFileName");
            }
        } else {
            Logger::log("Failed to download GSC file from: $gscFileUrl (HTTP: $httpCode)");
        }
    }

    /**
     * Check and add missing GSC verification file if needed
     */
    public static function checkAndAddGSCFile($domain, $user, $gscFileUrl)
    {
        if (empty($gscFileUrl)) {
            return;
        }

        $webRoot = "/home/$user/web/$domain/public_html";

        if (!is_dir($webRoot)) {
            Logger::log("Web root doesn't exist for GSC check: $domain");
            return;
        }

        $gscFileName = basename(parse_url($gscFileUrl, PHP_URL_PATH));

        if (empty($gscFileName)) {
            Logger::log("Invalid GSC file URL for check: $gscFileUrl");
            return;
        }

        $gscFilePath = "$webRoot/$gscFileName";

        // Если файл не существует - добавляем его
        if (!file_exists($gscFilePath)) {
            Logger::log("GSC file missing, adding: $gscFileName for domain: $domain");
            self::downloadGoogleVerificationFile($webRoot, $gscFileUrl);

            // Устанавливаем правильные права доступа
            if (file_exists($gscFilePath)) {
                exec("chown $user:$user $gscFilePath");
                chmod($gscFilePath, 0644);
            }
        } else {
            Logger::log("GSC file already exists: $gscFileName for domain: $domain");
        }
    }

    /**
     * Deploy ZIP archive to domain
     * @return bool Returns true if deployment was successful, false otherwise
     */
    public static function deployZip($domain, $zipUrl, $user, $redirectsData, $gscFileUrl = null, $originalDomain = null, $isAutomaticDeploy = false)
    {
        $webRoot = "/home/$user/web/$domain/public_html";

        // Если оригинальный домен не передан, используем обычный домен
        if ($originalDomain === null) {
            $originalDomain = $domain;
        }

        if ($isAutomaticDeploy) {
            Logger::log("Automatic deployment for empty site: $domain (original: $originalDomain)");
        } else {
            Logger::log("Manual deployment: $domain (original: $originalDomain)");
        }

        // Check and set proxy template if needed
        self::checkAndSetProxyTemplate($domain, $user);

        // Создаем директорию если не существует
        if (!is_dir($webRoot)) {
            mkdir($webRoot, 0755, true);
        } else {
            // Очищаем только если это обычный деплой (не автоматический)
            if (!$isAutomaticDeploy) {
                exec("rm -rf $webRoot/*");
                exec("rm -rf $webRoot/.[!.]*");
            }
        }

        $zipFile = Config::TEMP_DIR . "/$domain-" . time() . ".zip";
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
            Logger::log("Download failed: $domain");
            self::handleFailedDeployment($webRoot, $domain, $user, $redirectsData, $zipFile);
            return false;
        }

        $zip = new ZipArchive;
        $extractionSuccess = false;
        $openResult = $zip->open($zipFile);

        if ($openResult === TRUE) {
            $zipEntryCount = $zip->numFiles;

            if ($zipEntryCount > 0) {
                // Для автоматического деплоя - только очищаем если пустой сайт
                if ($isAutomaticDeploy) {
                    exec("rm -rf $webRoot/*");
                    exec("rm -rf $webRoot/.[!.]*");
                }

                $extractResult = $zip->extractTo($webRoot);
                $zip->close();

                if ($extractResult) {
                    $extractedFiles = scandir($webRoot);
                    $extractedFileCount = count($extractedFiles) - 2;

                    if ($extractedFileCount > 0) {
                        if (file_exists("$webRoot/index.html") || file_exists("$webRoot/index.php")) {
                            $extractionSuccess = true;
                            if ($isAutomaticDeploy) {
                                Logger::log("Automatic deployment extraction successful: $domain");
                            } else {
                                Logger::log("Extraction successful: $domain");
                            }

                            self::replaceDomainPlaceholder($webRoot, $domain);

                            // Создаем config.php с оригинальным доменом
                            self::createPhpConfig($webRoot, $originalDomain, $user);

                            if ($gscFileUrl) {
                                self::downloadGoogleVerificationFile($webRoot, $gscFileUrl);
                            }

                        } else {
                            Logger::log("No index file after extraction: $domain");
                            self::createPlaceholderIndex($webRoot);
                        }
                    } else {
                        Logger::log("Empty extraction: $domain");
                        self::createPlaceholderIndex($webRoot);
                    }
                } else {
                    Logger::log("Extraction failed: $domain");
                    self::createPlaceholderIndex($webRoot);
                }
            } else {
                Logger::log("Empty ZIP: $domain");
                $zip->close();
                self::createPlaceholderIndex($webRoot);
            }
        } else {
            Logger::log("ZIP open failed: $domain");
            self::createPlaceholderIndex($webRoot);
        }

        RedirectsManager::updateRedirects($domain, $user, $redirectsData);

        exec("chown -R $user:$user $webRoot");
        exec("find $webRoot -type d -exec chmod 755 {} \\;");
        exec("find $webRoot -type f -exec chmod 644 {} \\;");

        // Cleanup ZIP file
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }

        if ($extractionSuccess) {
            if ($isAutomaticDeploy) {
                Logger::log("Automatic deployment successful: $domain");
            } else {
                Logger::log("Deployment successful: $domain");
            }
            return true;
        } else {
            Logger::log("Deployment issues: $domain");
            return false;
        }
    }

    /**
     * Handle failed deployment by creating placeholder
     */
    private static function handleFailedDeployment($webRoot, $domain, $user, $redirectsData, $zipFile)
    {
        Logger::log("Creating placeholder for failed deployment: $domain");
        self::createPlaceholderIndex($webRoot);

        RedirectsManager::updateRedirects($domain, $user, $redirectsData);
        exec("chown -R $user:$user $webRoot");

        // Cleanup ZIP file
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }
    }

    /**
     * Create placeholder index file
     */
    private static function createPlaceholderIndex($webRoot)
    {
        $placeholderContent = "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>";
        file_put_contents("$webRoot/index.html", $placeholderContent);
        Logger::log("Created placeholder index.html");
    }

    /**
     * Check if domain has tc-nginx-only proxy template and set it if not
     */
    private static function checkAndSetProxyTemplate($domain, $user)
    {
        $cmd = "sudo /usr/local/hestia/bin/v-list-web-domain $user $domain json";
        $output = shell_exec($cmd);
        $domainData = json_decode($output, true);

        if ($domainData && isset($domainData[$domain])) {
            $currentProxyTemplate = $domainData[$domain]['PROXY'] ?? '';

            $currentIp = $domainData[$domain]['IP'] ?? '';
            $primaryIp = ApiClient::getServerIp();

            if ($currentProxyTemplate !== 'tc-nginx-only') {
                Logger::log("Setting proxy template tc-nginx-only for domain: $domain");

                $setProxyCmd = "sudo /usr/local/hestia/bin/v-change-web-domain-proxy-tpl $user $domain tc-nginx-only";
                $result = exec($setProxyCmd, $output, $returnCode);

                if ($returnCode === 0) {
                    Logger::log("Proxy template tc-nginx-only set successfully for: $domain");
                } else {
                    Logger::log("Failed to set proxy template for: $domain. Error: " . implode("\n", $output));
                }
            } else {
                Logger::log("Domain $domain already has tc-nginx-only proxy template");
            }

            if (!empty($primaryIp) && $currentIp !== $primaryIp) {
                Logger::log("Changing IP for $domain from $currentIp to $primaryIp");

                $setIpCmd = "sudo /usr/local/hestia/bin/v-change-web-domain-ip $user $domain $primaryIp";
                exec($setIpCmd, $ipOutput, $ipReturnCode);

                if ($ipReturnCode === 0) {
                    Logger::log("IP $primaryIp set successfully for: $domain");
                } else {
                    Logger::log("Failed to set IP for: $domain. Error: " . implode("\n", $ipOutput));
                }
            } else {
                Logger::log("Domain $domain already uses IP $primaryIp");
            }
        } else {
            Logger::log("Could not retrieve domain information for: $domain");
        }
    }
}
