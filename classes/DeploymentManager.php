<?php

require_once 'classes/config.php';
require_once 'classes/Logger.php';
require_once 'classes/StateManager.php';
require_once 'classes/ApiClient.php';
require_once 'classes/UserManager.php';
require_once 'classes/DomainManager.php';
require_once 'classes/RedirectsManager.php';
require_once 'classes/DeploymentManager.php';

class Deploy
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
    private static function processSite($site, $currentDomains, $schemaUser, $schemaName, &$previousState, $shouldDeploy, $zipUrl)
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

        if ($redirectsChanged) {
            $webRoot = "/home/$schemaUser/web/$hestiaDomain/public_html";

            if (!is_dir($webRoot)) {
                mkdir($webRoot, 0755, true);
                exec("chown $schemaUser:$schemaUser $webRoot");
            }

            RedirectsManager::updateRedirects($hestiaDomain, $schemaUser, $redirectsData);
        }

        if ($shouldDeploy || $domainNeverDeployed || $needsDeployment) {
            DeploymentManager::deployZip($hestiaDomain, $zipUrl, $schemaUser, $redirectsData);
            $previousState[$domainStateKey] = date('Y-m-d H:i:s');
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

        foreach ($schema['sites'] as $site) {
            self::processSite($site, $currentDomains, $schemaUser, $schemaName, $previousState, $shouldDeploy, $zipUrl);
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

Deploy::main();