<?php

declare(strict_types=1);

namespace CrisperCode\Utils;

use CrisperCode\Config\FrameworkConfig;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class LoggerFactory
{
    public static function create(FrameworkConfig $config): Logger
    {
        $logger = new Logger('app');
        $logLevel = $config->isDevelopment() ? Level::Debug : Level::Info;
        $logger->pushHandler(new StreamHandler('php://stderr', $logLevel));

        return $logger;
    }
}
