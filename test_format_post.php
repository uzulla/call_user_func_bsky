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
$output->writeln('<info>様々なパターンのパッケージ投稿テキストを表示します</info>');
$output->writeln('');

// サンプルパッケージデータ
$samplePackages = [
    // 1. 標準的なパッケージ（URLを含む説明文）
    [
        'title' => 'example/package (1.0.0)',
        'description' => 'This is a sample package with a URL https://example.com that should be snipped.',
        'repository_url' => 'https://github.com/example/package',
        'pubDate' => new \DateTime('2025-03-13 05:00:00'),
    ],
    
    // 2. シンプルな説明文のパッケージ
    [
        'title' => 'laravel/framework (10.0.0)',
        'description' => 'The Laravel Framework.',
        'repository_url' => 'https://github.com/laravel/framework',
        'pubDate' => new \DateTime('2025-03-13 06:00:00'),
    ],
    
    // 3. 複数のURLを含む説明文のパッケージ
    [
        'title' => 'symfony/console (6.3.0)',
        'description' => 'Symfony Console Component with multiple URLs: https://symfony.com and https://github.com/symfony/console',
        'repository_url' => 'https://github.com/symfony/console',
        'pubDate' => new \DateTime('2025-03-13 07:00:00'),
    ],
    
    // 4. 長い説明文のパッケージ（切り詰め処理の確認）
    [
        'title' => 'long/description (2.0.0)',
        'description' => str_repeat('This is a very long description that should be truncated. ', 10) . 'https://example.com/long-url',
        'repository_url' => 'https://gitlab.com/long/description',
        'pubDate' => new \DateTime('2025-03-13 08:00:00'),
    ],
    
    // 5. GitLabリポジトリのパッケージ
    [
        'title' => 'gitlab/package (3.1.0)',
        'description' => 'A package hosted on GitLab.',
        'repository_url' => 'https://gitlab.com/gitlab/package',
        'pubDate' => new \DateTime('2025-03-13 09:00:00'),
    ],
    
    // 6. リポジトリURLがないパッケージ（代わりにlinkを使用）
    [
        'title' => 'no-repo/package (0.1.0)',
        'description' => 'A package without repository URL.',
        'link' => 'https://packagist.org/packages/no-repo/package',
        'pubDate' => new \DateTime('2025-03-13 10:00:00'),
    ],
    
    // 7. 日本語の説明文を持つパッケージ
    [
        'title' => 'japanese/package (1.2.3)',
        'description' => '日本語の説明文を持つパッケージです。URLは https://example.jp です。',
        'repository_url' => 'https://github.com/japanese/package',
        'pubDate' => new \DateTime('2025-03-13 11:00:00'),
    ],
];

// フォーマッターを作成
$formatter = new PackagistFormatter();

// 各パッケージの投稿テキストを表示
foreach ($samplePackages as $index => $package) {
    $output->writeln(sprintf('<comment>パッケージ %d:</comment>', $index + 1));
    
    // パッケージの種類を表示
    $packageType = match($index) {
        0 => '標準的なパッケージ（URLを含む説明文）',
        1 => 'シンプルな説明文のパッケージ',
        2 => '複数のURLを含む説明文のパッケージ',
        3 => '長い説明文のパッケージ（切り詰め処理の確認）',
        4 => 'GitLabリポジトリのパッケージ',
        5 => 'リポジトリURLがないパッケージ',
        6 => '日本語の説明文を持つパッケージ',
        default => 'その他のパッケージ',
    };
    $output->writeln(sprintf('<info>種類: %s</info>', $packageType));
    
    // 投稿テキストを作成
    $text = $formatter->formatPackage($package);
    
    // 投稿テキストを表示
    $output->writeln($text);
    $output->writeln('');
    
    // リポジトリURLのカード表示をシミュレート
    $repoUrl = $package['repository_url'] ?? ($package['link'] ?? 'なし');
    $output->writeln(sprintf('<info>カード表示URL: %s</info>', $repoUrl));
    $output->writeln('-----------------------------------');
}
