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

            // Add rotating file handler
            $fileHandler = new RotatingFileHandler(
                dirname(__DIR__, 2) . '/logs/app-' . date('Ymd') . '.log',
                10,
                $logLevelConstant
            );
            $logger->pushHandler($fileHandler);

            // Add STDERR handler
            $stderrHandler = new StreamHandler('php://stderr', $logLevelConstant);
            $logger->pushHandler($stderrHandler);

            self::$instance = $logger;
        }

        return self::$instance;
    }
} 