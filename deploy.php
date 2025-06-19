<?php

require_once 'classes/config.php';
require_once 'classes/Logger.php';
require_once 'classes/StateManager.php';
require_once 'classes/ApiClient.php';
require_once 'classes/UserManager.php';
require_once 'classes/DomainManager.php';
require_once 'classes/RedirectsManager.php';
require_once 'classes/DeploymentManager.php';

class SchemaDeployer
{
    /**
     * Initialize required directories
     */
    private static function initializeDirectories()
    {
        if (!file_exists(dirname(Config::STATE_FILE))) {
            mkdir(dirname(Config::STATE_FILE), 0755, true);
        }
        if (!file_exists(Config::TEMP_DIR)) {
            mkdir(Config::TEMP_DIR, 0755, true);
        }
    }

    /**
     * Clean up unused domains for schema user
     */
    private static function cleanupUnusedDomains($schemas, $schema, $schemaUser)
    {
        $schemaName = $schema['name'];
        $currentDomains = array_column($schema['sites'], 'domain');
        $existingDomains = UserManager::getUserDomains($schemaUser);

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
                    Logger::log("Removing domain: $existingDomain");
                    exec("/usr/local/hestia/bin/v-delete-web-domain $schemaUser $existingDomain");
                }
            }
        }
    }

    /**
     * Process single site deployment
     */
    private static function processSite($site, $currentDomains, $schemaUser, $schemaName, &$previousState, $shouldDeploy, $zipUrl, &$deploymentResults)
    {
        $originalDomain = $site['domain'];
        $isWwwDomain = (strpos($originalDomain, 'www.') === 0);

        $hestiaDomain = DomainManager::createDomain($originalDomain, $schemaUser);

        $redirectsData = RedirectsManager::prepareRedirectsData($site, $currentDomains, $isWwwDomain);
        $redirectsChanged = RedirectsManager::hasChanged($previousState, $schemaName, $hestiaDomain, $site, $currentDomains, $isWwwDomain);
        RedirectsManager::storeState($previousState, $schemaName, $hestiaDomain, $redirectsData);

        $domainStateKey = $schemaName . '_' . $hestiaDomain;
        $domainNeverDeployed = empty($previousState[$domainStateKey]);
        $needsDeployment = DeploymentManager::needsDeployment($hestiaDomain, $schemaUser);

        $needsAutomaticDeployment = DeploymentManager::needsAutomaticDeployment($hestiaDomain, $schemaUser);

        if ($redirectsChanged) {
            $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

            if (!is_dir($webRoot)) {
                mkdir($webRoot, 0755, true);
                exec("chown $schemaUser:$schemaUser $webRoot");
            }

            RedirectsManager::updateRedirects($hestiaDomain, $schemaUser, $redirectsData);
        }

        $shouldDeployNow = $shouldDeploy || $domainNeverDeployed || $needsDeployment || $needsAutomaticDeployment;

        $deploymentResults[$hestiaDomain] = [
            'deployed' => false,
            'success' => false,
            'was_automatic' => false
        ];

        if ($shouldDeployNow) {
            $gscFileUrl = isset($site['gsc_file_url']) ? $site['gsc_file_url'] : null;

            $deploymentResults[$hestiaDomain]['deployed'] = true;

            if ($needsAutomaticDeployment) {
                $deploymentResults[$hestiaDomain]['was_automatic'] = true;
                Logger::log("Triggering automatic deployment for empty site: $hestiaDomain");

                $deploySuccess = DeploymentManager::deployZip($hestiaDomain, $zipUrl, $schemaUser, $redirectsData, $gscFileUrl, $originalDomain, true);
                $deploymentResults[$hestiaDomain]['success'] = $deploySuccess;
            } else {
                $reason = [];
                if ($shouldDeploy) $reason[] = "ZIP updated";
                if ($domainNeverDeployed) $reason[] = "never deployed";
                if ($needsDeployment) $reason[] = "missing content";

                Logger::log("Deploying $hestiaDomain. Reason: " . implode(", ", $reason));

                $deploySuccess = DeploymentManager::deployZip($hestiaDomain, $zipUrl, $schemaUser, $redirectsData, $gscFileUrl, $originalDomain, false);
                $deploymentResults[$hestiaDomain]['success'] = $deploySuccess;
            }

            if ($deploymentResults[$hestiaDomain]['success']) {
                $previousState[$domainStateKey] = date('Y-m-d H:i:s');
            }
        } else {
            Logger::log("No deployment needed for: $hestiaDomain");

            // Даже если деплой не нужен, проверяем GSC файл
            self::checkGSCFiles($site, $hestiaDomain, $schemaUser);
        }
    }

    /**
     * Check and add missing GSC files for all sites
     */
    private static function checkGSCFiles($site, $hestiaDomain, $schemaUser)
    {
        $gscFileUrl = isset($site['gsc_file_url']) ? $site['gsc_file_url'] : null;

        if (empty($gscFileUrl)) {
            return;
        }

        Logger::log("Checking GSC file for domain: $hestiaDomain");

        DeploymentManager::checkAndAddGSCFile($hestiaDomain, $schemaUser, $gscFileUrl);
    }

    /**
     * Check if Nginx configuration is valid
     */
    private static function isNginxConfigValid()
    {
        $output = [];
        $returnCode = 0;

        exec('nginx -t 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            Logger::log("Nginx configuration is valid");
            return true;
        } else {
            Logger::log("Nginx configuration has errors: " . implode("\n", $output));
            return false;
        }
    }

    /**
     * Reload Nginx safely
     */
    private static function reloadNginx()
    {
        if (!self::isNginxConfigValid()) {
            Logger::log("Skipping Nginx reload due to configuration errors");
            return false;
        }

        $output = [];
        $returnCode = 0;

        exec('systemctl restart nginx 2>&1', $output, $returnCode);

        if ($returnCode === 0) {
            Logger::log("Nginx reloaded successfully");
            return true;
        } else {
            Logger::log("Failed to reload Nginx: " . implode("\n", $output));
            return false;
        }
    }

    /**
     * Process single schema
     */
    private static function processSchema($schemas, $schema, &$previousState)
    {
        $schemaName = $schema['name'];
        $zipUrl = $schema['zip_url'] ?? null;
        $zipUploadedAt = $schema['zip_uploaded_at'] ?? null;

        Logger::log("Processing: $schemaName");

        if (empty($zipUrl)) {
            Logger::log("No ZIP: $schemaName");
            return;
        }

        $schemaUser = UserManager::createSchemaUser($schemaName);

        $previousZipDate = $previousState[$schemaName]['zip_uploaded_at'] ?? null;
        $shouldDeploy = empty($previousZipDate) || $previousZipDate !== $zipUploadedAt;

        if ($shouldDeploy) {
            Logger::log("ZIP updated: $schemaName");
        }

        self::cleanupUnusedDomains($schemas, $schema, $schemaUser);

        $currentDomains = array_column($schema['sites'], 'domain');
        $deploymentResults = [];

        foreach ($schema['sites'] as $site) {
            self::processSite($site, $currentDomains, $schemaUser, $schemaName, $previousState, $shouldDeploy, $zipUrl, $deploymentResults);
        }

        if ($shouldDeploy) {
            $allSitesDeployed = true;
            $allDeploymentsSuccessful = true;
            $hasNonAutomaticDeployments = false;

            foreach ($deploymentResults as $domain => $result) {
                if (!$result['deployed']) {
                    $allSitesDeployed = false;
                    break;
                }

                if (!$result['success']) {
                    $allDeploymentsSuccessful = false;
                }

                if ($result['deployed'] && !$result['was_automatic']) {
                    $hasNonAutomaticDeployments = true;
                }
            }


            if ($allSitesDeployed && $allDeploymentsSuccessful && $hasNonAutomaticDeployments) {
                Logger::log("All sites deployed successfully for schema $schemaName, reloading Nginx");
                self::reloadNginx();
            } else {
                $reasons = [];
                if (!$allSitesDeployed) $reasons[] = "not all sites deployed";
                if (!$allDeploymentsSuccessful) $reasons[] = "some deployments failed";
                if (!$hasNonAutomaticDeployments) $reasons[] = "only automatic deployments";

                Logger::log("Skipping Nginx reload for schema $schemaName. Reason: " . implode(", ", $reasons));
            }
        }

        $previousState[$schemaName] = [
            'zip_uploaded_at' => $zipUploadedAt,
            'last_processed' => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Main deployment function
     */
    public static function main()
    {
        Logger::log("Starting deployment");

        self::initializeDirectories();

        $previousState = StateManager::load();
        $schemas = ApiClient::getSchemas();

        if (empty($schemas)) {
            Logger::log("No schemas found");
            exit(0);
        }

        Logger::log("Found " . count($schemas) . " schemas");

        foreach ($schemas as $schema) {
            self::processSchema($schemas, $schema, $previousState);
        }

        StateManager::save($previousState);
        Logger::log("Deployment completed");
    }
}

SchemaDeployer::main();