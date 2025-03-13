<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;
use Uzulla\CallUserFunc\App\AppFactory;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    (new Dotenv())->load(__DIR__ . '/.env');
}

// ログディレクトリの作成
$logDir = __DIR__ . '/var/log';
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$output = new ConsoleOutput();
$output->writeln('<info>テスト: 最新投稿日時を無視して強制的に投稿します</info>');

// アプリケーションを作成
$app = AppFactory::create();

// 最新投稿日時を無視して強制的に投稿するためのモックメソッド
$blueSkyClient = new class extends \Uzulla\CallUserFunc\BlueSky\BlueSkyClient {
    public function getLatestPostDate(): ?\DateTime
    {
        // 1年前の日時を返すことで、すべてのパッケージが新着として扱われる
        return new \DateTime('-1 year');
    }
};

// リフレクションを使用してprivateプロパティを置き換える
$reflectionApp = new ReflectionClass($app);
$reflectionProperty = $reflectionApp->getProperty('blueSkyClient');
$reflectionProperty->setAccessible(true);
$reflectionProperty->setValue($app, $blueSkyClient);

// 5件だけ投稿するように制限
$limit = 5;
$dryRun = false;

// 実行
try {
    $result = $app->run($limit, $dryRun, $output);
    $output->writeln(sprintf('<info>テスト完了: 終了コード %d</info>', $result));
} catch (\Exception $e) {
    $output->writeln(sprintf('<error>エラー: %s</error>', $e->getMessage()));
}
