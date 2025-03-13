<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\App;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\App\PackagistToBlueSkyApp;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use Uzulla\CallUserFunc\Formatter\PackagistFormatter;
use Uzulla\CallUserFunc\RSS\PackagistRSSReader;
use Symfony\Component\Console\Tester\CommandTester;

class PackagistToBlueSkyAppTest extends TestCase
{
    public function testExecuteWithNoPackages(): void
    {
        // モックの作成
        $rssReader = $this->createMock(PackagistRSSReader::class);
        $blueSkyClient = $this->createMock(BlueSkyClient::class);
        $formatter = $this->createMock(PackagistFormatter::class);
        
        // モックの設定
        $rssReader->method('fetchPackages')->willReturn([]);
        $blueSkyClient->method('authenticate')->willReturn(true);
        $blueSkyClient->method('getLatestPostDate')->willReturn(null);
        
        // 環境変数の設定
        $_ENV['BLUESKY_USERNAME'] = 'test.bsky.social';
        $_ENV['BLUESKY_PASSWORD'] = 'password123';
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter);
        
        // コマンドテスターの作成
        $commandTester = new CommandTester($app);
        
        // コマンドの実行
        $exitCode = $commandTester->execute([
            '--dry-run' => true,
        ]);
        
        // 検証
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('新着パッケージはありません', $commandTester->getDisplay());
    }
    
    public function testExecuteWithPackages(): void
    {
        // モックの作成
        $rssReader = $this->createMock(PackagistRSSReader::class);
        $blueSkyClient = $this->createMock(BlueSkyClient::class);
        $formatter = $this->createMock(PackagistFormatter::class);
        
        // パッケージデータの作成
        $packages = [
            [
                'title' => 'example/package (1.0.0)',
                'link' => 'https://packagist.org/packages/example/package',
                'description' => 'Example package description',
                'pubDate' => new \DateTime('2025-03-13 05:00:00'),
                'timestamp' => (new \DateTime('2025-03-13 05:00:00'))->getTimestamp(),
            ],
        ];
        
        // フォーマット済みパッケージの作成
        $formattedPackages = [
            [
                'text' => '📦 example/package 1.0.0

Example package description

🔗 https://packagist.org/packages/example/package',
                'links' => [
                    'https://packagist.org/packages/example/package' => 'https://packagist.org/packages/example/package',
                ],
            ],
        ];
        
        // モックの設定
        $rssReader->method('fetchPackages')->willReturn($packages);
        $rssReader->method('filterPackagesSince')->willReturn($packages);
        $blueSkyClient->method('authenticate')->willReturn(true);
        $blueSkyClient->method('getLatestPostDate')->willReturn(new \DateTime('2025-03-12 00:00:00'));
        $formatter->method('formatPackages')->willReturn($formattedPackages);
        $blueSkyClient->method('createPost')->willReturn('at://did:plc:mock123456789/app.bsky.feed.post/mock-post-id');
        
        // 環境変数の設定
        $_ENV['BLUESKY_USERNAME'] = 'test.bsky.social';
        $_ENV['BLUESKY_PASSWORD'] = 'password123';
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter);
        
        // コマンドテスターの作成
        $commandTester = new CommandTester($app);
        
        // コマンドの実行（ドライラン）
        $exitCode = $commandTester->execute([
            '--dry-run' => true,
        ]);
        
        // 検証
        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('ドライラン: 実際の投稿は行いません', $commandTester->getDisplay());
        $this->assertStringContainsString('📦 example/package 1.0.0', $commandTester->getDisplay());
        
        // 実際の投稿テスト
        $commandTester->execute([]);
        
        $this->assertStringContainsString('投稿しました', $commandTester->getDisplay());
    }
    
    public function testExecuteWithAuthenticationError(): void
    {
        // モックの作成
        $rssReader = $this->createMock(PackagistRSSReader::class);
        $blueSkyClient = $this->createMock(BlueSkyClient::class);
        $formatter = $this->createMock(PackagistFormatter::class);
        
        // モックの設定
        $blueSkyClient->method('authenticate')->willThrowException(new \RuntimeException('認証エラー'));
        
        // 環境変数の設定
        $_ENV['BLUESKY_USERNAME'] = 'test.bsky.social';
        $_ENV['BLUESKY_PASSWORD'] = 'password123';
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter);
        
        // コマンドテスターの作成
        $commandTester = new CommandTester($app);
        
        // コマンドの実行
        $exitCode = $commandTester->execute([]);
        
        // 検証
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('エラー: 認証エラー', $commandTester->getDisplay());
    }
}
