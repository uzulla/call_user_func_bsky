<?php

declare(strict_types=1);

namespace Uzulla\CallUserFunc\App;

use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;
use Uzulla\CallUserFunc\RSS\PackagistRSSReader;

/**
 * アプリケーションのファクトリークラス
 */
class AppFactory
{
    /**
     * アプリケーションを作成する
     *
     * @return PackagistToBlueSkyApp アプリケーション
     */
    public static function create(): PackagistToBlueSkyApp
    {
        $logger = self::createLogger();
        
        $rssReader = new PackagistRSSReader(
            $_ENV['PACKAGIST_RSS_URL'] ?? 'https://packagist.org/feeds/releases.rss',
            $logger
        );
        
        $blueSkyClient = new BlueSkyClient($logger);
        
        $formatter = new PackagistFormatter(
            100,
            200,
            $logger
        );
        
        return new PackagistToBlueSkyApp(
            $rssReader,
            $blueSkyClient,
            $formatter,
            $logger
        );
    }
    
    /**
     * ロガーを作成する
     *
     * @return LoggerInterface ロガー
     */
    private static function createLogger(): LoggerInterface
    {
        $logLevel = $_ENV['LOG_LEVEL'] ?? 'info';
        $logLevelMap = [
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
        ];
        
        $level = $logLevelMap[$logLevel] ?? Logger::INFO;
        
        $logger = new Logger('packagist-to-bluesky');
        $logger->pushHandler(new StreamHandler(
            __DIR__ . '/../../../../var/log/app.log',
            $level
        ));
        
        return $logger;
    }
}
