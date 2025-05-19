<?php

namespace App\Config;

use Dotenv\Dotenv;

class EnvLoader
{
    private static ?EnvLoader $instance = null;
    private array $config = [];

    private function __construct()
    {
        try {
            $dotenv = Dotenv::createImmutable(dirname(__DIR__, 2));
            $dotenv->load();
            
            // PowerBody SOAP Config
            $this->config['POWERBODY_API_WSDL'] = $_ENV['POWERBODY_API_WSDL'] ?? 'http://www.powerbody.co.uk/api/soap/?wsdl';
            $this->config['POWERBODY_USER'] = $_ENV['POWERBODY_USER'] ?? '';
            $this->config['POWERBODY_PASS'] = $_ENV['POWERBODY_PASS'] ?? '';

            // Shopify Admin REST Config
            $this->config['SHOPIFY_STORE'] = $_ENV['SHOPIFY_STORE'] ?? '';
            $this->config['SHOPIFY_API_KEY'] = $_ENV['SHOPIFY_API_KEY'] ?? '';
            $this->config['SHOPIFY_API_SECRET'] = $_ENV['SHOPIFY_API_SECRET'] ?? '';
            $this->config['SHOPIFY_ACCESS_TOKEN'] = $_ENV['SHOPIFY_ACCESS_TOKEN'] ?? '';
            
            // Log the location ID for debugging
            $locationId = $_ENV['SHOPIFY_LOCATION_ID'] ?? '';
            $this->config['SHOPIFY_LOCATION_ID'] = $locationId;

            // Sync Cadence
            $this->config['PRODUCT_SYNC_CRON'] = $_ENV['PRODUCT_SYNC_CRON'] ?? '0 2 * * *';
            $this->config['ORDER_SYNC_CRON'] = $_ENV['ORDER_SYNC_CRON'] ?? '0 * * * *';
            $this->config['LOG_LEVEL'] = $_ENV['LOG_LEVEL'] ?? 'INFO';
        } catch (\Exception $e) {
            // Can't use logger here due to circular dependency
            error_log('Failed to load environment variables: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function getInstance(): EnvLoader
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->config;
    }
} 