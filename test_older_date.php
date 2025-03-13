<?php

require __DIR__ . '/vendor/autoload.php';

use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use Uzulla\CallUserFunc\RSS\PackagistRSSReader;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;
use Uzulla\CallUserFunc\App\AppFactory;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// .envファイルを読み込む
if (file_exists(__DIR__ . '/.env')) {
    $envFile = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $envFile);
    foreach ($lines as $line) {
        if (empty($line) || strpos($line, '#') === 0) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

// ロガーの設定
$logger = new Logger('test');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::DEBUG));

// 古い日付を設定（2025-03-01）
$oldDate = new DateTime('2025-03-01 00:00:00');
$logger->info('テスト用の古い日付を設定', ['date' => $oldDate->format('Y-m-d H:i:s')]);

// RSSリーダーの初期化
$rssReader = new PackagistRSSReader($_ENV['PACKAGIST_RSS_URL'] ?? 'https://packagist.org/feeds/releases.rss', $logger);

// パッケージの取得
$packages = $rssReader->fetchPackages();
$logger->info('パッケージを取得しました', ['count' => count($packages)]);

// 古い日付以降のパッケージをフィルタリング
$filteredPackages = $rssReader->filterPackagesSince($packages, $oldDate);
$logger->info('フィルタリング後のパッケージ', [
    'before' => count($packages),
    'after' => count($filteredPackages),
    'since' => $oldDate->format('Y-m-d H:i:s')
]);

// すべてのパッケージを投稿（最大40件）
$limitedPackages = $filteredPackages;
$logger->info('投稿するパッケージ', ['count' => count($limitedPackages)]);

// パッケージを公開日時の昇順（古い順）でソート
usort($limitedPackages, fn($a, $b) => $a['timestamp'] <=> $b['timestamp']);

// フォーマッターの初期化
$formatter = new PackagistFormatter(100, 200, $logger);

// パッケージの整形
$formattedPackages = $formatter->formatPackages($limitedPackages);
$logger->info('整形したパッケージ', ['count' => count($formattedPackages)]);

// BlueSkyクライアントの初期化
$blueSkyClient = new BlueSkyClient($logger);

// 認証情報の取得
$username = $_ENV['BLUESKY_USERNAME'] ?? '';
$password = $_ENV['BLUESKY_PASSWORD'] ?? '';

$logger->info('BlueSky認証情報', ['username_set' => !empty($username), 'password_set' => !empty($password)]);

if (empty($username) || empty($password)) {
    $logger->error('BlueSkyの認証情報が設定されていません');
    exit(1);
}

// BlueSkyに認証
$logger->info('BlueSkyに認証します', ['username' => $username]);
$blueSkyClient->authenticate($username, $password);
$logger->info('BlueSkyに認証しました');

// ドライランモード（実際に投稿しない場合はtrueに設定）
$dryRun = false;

// パッケージの投稿
$count = count($formattedPackages);
foreach ($formattedPackages as $index => $formattedPackage) {
    $packageNumber = $index + 1;
    $logger->info("パッケージ {$packageNumber}/{$count} を投稿します");
    echo $formattedPackage['text'] . PHP_EOL . PHP_EOL;
    
    if (!$dryRun) {
        try {
            $postUri = $blueSkyClient->createPost(
                $formattedPackage['text'],
                $formattedPackage['links']
            );
            
            $logger->info('投稿しました', [
                'package_number' => $packageNumber,
                'total_packages' => count($formattedPackages),
                'uri' => $postUri
            ]);
            
            // 連続投稿の間隔を空ける（APIレート制限対策）
            sleep(2);
        } catch (\Exception $e) {
            $logger->error('投稿に失敗しました', [
                'package_number' => $packageNumber,
                'error' => $e->getMessage()
            ]);
        }
    }
}

$logger->info('処理が完了しました');
