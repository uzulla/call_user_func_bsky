<?php

require_once __DIR__ . '/vendor/autoload.php';

use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Dotenv\Dotenv;
use Uzulla\CallUserFunc\App\AppFactory;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;

// Load environment variables
if (file_exists(__DIR__ . '/.env')) {
    (new Dotenv())->load(__DIR__ . '/.env');
}

$output = new ConsoleOutput();
$output->writeln('<info>投稿テキストのフォーマットテスト</info>');

// サンプルパッケージデータ
$samplePackages = [
    [
        'title' => 'example/package (1.0.0)',
        'description' => 'This is a sample package with a URL https://example.com that should be snipped.',
        'repository_url' => 'https://github.com/example/package',
        'pubDate' => new \DateTime('2025-03-13 05:00:00'),
    ],
    [
        'title' => 'laravel/framework (10.0.0)',
        'description' => 'The Laravel Framework.',
        'repository_url' => 'https://github.com/laravel/framework',
        'pubDate' => new \DateTime('2025-03-13 06:00:00'),
    ],
    [
        'title' => 'symfony/console (6.3.0)',
        'description' => 'Symfony Console Component with multiple URLs: https://symfony.com and https://github.com/symfony/console',
        'repository_url' => 'https://github.com/symfony/console',
        'pubDate' => new \DateTime('2025-03-13 07:00:00'),
    ],
];

// フォーマッターを作成
$formatter = new PackagistFormatter();

// 各パッケージの投稿テキストを表示
foreach ($samplePackages as $index => $package) {
    $output->writeln(sprintf('<comment>パッケージ %d:</comment>', $index + 1));
    
    // パッケージ名とバージョンを抽出
    preg_match('/^(.+?) \((.+?)\)$/', $package['title'], $matches);
    $packageName = $matches[1] ?? 'unknown';
    $version = $matches[2] ?? 'unknown';
    
    // 投稿テキストを作成
    $text = $formatter->formatPackage($package);
    
    // 投稿テキストを表示
    $output->writeln($text);
    $output->writeln('');
    
    // リポジトリURLのカード表示をシミュレート
    $output->writeln(sprintf('<info>カード表示URL: %s</info>', $package['repository_url']));
    $output->writeln('-----------------------------------');
}
