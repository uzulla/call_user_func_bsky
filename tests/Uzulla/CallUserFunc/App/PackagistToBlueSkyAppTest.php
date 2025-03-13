<?php

declare(strict_types=1);

namespace Tests\Uzulla\CallUserFunc\App;

use PHPUnit\Framework\TestCase;
use Uzulla\CallUserFunc\App\PackagistToBlueSkyApp;
use Uzulla\CallUserFunc\BlueSky\BlueSkyClient;
use Uzulla\CallUserFunc\Command\PostPackagesCommand;
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
        
        // 環境変数の設定
        $_ENV['BLUESKY_USERNAME'] = 'test.bsky.social';
        $_ENV['BLUESKY_PASSWORD'] = 'password123';
        $_ENV['GITHUB_TOKEN'] = 'ghp_testtoken';
        $_ENV['GITHUB_REPOSITORY'] = 'test-owner/test-repo';
        // テスト用のタイムスタンプを設定
        $_ENV['LAST_ITEM_PUBDATE'] = (new \DateTime('2025-03-12 00:00:00'))->getTimestamp();
        
        // GitHubClientのモック作成
        $githubClient = $this->createMock(\Uzulla\CallUserFunc\GitHub\GitHubClient::class);
        $githubClient->method('getLastPackagePubDate')->willReturn(null);
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter, $githubClient);

        // コマンドテスターの作成
        $command = new PostPackagesCommand();
        $command->setApp($app);
        $commandTester = new CommandTester($command);

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
        $formatter->method('formatPackages')->willReturn($formattedPackages);
        $blueSkyClient->method('createPost')->willReturn('at://did:plc:mock123456789/app.bsky.feed.post/mock-post-id');
        
        // 環境変数の設定
        $_ENV['BLUESKY_USERNAME'] = 'test.bsky.social';
        $_ENV['BLUESKY_PASSWORD'] = 'password123';
        $_ENV['GITHUB_TOKEN'] = 'ghp_testtoken';
        $_ENV['GITHUB_REPOSITORY'] = 'test-owner/test-repo';
        // テスト用のタイムスタンプを設定
        $_ENV['LAST_ITEM_PUBDATE'] = (new \DateTime('2025-03-12 00:00:00'))->getTimestamp();
        
        // GitHubClientのモック作成
        $githubClient = $this->createMock(\Uzulla\CallUserFunc\GitHub\GitHubClient::class);
        $githubClient->method('getLastPackagePubDate')->willReturn(new \DateTime('2025-03-12 00:00:00'));
        $githubClient->method('setLastPackagePubDate')->willReturn(true);
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter, $githubClient);

        // コマンドテスターの作成
        $command = new PostPackagesCommand();
        $command->setApp($app);
        $commandTester = new CommandTester($command);

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
        $_ENV['GITHUB_TOKEN'] = 'ghp_testtoken';
        $_ENV['GITHUB_REPOSITORY'] = 'test-owner/test-repo';
        $_ENV['GITHUB_TOKEN'] = 'ghp_testtoken';
        $_ENV['GITHUB_REPOSITORY_OWNER'] = 'test-owner';
        $_ENV['GITHUB_REPOSITORY_NAME'] = 'test-repo';
        
        // GitHubClientのモック作成
        $githubClient = $this->createMock(\Uzulla\CallUserFunc\GitHub\GitHubClient::class);
        
        // アプリケーションの作成
        $app = new PackagistToBlueSkyApp($rssReader, $blueSkyClient, $formatter, $githubClient);

        // コマンドテスターの作成
        $command = new PostPackagesCommand();
        $command->setApp($app);
        $commandTester = new CommandTester($command);

        // コマンドの実行
        $exitCode = $commandTester->execute([]);
        
        // 検証
        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('エラー: 認証エラー', $commandTester->getDisplay());
    }
}
