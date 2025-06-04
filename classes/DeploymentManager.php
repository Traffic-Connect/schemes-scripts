<?php

require_once __DIR__ . '/Logger.php';

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
     * Deploy ZIP archive to domain - ПРОСТАЯ ВЕРСИЯ
     */
    public static function deployZip($domain, $zipUrl, $user, $redirectsData = null, $gscFileUrl = null, $originalDomain = null)
    {
        $webRoot = "/home/$user/web/$domain/public_html";

        if ($originalDomain === null) {
            $originalDomain = $domain;
        }

        Logger::log("Deploying: $domain (original: $originalDomain)");

        // Создаем папку если нет
        if (!is_dir($webRoot)) {
            mkdir($webRoot, 0755, true);
            exec("chown $user:$user $webRoot");
        }

        // Скачиваем ZIP
        $zipFile = "/tmp/$domain-" . time() . ".zip";

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

        if (!$success || $httpCode !== 200 || !file_exists($zipFile) || $fileSize == 0) {
            Logger::log("Download failed: $domain");
            self::createPlaceholder($webRoot, $user);
            return;
        }

        // Очищаем папку
        exec("rm -rf $webRoot/* 2>/dev/null");
        exec("rm -rf $webRoot/.[!.]* 2>/dev/null");

        // Распаковываем ZIP
        $zip = new ZipArchive;
        if ($zip->open($zipFile) === TRUE) {
            if ($zip->numFiles > 0) {
                $extractResult = $zip->extractTo($webRoot);
                $zip->close();

                if ($extractResult && (file_exists("$webRoot/index.html") || file_exists("$webRoot/index.php"))) {
                    Logger::log("Extraction successful: $domain");

                    // Заменяем плейсхолдеры домена
                    self::replaceDomainPlaceholder($webRoot, $domain);

                    // Создаем config.php
                    self::createPhpConfig($webRoot, $originalDomain, $user);

                    // Скачиваем GSC файл если нужно
                    if ($gscFileUrl) {
                        self::downloadGoogleVerificationFile($webRoot, $gscFileUrl);
                    }

                } else {
                    Logger::log("Extraction failed or no index file: $domain");
                    self::createPlaceholder($webRoot, $user);
                }
            } else {
                Logger::log("Empty ZIP: $domain");
                $zip->close();
                self::createPlaceholder($webRoot, $user);
            }
        } else {
            Logger::log("Failed to open ZIP: $domain");
            self::createPlaceholder($webRoot, $user);
        }

        // Устанавливаем права
        exec("chown -R $user:$user $webRoot");
        exec("find $webRoot -type d -exec chmod 755 {} \\;");
        exec("find $webRoot -type f -exec chmod 644 {} \\;");

        // Убираем ZIP файл
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }

        Logger::log("Deployment completed: $domain");
    }

    /**
     * Replace %domain% placeholder in files
     */
    private static function replaceDomainPlaceholder($webRoot, $domain)
    {
        $extensions = ['html', 'css', 'js', 'txt', 'xml'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($webRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        $replacedFiles = 0;

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fileExtension = strtolower(pathinfo($file->getFilename(), PATHINFO_EXTENSION));

                if (in_array($fileExtension, $extensions)) {
                    $filePath = $file->getRealPath();
                    $content = file_get_contents($filePath);

                    if ($content !== false && strpos($content, '%domain%') !== false) {
                        $newContent = str_replace('%domain%', $domain, $content);
                        if (file_put_contents($filePath, $newContent) !== false) {
                            $replacedFiles++;
                        }
                    }
                }
            }
        }

        if ($replacedFiles > 0) {
            Logger::log("Replaced domain placeholders in $replacedFiles files");
        }
    }

    /**
     * Create PHP config file
     */
    private static function createPhpConfig($webRoot, $originalDomain, $user)
    {
        $configPath = "$webRoot/config.php";
        $homeUrl = 'https://' . $originalDomain;
        $configContent = "<?php\nreturn array (\n  'home_url' => '$homeUrl',\n);\n";

        if (file_put_contents($configPath, $configContent) !== false) {
            exec("chown $user:$user $configPath");
            chmod($configPath, 0644);
            Logger::log("Created config.php for $originalDomain");
        }
    }

    /**
     * Download Google Search Console verification file
     */
    private static function downloadGoogleVerificationFile($webRoot, $gscFileUrl)
    {
        if (empty($gscFileUrl)) {
            return;
        }

        $gscFileName = basename(parse_url($gscFileUrl, PHP_URL_PATH));
        if (empty($gscFileName)) {
            return;
        }

        $ch = curl_init($gscFileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $gscContent = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($gscContent !== false && $httpCode == 200) {
            $gscFilePath = "$webRoot/$gscFileName";
            if (file_put_contents($gscFilePath, $gscContent) !== false) {
                chmod($gscFilePath, 0644);
                Logger::log("Downloaded GSC file: $gscFileName");
            }
        }
    }

    /**
     * Create placeholder page
     */
    private static function createPlaceholder($webRoot, $user)
    {
        $placeholder = "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>";
        file_put_contents("$webRoot/index.html", $placeholder);
        exec("chown $user:$user $webRoot/index.html");
        Logger::log("Created placeholder page");
    }
}