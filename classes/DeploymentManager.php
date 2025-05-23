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
     * Deploy ZIP archive to domain
     */
    public static function deployZip($domain, $zipUrl, $user, $redirectsData)
    {
        $webRoot = "/home/$user/web/$domain/public_html";
        $backupDir = Config::TEMP_DIR . "/$domain-backup-" . time();
        $hasBackup = false;

        Logger::log("Deploying: $domain");

        // Check and set proxy template if needed
        self::checkAndSetProxyTemplate($domain, $user);

        if (is_dir($webRoot)) {
            exec("cp -r $webRoot $backupDir");
            $hasBackup = is_dir($backupDir);

            exec("rm -rf $webRoot/*");
            exec("rm -rf $webRoot/.[!.]*");
        } else {
            mkdir($webRoot, 0755, true);
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
            self::handleFailedDeployment($webRoot, $backupDir, $hasBackup, $domain, $user, $redirectsData, $zipFile);
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
                            Logger::log("Extraction successful: $domain");
                        } else {
                            Logger::log("No index file: $domain");
                            self::restoreOrCreateIndex($webRoot, $backupDir, $hasBackup);
                        }
                    } else {
                        Logger::log("Empty extraction: $domain");
                        self::restoreBackupOrCreateIndex($webRoot, $backupDir, $hasBackup);
                    }
                } else {
                    Logger::log("Extraction failed: $domain");
                    self::restoreBackupOrCreateIndex($webRoot, $backupDir, $hasBackup);
                }
            } else {
                Logger::log("Empty ZIP: $domain");
                $zip->close();
                self::restoreBackupOrCreateIndex($webRoot, $backupDir, $hasBackup);
            }
        } else {
            Logger::log("ZIP open failed: $domain");
            self::restoreBackupOrCreateIndex($webRoot, $backupDir, $hasBackup);
        }

        RedirectsManager::updateRedirects($domain, $user, $redirectsData);

        exec("chown -R $user:$user $webRoot");
        exec("find $webRoot -type d -exec chmod 755 {} \\;");
        exec("find $webRoot -type f -exec chmod 644 {} \\;");

        self::cleanup($zipFile, $backupDir, $hasBackup);

        if ($extractionSuccess) {
            Logger::log("Deployment successful: $domain");
        } else {
            Logger::log("Deployment issues: $domain");
        }
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
        } else {
            Logger::log("Could not retrieve domain information for: $domain");
        }
    }

    /**
     * Handle failed deployment by restoring backup or creating placeholder
     */
    private static function handleFailedDeployment($webRoot, $backupDir, $hasBackup, $domain, $user, $redirectsData, $zipFile)
    {
        if ($hasBackup) {
            exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
            exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
            Logger::log("Restored backup: $domain");
        } else {
            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
        }

        RedirectsManager::updateRedirects($domain, $user, $redirectsData);
        exec("chown -R $user:$user $webRoot");

        self::cleanup($zipFile, $backupDir, $hasBackup);
    }

    /**
     * Restore index file from backup or create new one
     */
    private static function restoreOrCreateIndex($webRoot, $backupDir, $hasBackup)
    {
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

    /**
     * Restore backup or create placeholder index
     */
    private static function restoreBackupOrCreateIndex($webRoot, $backupDir, $hasBackup)
    {
        if ($hasBackup) {
            exec("cp -r $backupDir/* $webRoot/ 2>/dev/null");
            exec("cp -r $backupDir/.[!.]* $webRoot/ 2>/dev/null");
        } else {
            file_put_contents("$webRoot/index.html", "<html><body><h1>Site is being updated</h1><p>Please check back later.</p></body></html>");
        }
    }

    /**
     * Cleanup temporary files and directories
     */
    private static function cleanup($zipFile, $backupDir, $hasBackup)
    {
        if (file_exists($zipFile)) {
            unlink($zipFile);
        }
        if ($hasBackup) {
            exec("rm -rf $backupDir");
        }
    }
}