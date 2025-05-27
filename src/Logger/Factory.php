<?php

namespace App\Logger;

use App\Config\EnvLoader;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

class Factory
{
    private static ?Logger $instance = null;

    public static function getInstance(string $channel = 'app'): Logger
    {
        if (self::$instance === null) {
            $config = EnvLoader::getInstance();
            $logLevel = strtoupper($config->get('LOG_LEVEL', 'INFO'));
            $logLevelConstant = constant('Monolog\Logger::' . $logLevel);

            $logger = new Logger($channel);

            // Always use STDERR handler for reliability
            $stderrHandler = new StreamHandler('php://stderr', $logLevelConstant);
            $logger->pushHandler($stderrHandler);
            
            try {
                // Ensure logs directory exists using relative path
                $logsDir = dirname(__DIR__, 2) . '/logs';
                if (!file_exists($logsDir)) {
                    // Create with very permissive mode for cross-platform compatibility
                    if (!@mkdir($logsDir, 0777, true)) {
                        // If creating directory fails, log to stderr only
                        error_log("Warning: Could not create logs directory at {$logsDir}");
                    }
                }
                
                // Only try to use file logging if directory exists
                if (is_dir($logsDir) && is_writable($logsDir)) {
                    // Simple naming format without date in filename
                    $logFile = $logsDir . '/app.log';
                    
                    // Add rotating file handler with basic settings
                    $fileHandler = new StreamHandler($logFile, $logLevelConstant);
                    $logger->pushHandler($fileHandler);
                }
            } catch (\Exception $e) {
                // If anything goes wrong with file setup, just log to stderr
                error_log("Warning: Could not set up file logging: " . $e->getMessage());
            }

            self::$instance = $logger;
        }

        return self::$instance;
    }
} 